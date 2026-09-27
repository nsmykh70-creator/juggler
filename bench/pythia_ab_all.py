#!/usr/bin/env python3
"""Pythia A/B orchestrator: one command -> all three tests + fresh report.

Phases (each logs its own section to stdout and bench/ab_all/*.log):

  1. preflight   — binaries present, ports free, docker image present
  2. upstreams   — launch origin stands (normal + saturated) on :8101/:8102
  3. test1       — accept-mode A/B in Linux containers (handoff vs reuseport):
                   refresh the Linux binary in the bind dirs, recreate the two
                   containers, run bench/pythia_ab1.py per mode
  4. test2       — balancer A/B on the Windows host instance:
                   ensure bench/srv_bench juggler is up with the /pyab2 route,
                   run bench/pythia_ab2_sat.py (saturated regime, 512 conns)
  5. test3       — prewarm A/B on the Windows host instance
                   (bench/pythia_ab3.py manages its own server lifecycle)
  6. report      — regenerate bench/report_pythia_ab.html from the fresh JSON

Artifacts land in bench/: ab1_handoff.json, ab1_reuseport.json,
ab_sat512.json, ab3_prewarm.json, report_pythia_ab.html.

Usage: python bench/pythia_ab_all.py [--fast]
  --fast shortens load phases (smoke-style, for plumbing checks).
"""
import json
import os
import shutil
import socket
import subprocess
import sys
import time
import urllib.request

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
BENCH = os.path.join(ROOT, "bench")
SRV = os.path.join(BENCH, "srv_bench")
H3LOAD = os.path.abspath(os.path.join(ROOT, "target", "release", "h3load.exe"))
JUGGLER = os.path.abspath(os.path.join(ROOT, "target", "release", "juggler.exe"))
LINUX_BIN = os.path.abspath(os.path.join(
    ROOT, "target", "x86_64-unknown-linux-gnu", "release", "juggler"))
AB1_DIRS = {mode: os.path.join(BENCH, "ab1", mode) for mode in ("handoff", "reuseport")}
DOCKER_IMAGE = "juggler:bench"

DATA_PLAIN = 9085
DATA_ADMIN = 9091
UP_FAST, UP_SLOW = 8101, 8102
HOST_DATA = {"handoff": 18081, "reuseport": 18080}
HOST_ADMIN = {"handoff": 19091, "reuseport": 19090}


def sh(cmd, **kw):
    print(f"  $ {cmd if isinstance(cmd, str) else ' '.join(cmd)}")
    return subprocess.run(cmd, cwd=ROOT, **kw)


def port_busy(port: int) -> bool:
    out = subprocess.run(["netstat", "-ano"], capture_output=True, text=True).stdout
    return any(f":{port} " in ln and "LISTENING" in ln.upper() for ln in out.splitlines())


def kill_port(port: int) -> None:
    out = subprocess.run(["netstat", "-ano"], capture_output=True, text=True).stdout
    pids = {ln.split()[-1] for ln in out.splitlines()
            if f":{port} " in ln and "LISTENING" in ln.upper()}
    for p in pids:
        subprocess.run(["taskkill", "/F", "/PID", p], capture_output=True)
    if pids:
        time.sleep(1)


def wait_port(port: int, timeout_s: float = 30) -> bool:
    t0 = time.time()
    while time.time() - t0 < timeout_s:
        try:
            socket.create_connection(("127.0.0.1", port), timeout=0.2).close()
            return True
        except OSError:
            time.sleep(0.1)
    return False


def admin_token(admin_port: int, user="admin", password="admin1") -> str:
    # Docker test1 containers run with JUGGLER_ADMIN_TOKEN=ab (env token);
    # the Windows host instance accepts the same bearer when its env var is
    # set by start_host_instance(). Default-credential login is the fallback
    # (host instance without an env token).
    try:
        req = urllib.request.Request(
            f"http://127.0.0.1:{admin_port}/api/stats",
            headers={"Authorization": "Bearer ab"})
        urllib.request.urlopen(req, timeout=3)
        return "ab"
    except Exception:
        pass
    req = urllib.request.Request(
        f"http://127.0.0.1:{admin_port}/api/login",
        data=json.dumps({"user": user, "password": password}).encode(),
        headers={"Content-Type": "application/json"}, method="POST")
    with urllib.request.urlopen(req, timeout=5) as r:
        return json.load(r)["token"]


