#!/usr/bin/env python3
"""Single-variable A/B: does the upstream-pool cap explain the v0.9 regression?

One juggler:bench image, one fresh container per round. Between arms ONLY
`pool_cap_per_upstream` changes (admin POST /api/settings): 64 vs 256. The
startup prewarm target is the compiled-in constant (256) in both arms and the
adaptive target is clamped by the same knob — so the measured delta is the
clamp alone. nginx runs interleaved as a drift control for the shared host
(Docker Desktop / WSL2), same session, same wrk invocation.

Arms (round-robin blocks; each juggler round = fresh container):
  cold: container start -> route apply (fires async prewarm) -> first burst
  warm: 12 s quiet soak + prune cycle, pool size logged, then the same burst

Requirements checked at startup: no host juggler.exe may be listening on
127.0.0.1:50051 (it silently hijacks the Control Plane connection aimed at
the container — this burned the first harness attempt).

Usage:
  python bench/run_ab.py                # full design (10 rounds/arm, ~2 h)
  python bench/run_ab.py --quick        # 3 rounds/arm, c=1000 only (smoke)
"""
import json
import os
import re
import socket
import statistics
import subprocess
import sys
import threading
import time
import urllib.request

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

# ---------------- design ----------------
ARMS = [("cap64", 64), ("cap256", 256)]
ROUNDS_PER_ARM = int(os.environ.get("AB_ROUNDS", "10"))
CONCURRENCY = [100, 1000, 10000]
DURATION = "15s"
THREADS = 4

QUICK = "--quick" in sys.argv
if QUICK:
    ROUNDS_PER_ARM = int(os.environ.get("AB_ROUNDS", "3"))
    CONCURRENCY = [1000]
    DURATION = "10s"

# Round 2: pool cap is NOT the arm variable any more (both arms keep the
# matrix-level 4096 pool); the prewarm TARGET (64 vs 256) is injected per arm
# via compose interpolation. This isolates the warm-pool size at 10k inflight,
# which round 1 showed is a different bottleneck than the pool cap.
ROUND2 = "--round2" in sys.argv
if ROUND2:
    CONCURRENCY = [100, 1000, 10000]
    DURATION = os.environ.get("AB_DURATION", "15s")

# ---------------- juggler runtime (identical across arms) ----------------
ADMIN = "http://127.0.0.1:19090"
ADMIN_HEADERS = {"Authorization": "Bearer bench"}  # compose.ab.yml sets the token
# Pinned for BOTH arms so the only variable is the pool cap:
PINNED = {
    "balancer": 0,               # leastconn: Pythia scoring must not confound
    "pool_idle_timeout_secs": 300,
    "prewarm_floor": 32,
}

if ROUND2:
    # Arms now differ ONLY in JUGGLER_PREWARM_TARGET_CAP (compose env);
    # the pool cap stays at the matrix level in both arms.
    ARMS = [("prewarm64", 64), ("prewarm256", 256)]

WRK_RPS_RE = re.compile(r"Requests/sec:\s+([\d.]+)")
WRK_LATENCY_RE = re.compile(
    r"Latency\s+([\d.]+)(us|ms|s)\s+([\d.]+)(us|ms|s)\s+([\d.]+)(us|ms|s)")
WRK_ERR_RE = re.compile(r"Non-2xx or 3xx responses: (\d+)")
WRK_SOCK_RE = re.compile(r"Socket errors: connect (\d+), read (\d+), write (\d+), timeout (\d+)")
WRK_PCT_RE = re.compile(r"\s*([\d.]+)%\s+([\d.]+)(us|ms|s)")
UNIT = {"us": 1e-3, "ms": 1.0, "s": 1000.0}


def log(msg: str) -> None:
    print(msg, flush=True)


def sh(cmd: list, timeout: int = 120) -> str:
    return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout).stdout


