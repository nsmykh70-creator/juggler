#!/usr/bin/env python3
"""c=10k diagnostics: where do the ~1.4% warm errors and the 3 s p99 come from?

For each run it captures:
  * wrk per-class socket errors (connect / read / write / timeout) + non-2xx
  * juggler /metrics snapshots before and after the run (pool size, reuses,
    stale retries, failover, upstream ok/error counters, active in-flight)
  * the active in-flight gauge sampled every second during the run
Arms mirror round 2: the ONLY variable is the prewarm target (64 vs 256,
container env), pool cap pinned at 4096. nginx is the drift control.

Usage:
  python bench/run_diag.py            # 6 rounds/arm, ~40 min
  python bench/run_diag.py --quick    # 1 round/arm, c=10k only (smoke)
"""
import json
import os
import re
import statistics
import sys
import threading
import time

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
# Reuse the A/B harness plumbing (docker/admin/wrk helpers, regexes).
import run_ab as ab  # noqa: E402

_orig_start_juggler = ab.start_juggler


def start_juggler_diag(cap: int) -> None:
    """Wrap the harness: run_diag arms put their variable in AB_CONNECT_PERMITS
    (connect round) or AB_PREWARM_CAP (prewarm round) before container start."""
    env_extra = {"AB_CONNECT_PERMITS": str(cap)} if CONNECT_ROUND else {}
    old = os.environ.copy()
    os.environ.update(env_extra)
    try:
        _orig_start_juggler(cap)
    finally:
        os.environ.clear()
        os.environ.update(old)

ARMS = [("prewarm64", 64), ("prewarm256", 256)]
ROUNDS = int(os.environ.get("DIAG_ROUNDS", "6"))
QUICK = "--quick" in sys.argv
if QUICK:
    ROUNDS = 1

# Connect-permits round: the ONLY variable is the fresh-connect semaphore
# (256 default vs 2048) via AB_CONNECT_PERMITS compose interpolation; the
# prewarm target stays pinned in both arms.
CONNECT_ROUND = "--connect-round" in sys.argv
if CONNECT_ROUND:
    ARMS = [("perm256", 256), ("perm2048", 2048)]

# Counter/gauge names harvested from /metrics between snapshots.
METRIC_NAMES = [
    "upstream_pool_size",
    "upstream_pool_reuses_total",
    "upstream_stale_retries_total",
    "upstream_failover_total",
    "active_upstream_connections",
    "connections_total",
]


def fetch_metrics() -> dict:
    out = ab.sh(["docker", "exec", "bench-wrk", "sh", "-c",
                 "wget -qO- http://juggler:8080/metrics 2>/dev/null"])
    snap: dict = {}
    for name in METRIC_NAMES:
        if name == "upstream_pool_size":
            snap[name] = sum(int(m.group(1)) for m in
                             re.finditer(rf"{name}\{{[^}}]*\}}\s+(\d+)", out)
                             if "init" not in m.group(0))
        else:
            m = re.search(rf"^{name}\s+(\d+)", out, re.M)
            snap[name] = int(m.group(1)) if m else 0
    # Per-upstream request outcome counters (label family).
    ok = err = 0
    for m in re.finditer(r'upstream_requests_total\{[^}]*\}\s+(\d+)', out):
        pass  # labels carry the outcome; count via ok/err below
    for m in re.finditer(r'upstream_requests_total\{[^}]*outcome="ok"[^}]*\}\s+(\d+)', out):
        ok += int(m.group(1))
    for m in re.finditer(r'upstream_requests_total\{[^}]*outcome="error"[^}]*\}\s+(\d+)', out):
        err += int(m.group(1))
    snap["upstream_ok_total"] = ok
    snap["upstream_error_total"] = err
    return snap


def log_sampler(stop: threading.Event, samples: list) -> None:
    """Sample active in-flight gauge during the run (from inside the net)."""
    while not stop.is_set():
        out = ab.sh(["docker", "exec", "bench-wrk", "sh", "-c",
                     "wget -qO- http://juggler:8080/metrics 2>/dev/null"], timeout=15)
        m = re.search(r"^active_upstream_connections\s+(\d+)", out, re.M)
        if m:
            samples.append(int(m.group(1)))
        time.sleep(1.0)


