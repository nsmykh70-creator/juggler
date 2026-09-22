#!/usr/bin/env python3
"""Decisive one-variable test of the pool-mutex convoy hypothesis at c=10k.

If the global UpstreamPool Mutex is the tail bottleneck, then shrinking the
pool cap (4096 -> 512) must shrink the convoy: warm p99 should improve
sharply (fewer pooled pops/pushes contending under lock) while RPS holds.

The ONLY variable is `pool_cap_per_upstream` (admin settings, no rebuild):
  arm "pool4096": pool_cap_per_upstream = 4096
  arm "pool512":  pool_cap_per_upstream = 512
Prewarm target pinned 256, connect permits default, nginx control interleaved.

Usage: python bench/run_poolsize.py [--quick]
"""
import json
import os
import sys
import time

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import run_ab as ab  # noqa: E402

ARMS = [("pool4096", 4096), ("pool0", 0)]  # pool0 disables pooling entirely (fresh path only)
ROUNDS = int(os.environ.get("PS_ROUNDS", "6"))
QUICK = "--quick" in sys.argv
if QUICK:
    ROUNDS = 1
# Resume support: rounds 1-2 crashed out of the log in a previous run;
# --resume3 starts the schedule at round 3 (index 2).
START = 0


def run_one(arm: str, rnd: int) -> dict:
    cap = dict(ARMS)[arm]
    ab.log(f"\n=== poolsize round {rnd + 1}/{ROUNDS} arm {arm} ===")
    ab.start_juggler(cap)  # not ROUND2: env leaks popped; default prewarm 256
    ab.apply_route()
    ab.post_settings({**ab.PINNED})
    ab.post_settings({"pool_cap_per_upstream": cap})
    got = ab.get_settings()
    assert got["pool_cap_per_upstream"] == cap, got
    ab.log(f"  pool cap={cap} (settings), prewarm target pinned 256")

    rows = []
    for phase in ("cold", "warm"):
        if phase == "warm":
            time.sleep(12)
            ab.log(f"  pool after soak: {ab.pool_size()} sockets")
        run = ab.wrk("juggler:8080", 10000, wrk_timeout="30s")
        row = {
            "arm": arm, "round": rnd + 1, "phase": phase, "concurrency": 10000,
            "rps": run["rps"],
            "latency_avg_ms": run["latency_avg_ms"],
            "percentiles_ms": run["percentiles_ms"],
            "non_2xx": run["non_2xx"],
            "socket_errors": run["socket_errors"],
            "errors": run["errors"],
            "ts": time.strftime("%H:%M:%S"),
        }
        rows.append(row)
        se = run["socket_errors"]
        ab.log(f"  [{arm} {phase} c=10k] rps={run['rps']:>8.0f} "
               f"p99={row['percentiles_ms'].get('99', 0):>7.0f}ms "
               f"non2xx={run['non_2xx']} sock={sum(se.values())}")
    return {"arm": arm, "rounds": rows}


def main() -> None:
    ab.preflight()
    out = {"meta": {
        "design": "pool-mutex convoy test wave 2: pool_cap 4096 vs 0 (pooling "
                  "off entirely), single variable via settings API, prewarm 256",
        "wrk_timeout": "30s", "duration": ab.DURATION,
        "resume_from_round": START + 1,
    }, "juggler": []}
    schedule = []
    for r in range(START, ROUNDS):
        for arm, _ in ARMS:
            schedule.append((r, arm))
            if arm == "pool512":
                schedule.append((r, "nginx"))
    for rnd, arm in schedule:
        if arm == "nginx":
            ab.sh(["docker", "start", "bench-nginx"], timeout=60)
            time.sleep(1)
            run = ab.wrk("nginx:8081", 10000, wrk_timeout="30s")
            se = run["socket_errors"]
            ab.log(f"  [nginx c=10k] rps={run['rps']:>8.0f} "
                   f"p99={run['percentiles_ms'].get('99', 0):>7.0f}ms sock={sum(se.values())}")
            out["juggler"].append({"arm": "nginx", "round": rnd + 1, "phase": "control",
                                   "concurrency": 10000, "rps": run["rps"],
                                   "percentiles_ms": run["percentiles_ms"],
                                   "errors": run["errors"], "ts": time.strftime("%H:%M:%S")})
            ab.sh(["docker", "stop", "bench-nginx"], timeout=60)
        else:
            out["juggler"].append(run_one(arm, rnd))
        with open("bench/results_poolsize.json", "w", encoding="utf-8") as f:
            json.dump(out, f, indent=2)
    ab.log("\n📄 written bench/results_poolsize.json")


if __name__ == "__main__":
    main()