def preflight() -> None:
    """A host juggler.exe on 0.0.0.0:50051 hijacks grpc_client.py connections
    meant for the bench container (0 hot-reloads land in the container)."""
    out = sh(["netstat", "-ano"], timeout=60)
    pids = {ln.rsplit(None, 1)[1] for ln in out.splitlines()
            if ":50051" in ln and "LISTENING" in ln}
    for pid in pids:
        name = sh(["tasklist", "//FI", f"PID eq {pid}", "//FO", "CSV", "//NH"],
                  timeout=30)
        if "juggler.exe" in name.lower():
            raise RuntimeError(
                f"host juggler.exe (pid {pid}) is listening on :50051 — kill it "
                f"first (taskkill //F //PID {pid}); it would swallow the "
                f"Control Plane traffic aimed at the bench container")
    log("✅ preflight: no host juggler.exe on :50051")


def wrk(target: str, c: int) -> dict:
    url = f"http://{target}/bench"
    text = sh(["docker", "exec", "bench-wrk", "sh", "-c",
               f"wrk -t{THREADS} -c{c} -d{DURATION} --latency --timeout 5s {url}"],
              timeout=300)
    rps = float(WRK_RPS_RE.search(text).group(1))
    m = WRK_LATENCY_RE.search(text)
    errs = 0
    if (e := WRK_ERR_RE.search(text)):
        errs += int(e.group(1))
    if (e := WRK_SOCK_RE.search(text)):
        errs += sum(int(g) for g in e.groups())
    pct = {}
    for line in text.splitlines():
        if (pm := WRK_PCT_RE.match(line)):
            pct[str(int(float(pm.group(1))))] = float(pm.group(2)) * UNIT[pm.group(3)]
    return {"rps": rps, "latency_avg_ms": float(m.group(1)) * UNIT[m.group(2)],
            "percentiles_ms": pct, "errors": errs}


def mem_sampler(container: str, stop: threading.Event, samples: list) -> None:
    while not stop.is_set():
        out = subprocess.run(
            ["docker", "stats", "--no-stream", "--format", "{{.MemUsage}}", container],
            capture_output=True, text=True, timeout=30).stdout.strip()
        if out:
            used = out.split("/")[0].strip()
            m = re.match(r"([\d.]+)(MiB|GiB|KiB)", used)
            if m:
                v = float(m.group(1))
                samples.append(v * 1024 if m.group(2) == "GiB"
                               else v / 1024 if m.group(2) == "KiB" else v)
        time.sleep(0.5)


# ---------------- juggler lifecycle ----------------
def admin_alive() -> bool:
    s = socket.socket()
    s.settimeout(2)
    try:
        s.connect(("127.0.0.1", 19090))
        return True
    except OSError:
        return False
    finally:
        s.close()


def start_juggler(cap: int) -> None:
    env = dict(os.environ)
    if ROUND2:
        env["AB_PREWARM_CAP"] = str(cap)
    sh(["docker", "rm", "-f", "bench-juggler"], timeout=60)
    subprocess.run(["docker", "compose", "-f", "bench/docker-compose.bench.yml",
                    "-f", "bench/compose.ab.yml", "up", "-d", "--no-deps", "juggler"],
                   capture_output=True, text=True, timeout=120, env=env)
    t0 = time.monotonic()
    while time.monotonic() - t0 < 60:
        if admin_alive():
            time.sleep(1)  # let shards finish binding
            return
        time.sleep(0.5)
    raise RuntimeError("juggler admin API did not come up in 60 s")


def get_settings() -> dict:
    """GET /api/settings -> {name: value} from the {settings:[...]} wrapper."""
    req = urllib.request.Request(f"{ADMIN}/api/settings", headers=ADMIN_HEADERS)
    with urllib.request.urlopen(req, timeout=10) as r:
        data = json.load(r)
    return {e["name"]: e["value"] for e in data["settings"]}


def post_settings(payload: dict) -> None:
    req = urllib.request.Request(
        f"{ADMIN}/api/settings",
        data=json.dumps(payload).encode(),
        headers={"Content-Type": "application/json", **ADMIN_HEADERS},
        method="POST")
    with urllib.request.urlopen(req, timeout=10) as r:
        body = r.read().decode()
    if '"success":true' not in body:
        raise RuntimeError(f"settings POST failed: {body}")


def pool_size() -> int:
    """Total pooled keep-alive upstream sockets, from the in-network /metrics."""
    out = sh(["docker", "exec", "bench-wrk", "sh", "-c",
              "wget -qO- http://juggler:8080/metrics 2>/dev/null"])
    return sum(int(m.group(1))
               for m in re.finditer(r"upstream_pool_size\{[^}]*\}\s+(\d+)", out)
               if "init" not in m.group(0))