def run_one(arm: str, rnd: int) -> dict:
    cap = dict(ARMS)[arm]
    ab.log(f"\n=== diag round {rnd + 1}/{ROUNDS} arm {arm} ===")
    start_juggler_diag(cap)
    ab.apply_route()
    ab.post_settings({**ab.PINNED})
    ab.post_settings({"pool_cap_per_upstream": 4096})
    got = ab.get_settings()
    assert got["pool_cap_per_upstream"] == 4096, got
    if CONNECT_ROUND:
        ab.log(f"  connect permits={cap} (env), prewarm target pinned 256")
    else:
        ab.log(f"  prewarm target={cap} (env), pool cap pinned 4096")

    rows = []
    for phase in ("cold", "warm"):
        if phase == "warm":
            time.sleep(12)  # quiet soak: prewarm + prune settle
            ab.log(f"  pool after soak: {ab.pool_size()} sockets")
        before = fetch_metrics()
        samples: list = []
        stop = threading.Event()
        t = threading.Thread(target=log_sampler, args=(stop, samples), daemon=True)
        t.start()
        mem_samples: list = []
        stop_m = threading.Event()
        tm = threading.Thread(target=ab.mem_sampler,
                              args=("bench-juggler", stop_m, mem_samples), daemon=True)
        tm.start()
        run = ab.wrk("juggler:8080", 10000, wrk_timeout="30s")
        stop.set()
        stop_m.set()
        time.sleep(0.6)
        after = fetch_metrics()
        pool_during_max = max(samples) if samples else None
        row = {
            "arm": arm, "round": rnd + 1, "phase": phase, "concurrency": 10000,
            "rps": run["rps"],
            "latency_avg_ms": run["latency_avg_ms"],
            "percentiles_ms": run["percentiles_ms"],
            "non_2xx": run["non_2xx"],
            "socket_errors": run["socket_errors"],
            "errors": run["errors"],
            "mem_median_mib": round(statistics.median(mem_samples), 1) if mem_samples else None,
            "mem_max_mib": round(max(mem_samples), 1) if mem_samples else None,
            "active_inflight_during_max": pool_during_max,
            "active_inflight_after": after["active_upstream_connections"],
            "metrics_before": before,
            "metrics_after": after,
            "ts": time.strftime("%H:%M:%S"),
        }
        rows.append(row)
        se = run["socket_errors"]
        ab.log(f"  [{arm} {phase} c=10k] rps={run['rps']:>8.0f} "
               f"p99={row['percentiles_ms'].get('99', 0):>7.1f}ms "
               f"non2xx={run['non_2xx']} sock(c/r/w/t)={se['connect']}/{se['read']}/"
               f"{se['write']}/{se['timeout']} "
               f"inflight_max={pool_during_max} after={after['active_upstream_connections']}")
    return {"arm": arm, "rounds": rows}


def main() -> None:
    ab.preflight()
    out = {"meta": {
        "design": "c=10k diag: prewarm target 64 vs 256 (round-2 style), "
                  "pool cap 4096, per-class socket errors + metrics/log deltas",
        "wrk_timeout": "30s", "duration": ab.DURATION,
        "host": "Docker Desktop (WSL2), 12 cores / 15.9 GiB",
    }, "juggler": []}
    schedule = []
    for r in range(ROUNDS):
        for arm, _ in ARMS:
            schedule.append((r, arm))
            if arm == "prewarm256":
                schedule.append((r, "nginx"))
    for rnd, arm in schedule:
        if arm == "nginx":
            ab.log(f"\n=== diag round {rnd + 1}/{ROUNDS} arm nginx (control) ===")
            ab.sh(["docker", "start", "bench-nginx"], timeout=60)
            time.sleep(1)
            run = ab.wrk("nginx:8081", 10000, wrk_timeout="30s")
            se = run["socket_errors"]
            ab.log(f"  [nginx c=10k] rps={run['rps']:>8.0f} "
                   f"p99={run['percentiles_ms'].get('99', 0):>7.1f}ms "
                   f"non2xx={run['non_2xx']} sock(c/r/w/t)={se['connect']}/{se['read']}/"
                   f"{se['write']}/{se['timeout']}")
            out["juggler"].append({
                "arm": "nginx", "round": rnd + 1, "phase": "control",
                "concurrency": 10000, "rps": run["rps"],
                "percentiles_ms": run["percentiles_ms"],
                "non_2xx": run["non_2xx"], "socket_errors": se,
                "errors": run["errors"], "ts": time.strftime("%H:%M:%S"),
            })
            ab.sh(["docker", "stop", "bench-nginx"], timeout=60)
        else:
            out["juggler"].append(run_one(arm, rnd))
        with open("bench/results_diag.json", "w", encoding="utf-8") as f:
            json.dump(out, f, indent=2)
    ab.log("\n📄 written bench/results_diag.json")


if __name__ == "__main__":
    main()