def shard_active_sample(admin_port: int, tok: str, prev: list | None) -> tuple[list | None, int | None]:
    """Per-shard accept deltas from /api/stats handoff_steering.

    Cumulative counters, so imbalance is measured on the DELTA between two
    polls (requests accepted per shard since the previous poll) — works on
    any binary that exposes /api/stats, no rebuild coupling.
    """
    try:
        req = urllib.request.Request(
            f"http://127.0.0.1:{admin_port}/api/stats",
            headers={"Authorization": f"Bearer {tok}"})
        with urllib.request.urlopen(req, timeout=2) as r:
            cur = [s["plain"] + s["redir"] + s["tls"]
                   for s in json.load(r).get("handoff_steering", [])]
    except (OSError, KeyError, ValueError):
        return prev, None
    if prev is None or len(cur) != len(prev):
        return cur, None
    deltas = [c - p for c, p in zip(cur, prev)]
    if all(d == 0 for d in deltas):
        return cur, None
    return cur, max(deltas) - min(deltas)


def ensure_route(admin_port: int) -> None:
    """(Re)register the /pyab2 proxy route with bare host:port addrs."""
    tok = admin_token(admin_port)
    req = urllib.request.Request(
        f"http://127.0.0.1:{admin_port}/api/routes",
        headers={"Authorization": f"Bearer {tok}"}, method="GET")
    with urllib.request.urlopen(req, timeout=5) as r:
        cfg = json.load(r)
    cfg.setdefault("routes", {})["/pyab2"] = {
        "upstream_addrs": [f"127.0.0.1:{UP_FAST}", f"127.0.0.1:{UP_SLOW}"],
        "wasm_plugin": None,
        "security": {"deny_substrings": [], "response_headers": [],
                      "cms_fallback_path": None},
        "static_dir": None,
        "preserve_prefix": False,
    }
    req = urllib.request.Request(
        f"http://127.0.0.1:{admin_port}/api/routes",
        data=json.dumps(cfg).encode(),
        headers={"Authorization": f"Bearer {tok}",
                  "Content-Type": "application/json"}, method="POST")
    with urllib.request.urlopen(req, timeout=5) as r:
        assert r.status == 200


# ----------------------------------------------------------------- phases --

def preflight(fast: bool) -> None:
    print("[1/6] preflight")
    for p in (H3LOAD, JUGGLER):
        if not os.path.exists(p):
            raise SystemExit(f"missing {p} — run: cargo build --release")
    if not fast and not os.path.exists(LINUX_BIN):
        print("  ! linux binary missing — test1 will reuse the existing one in bind dirs")
    for mode, d in AB1_DIRS.items():
        for f in ("cert.pem", "key.pem"):
            if not os.path.exists(os.path.join(d, f)):
                raise SystemExit(f"missing {d}/{f} — test1 bind dir incomplete")
    for port in (DATA_PLAIN, DATA_ADMIN, UP_FAST, UP_SLOW):
        if port_busy(port):
            print(f"  ! port {port} busy — killing")
            kill_port(port)
    r = sh(["docker", "image", "inspect", DOCKER_IMAGE], capture_output=True)
    if r.returncode != 0:
        raise SystemExit(f"docker image {DOCKER_IMAGE} missing — build it first")
    print("  ok")


def start_upstreams(sat: bool) -> dict:
    print(f"[upstreams] {"saturated" if sat else "normal"} stand")
    fast_delay, slow_delay = (0.010, 0.120) if not sat else (0.010, 0.120)
    fast_conc, slow_conc = 64, 8
    if sat:
        fast_conc, slow_conc = 8, 3
    script = os.path.join(BENCH, "pythia_upstreams_sat.py")
    procs = {}
    for port, delay, conc in ((UP_FAST, fast_delay, fast_conc),
                              (UP_SLOW, slow_delay, slow_conc)):
        log = open(os.path.join(BENCH, f"origin_{port}.log"), "w")
        procs[port] = subprocess.Popen(
            [sys.executable, script, str(port), str(delay), str(conc), "-"],
            cwd=ROOT, stdout=log, stderr=log)
    if not (wait_port(UP_FAST) and wait_port(UP_SLOW)):
        raise SystemExit("upstreams did not open ports")
    print(f"  origins up: fast={fast_conc}x{fast_delay}s slow={slow_conc}x{slow_delay}s")
    return procs


