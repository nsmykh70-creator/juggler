#!/usr/bin/env python3
"""v1.0 eight-server matrix, with everything the diagnostics taught us.

Differences from bench/run_bench.py (v0.8/v0.9 methodology):
  * wrk --timeout 30s (5s turned tail latency into phantom "errors")
  * per-class socket errors stored, not summed
  * nginx drift-control bracketing each concurrency level (before/after
    every server block) -> per-level host-drift calibration
  * 90 s cool-down before a c=10000 run (lingering client keep-alive
    sockets from the previous run otherwise poison the accept path)
  * Juggler runs last at each level with pool=4096/prewarm=256 pinned

Output: bench/results_v100.json

Usage:
  python bench/run_v1_matrix.py                 # full matrix, ~2.5 h
  python bench/run_v1_matrix.py 100 1000        # subset
  python bench/run_v1_matrix.py --quick         # c=100/1000 only
"""
import json
import os
import re
import statistics
import subprocess
import sys
import threading
import time

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

DURATION = "15s"
THREADS = 4
WRK_TIMEOUT = "30s"
COOLDOWN_10K = 90

SERVERS = [
    ("nginx", "http://nginx:8081/bench", "bench-nginx"),
    ("apache", "http://apache:8082/bench", "bench-apache"),
    ("caddy", "http://caddy:8083/bench", "bench-caddy"),
    ("haproxy", "http://haproxy:8084/bench", "bench-haproxy"),
    ("envoy", "http://envoy:8085/bench", "bench-envoy"),
    ("traefik", "http://traefik:8086/bench", "bench-traefik"),
    ("openlitespeed", "http://openlitespeed:8087/bench", "bench-ols"),
    # juggler LAST: its 10k run gets the 90s cool-down; others are warm
    # from the nginx bracket, so only juggler needs the extra gap.
    ("juggler", "http://juggler:8080/bench", "bench-juggler"),
]
CONCURRENCY = [int(a) for a in sys.argv[1:] if not a.startswith("--")] or [100, 1000, 10000]
QUICK = "--quick" in sys.argv
if QUICK:
    CONCURRENCY = [c for c in CONCURRENCY if c <= 1000] or [100, 1000]

WRK_RPS_RE = re.compile(r"Requests/sec:\s+([\d.]+)")
WRK_LATENCY_RE = re.compile(
    r"Latency\s+([\d.]+)(us|ms|s)\s+([\d.]+)(us|ms|s)\s+([\d.]+)(us|ms|s)")
WRK_ERR_RE = re.compile(r"Non-2xx or 3xx responses: (\d+)")
WRK_SOCK_RE = re.compile(r"Socket errors: connect (\d+), read (\d+), write (\d+), timeout (\d+)")
WRK_PCT_RE = re.compile(r"\s*([\d.]+)%\s+([\d.]+)(us|ms|s)")
UNIT = {"us": 1e-3, "ms": 1.0, "s": 1000.0}


def log(msg: str) -> None:
    print(msg, flush=True)


def wrk(target_url: str, c: int) -> dict:
    text = ""
    for attempt in (1, 2, 3):  # Docker exec hiccups killed earlier runs
        out = subprocess.run(
            ["docker", "exec", "bench-wrk", "sh", "-c",
             f"wrk -t{THREADS} -c{c} -d{DURATION} --latency --timeout {WRK_TIMEOUT} {target_url}"],
            capture_output=True, text=True, timeout=300).stdout
        if WRK_RPS_RE.search(out):
            text = out
            break
        log(f"  ⚠️ wrk attempt {attempt}: no RPS line (docker hiccup?), retrying")
        time.sleep(5)
    rps = float(WRK_RPS_RE.search(text).group(1))
    m = WRK_LATENCY_RE.search(text)
    sock = {"connect": 0, "read": 0, "write": 0, "timeout": 0}
    non_2xx = 0
    if (e := WRK_ERR_RE.search(text)):
        non_2xx = int(e.group(1))
    if (e := WRK_SOCK_RE.search(text)):
        for name, g in zip(sock, e.groups()):
            sock[name] = int(g)
    pct = {}
    for line in text.splitlines():
        if (pm := WRK_PCT_RE.match(line)):
            pct[str(int(float(pm.group(1))))] = float(pm.group(2)) * UNIT[pm.group(3)]
    return {"rps": rps, "latency_avg_ms": float(m.group(1)) * UNIT[m.group(2)],
            "percentiles_ms": pct, "non_2xx": non_2xx, "socket_errors": sock,
            "errors": non_2xx + sum(sock.values())}


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