def apply_route() -> None:
    out = sh(["python", "bench/apply_bench_routes.py"], timeout=60)
    # ASCII marker only: emoji comparison is unreliable across Windows
    # console encodings (cp1251 vs UTF-8 subprocess decoding).
    if "hot-reloaded" not in out:
        raise RuntimeError(f"route apply failed: {out}")


# ---------------- schedule ----------------
def main() -> None:
    preflight()
    results = {
        "meta": {
            "design": "single-variable A/B: pool_cap_per_upstream 64 vs 256, "
                      "one v0.9 binary, fresh container per round",
            "prewarm_target": "256 (compiled const; identical in both arms)",
            "pinned": PINNED,
            "rounds_per_arm": ROUNDS_PER_ARM,
            "concurrency": CONCURRENCY,
            "duration": DURATION,
            "threads": THREADS,
            "host": "Docker Desktop (WSL2), 12 cores / 15.9 GiB",
            "load_tool": "wrk 4.2.0",
        },
        "juggler": [],
        "nginx_control": [],
    }

    blocks = [(r, arm, cap) for r in range(ROUNDS_PER_ARM) for arm, cap in ARMS]
    schedule = []
    for i, b in enumerate(blocks):
        schedule.append(b)
        if i % 2 == 1:
            schedule.append((i // 2, "nginx", None))

    log(f"▶ A/B design: {ROUNDS_PER_ARM} rounds/arm, arms={ARMS}, c={CONCURRENCY}, "
        f"{DURATION} per run, nginx control interleaved")
    for rnd, arm, cap in schedule:
        is_control = arm == "nginx"
        if is_control:
            sh(["docker", "start", "bench-nginx"], timeout=60)
            time.sleep(1)
            target = "nginx:8081"
            container = "bench-nginx"
        else:
            log(f"\n=== round {rnd + 1} arm {arm} (cap={cap}) ===")
            start_juggler(cap)
            apply_route()
            post_settings({**PINNED})
            if ROUND2:
                # The arm variable lives in the container env (prewarm target);
                # pool cap is pinned at the matrix level for both arms.
                post_settings({"pool_cap_per_upstream": 4096})
                # Verify the arm's prewarm target through the visible soak pool.
            else:
                post_settings({"pool_cap_per_upstream": cap})
                got = get_settings()
                assert got["pool_cap_per_upstream"] == cap, got
            log(f"  cap pinned to {cap}; pinned={PINNED}")
            target = "juggler:8080"
            container = "bench-juggler"

        for phase in ("cold", "warm"):
            if not is_control and phase == "warm":
                time.sleep(12)  # quiet soak: adaptive prewarm + prune settle
                log(f"  pool after soak: {pool_size()} sockets")
            for c in CONCURRENCY:
                samples: list = []
                stop = threading.Event()
                t = threading.Thread(target=mem_sampler,
                                     args=(container, stop, samples), daemon=True)
                t.start()
                run = wrk(target, c)
                stop.set()
                time.sleep(0.6)
                row = {
                    "arm": arm, "round": rnd + 1, "phase": phase,
                    "concurrency": c, "rps": run["rps"],
                    "latency_avg_ms": run["latency_avg_ms"],
                    "percentiles_ms": run["percentiles_ms"],
                    "errors": run["errors"],
                    "mem_median_mib": round(statistics.median(samples), 1) if samples else None,
                    "mem_max_mib": round(max(samples), 1) if samples else None,
                    "ts": time.strftime("%H:%M:%S"),
                }
                bucket = results["nginx_control" if is_control else "juggler"]
                bucket.append(row)
                log(f"  [{arm} {phase} c={c:>5}] rps={run['rps']:>8.0f} "
                    f"p99={row['percentiles_ms'].get('99', 0):>7.1f}ms "
                    f"errs={run['errors']:>6} mem={row['mem_median_mib']}")

        if is_control:
            sh(["docker", "stop", "bench-nginx"], timeout=60)

    with open("bench/results_ab.json", "w", encoding="utf-8") as f:
        json.dump(results, f, indent=2)
    log("\n📄 written bench/results_ab.json")


if __name__ == "__main__":
    main()