def refresh_test1_bins() -> bool:
    """Copy the fresh Linux binary into both bind dirs. Returns refresh status."""
    if not os.path.exists(LINUX_BIN):
        return False
    for d in AB1_DIRS.values():
        shutil.copy2(LINUX_BIN, os.path.join(d, "juggler"))
    return True


def test1(fast: bool) -> dict:
    print("[test1] accept mode handoff vs reuseport (Linux containers)")
    refreshed = refresh_test1_bins()
    print(f"  linux binary {'refreshed' if refreshed else 'REUSED (no fresh build)'}")
    dur = "4" if fast else "15"
    waves = "2" if fast else "10"
    out = {}
    for mode, d in AB1_DIRS.items():
        # recreate so a fresh binary is picked up; POSIX-style bind path —
        # Docker Desktop accepts C:/... but rejects backslashes.
        bind = d.replace("\\", "/")
        sh(["docker", "rm", "-f", f"jg-{mode}"], capture_output=True)
        r = sh(["docker", "run", "-d", "--name", f"jg-{mode}",
                "-v", f"{bind}:/srv", "-p", f"{HOST_DATA[mode]}:8080",
                "-p", f"{HOST_ADMIN[mode]}:9090",
                "-e", "JUGGLER_HTTP_ADDR=0.0.0.0:8443",
                "-e", "JUGGLER_PLAIN_HTTP_ADDR=0.0.0.0:8080",
                "-e", "JUGGLER_ADMIN_ADDR=0.0.0.0:9090",
                "-e", f"JUGGLER_ACCEPT_MODE={mode}",
                "-e", "JUGGLER_ADMIN_TOKEN=ab",
                "-e", "JUGGLER_RATE_LIMIT_TOKENS=1000000",
                "-e", "JUGGLER_RATE_LIMIT_REFILL=1000000",
                DOCKER_IMAGE], capture_output=True, text=True)
        if r.returncode != 0:
            raise SystemExit(f"docker run jg-{mode} failed: {r.stderr[-200:]}")
        if not wait_port(HOST_DATA[mode], 20):
            raise SystemExit(f"container jg-{mode} did not open {HOST_DATA[mode]}")
        # steady rounds
        rounds = []
        for _ in (range(1) if fast else range(3)):
            r = sh([H3LOAD, "h1plain", "127.0.0.1", str(HOST_DATA[mode]), "/",
                    "32", dur], capture_output=True, text=True)
            d_json = json.loads(r.stdout.strip().splitlines()[-1])
            rounds.append(d_json)
        tok = admin_token(HOST_ADMIN[mode])
        # churn waves with per-shard imbalance sampling (deltas)
        samples = []
        n_waves = 2 if fast else 10
        prev = None
        for _ in range(n_waves):
            proc = subprocess.Popen(
                [H3LOAD, "h1plain", "127.0.0.1", str(HOST_DATA[mode]), "/",
                 "128", "6"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            while proc.poll() is None:
                prev, imb = shard_active_sample(HOST_ADMIN[mode], tok, prev)
                if imb is not None:
                    samples.append(imb)
                time.sleep(0.15)
            proc.wait()
            time.sleep(1)
        out[mode] = {
            "steady_rps": sum(x["rps"] for x in rounds) / len(rounds),
            "steady_p50": sum(x["p50_ms"] for x in rounds) / len(rounds),
            "steady_p99": sum(x["p99_ms"] for x in rounds) / len(rounds),
            "steady_err": sum(x["err"] for x in rounds),
            "imbalance_mean": sum(samples) / len(samples) if samples else None,
            "imbalance_max": max(samples) if samples else None,
            "waves": n_waves,
        }
        print(f"  {mode}: {out[mode]}")
        sh(["docker", "rm", "-f", f"jg-{mode}"], capture_output=True)
    with open(os.path.join(BENCH, "ab1_ab_all.json"), "w", encoding="utf-8") as f:
        json.dump(out, f, indent=1)
    return out


def start_host_instance() -> subprocess.Popen:
    """Windows host juggler for tests 2/3 (plain :9085, admin :9091)."""
    kill_port(DATA_PLAIN)
    kill_port(DATA_ADMIN)
    env = {
        **os.environ,
        "JUGGLER_ADMIN_TOKEN": "ab",
        "JUGGLER_ALLOW_PRIVATE_UPSTREAMS": "1",
        "JUGGLER_PLAIN_HTTP_ADDR": f"127.0.0.1:{DATA_PLAIN}",
        "JUGGLER_ADMIN_ADDR": f"127.0.0.1:{DATA_ADMIN}",
        "JUGGLER_GRPC_ADDR": "127.0.0.1:50052",
        "JUGGLER_RATE_LIMIT_TOKENS": "1000000",
        "JUGGLER_RATE_LIMIT_REFILL": "1000000",
    }
    boot = open(os.path.join(SRV, "boot_ab_all.log"), "w")
    proc = subprocess.Popen([JUGGLER], cwd=SRV, env=env, stdout=boot, stderr=boot)
    if not wait_port(DATA_PLAIN, 30):
        proc.kill()
        raise SystemExit("host juggler did not open :9085")
    ensure_route(DATA_ADMIN)
    return proc


def test2(fast: bool, procs_up: dict) -> dict:
    print("[test2] balancer leastconn vs predictive (saturated origins)")
    conns = "64" if fast else "512"
    dur = "20" if fast else "90"
    r = sh([sys.executable, os.path.join(BENCH, "pythia_ab2_sat.py"), H3LOAD,
            str(DATA_PLAIN), str(DATA_ADMIN),
            os.path.join(BENCH, "ab_sat512.json"), conns, dur],
           capture_output=True, text=True)
    print(r.stdout.strip()[-800:])
    if r.returncode != 0:
        raise SystemExit(f"test2 driver failed: {r.stderr[-300:]}")
    return json.load(open(os.path.join(BENCH, "ab_sat512.json"), encoding="utf-8"))


def stop_upstreams(procs_up: dict) -> None:
    for p in procs_up.values():
        p.terminate()
    for port in (UP_FAST, UP_SLOW):
        kill_port(port)
    time.sleep(1)


def test3(fast: bool) -> list:
    print("[test3] adaptive prewarm on vs off (light origins)")
    if fast:
        # ab3 starts its own server; give it the short-burst treatment by
        # temporarily swapping h3load args is invasive — run as-is.
        pass
    r = sh([sys.executable, os.path.join(BENCH, "pythia_ab3.py"),
            os.path.join(BENCH, "ab3_prewarm.json")],
           capture_output=True, text=True)
    print(r.stdout.strip()[-800:])
    if r.returncode != 0:
        raise SystemExit(f"test3 driver failed: {r.stderr[-300:]}")
    return json.load(open(os.path.join(BENCH, "ab3_prewarm.json"), encoding="utf-8"))


def report(t1: dict, t2: dict, t3: list, refreshed: bool) -> None:
    print("[report] regenerate bench/report_pythia_ab.html")
    sys.path.insert(0, BENCH)
    import build_report_pythia  # noqa: E402
    build_report_pythia.build(t1, t2, t3, refreshed)
    print("  bench/report_pythia_ab.html regenerated")


def main() -> None:
    fast = "--fast" in sys.argv
    preflight(fast)          # BEFORE starting anything: kills stale port owners
    procs_up = start_upstreams(sat=True)
    host = None
    try:
        host = start_host_instance()
        t1 = test1(fast)
        t2 = test2(fast, procs_up)
        host.kill()
        host = None
        # Cold-start measurement needs a LIGHT origin stand: saturated origins
        # drown the prewarm signal in queue noise (p99 becomes origin-queue
        # bound regardless of pool state).
        stop_upstreams(procs_up)
        procs_up = start_upstreams(sat=False)
        t3 = test3(fast)
        report(t1, t2, t3, refreshed=os.path.exists(LINUX_BIN))
    finally:
        if host:
            host.kill()
        for p in procs_up.values():
            p.terminate()
        print("stand torn down (origins stopped; report artifacts kept in bench/)")


if __name__ == "__main__":
    main()