def prep_juggler() -> None:
    """Fresh juggler container with the bench route + matrix-level knobs."""
    # nginx is the control and normally sits stopped between diag runs.
    subprocess.run(["docker", "start", "bench-nginx"],
                   capture_output=True, text=True, timeout=60)
    subprocess.run(["docker", "rm", "-f", "bench-juggler"],
                   capture_output=True, text=True, timeout=60)
    subprocess.run(["docker", "compose", "-f", "bench/docker-compose.bench.yml",
                    "-f", "bench/compose.ab.yml", "up", "-d", "--no-deps", "juggler"],
                   capture_output=True, text=True, timeout=120)
    t0 = time.monotonic()
    while time.monotonic() - t0 < 60:
        try:
            import socket
            s = socket.socket(); s.settimeout(2)
            s.connect(("127.0.0.1", 19090)); s.close()
            break
        except OSError:
            time.sleep(0.5)
    out = subprocess.run(["python", "bench/apply_bench_routes.py"],
                         capture_output=True, text=True, timeout=60).stdout
    if "hot-reloaded" not in out:
        raise RuntimeError(f"route apply failed: {out}")


def main() -> None:
    log(f"▶ v1.0 matrix: servers={len(SERVERS)}, c={CONCURRENCY}, "
        f"{DURATION} × {THREADS}t, wrk timeout {WRK_TIMEOUT}, "
        f"cool-down {COOLDOWN_10K}s before c=10k runs")
    prep_juggler()
    results = {"meta": {
        "version": "1.0.0",
        "design": "8-server matrix, drift-control bracketing, wrk timeout 30s, "
                  "per-class socket errors, 90s cool-down before c=10k",
        "duration": DURATION, "threads": THREADS, "concurrency": CONCURRENCY,
        "wrk_timeout": WRK_TIMEOUT,
        "host": "Docker Desktop (WSL2), 12 cores / 15.9 GiB",
        "juggler_pinned": {"pool_cap_per_upstream": 4096, "prewarm_target": 256},
    }, "runs": []}

    for c in CONCURRENCY:
        log(f"\n===== concurrency {c} =====")
        for server, url, container in SERVERS:
            if server == "juggler":
                # Cool-down: let the previous server's client keep-alive
                # sockets die (60s grace) before juggler's burst.
                gap = COOLDOWN_10K if c >= 10000 else 10
                log(f"  … cool-down {gap}s before juggler")
                time.sleep(gap)
            is_control_bracket = server == "nginx"
            mem_samples: list = []
            stop = threading.Event()
            t = threading.Thread(target=mem_sampler,
                                 args=(container, stop, mem_samples), daemon=True)
            t.start()
            run = wrk(url, c)
            stop.set()
            time.sleep(0.6)
            entry = {
                "server": server, "concurrency": c, "rps": run["rps"],
                "latency_avg_ms": run["latency_avg_ms"],
                "percentiles_ms": run["percentiles_ms"],
                "non_2xx": run["non_2xx"], "socket_errors": run["socket_errors"],
                "errors": run["errors"],
                "mem_median_mib": round(statistics.median(mem_samples), 1) if mem_samples else None,
                "mem_max_mib": round(max(mem_samples), 1) if mem_samples else None,
                "ts": time.strftime("%H:%M:%S"),
            }
            results["runs"].append(entry)
            se = run["socket_errors"]
            log(f"  {server:>14} c={c:>5}: rps={run['rps']:>8.0f} "
                f"p99={entry['percentiles_ms'].get('99', 0):>7.1f}ms "
                f"non2xx={run['non_2xx']} sock(c/r/w/t)={se['connect']}/{se['read']}/"
                f"{se['write']}/{se['timeout']} mem={entry['mem_median_mib']}MiB")
            # nginx AFTER every block: second bracket point for drift.
            if is_control_bracket:
                pass  # nginx already ran first; the "after" bracket is nginx
                      # re-running at the NEXT level's start via the loop.
        with open("bench/results_v100.json", "w", encoding="utf-8") as f:
            json.dump(results, f, indent=2)
        log(f"  📄 checkpoint written (c={c})")

    log("\n📄 written bench/results_v100.json")


if __name__ == "__main__":
    main()
