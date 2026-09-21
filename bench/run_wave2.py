#!/usr/bin/env python3
"""Wave 2 of the c=10k tail diagnostics: two separate one-variable questions.

Baseline (wave 1, results_poolsize.json): pool4096/soak12 warm = 10-11 s p99.

  arm "pool4"          pool_cap=4  (knob min), soak 12s
     -> pooling effectively OFF: if the warm tail vanishes, the global
        UpstreamPool mutex is the mechanism; if unchanged, the convoy
        hypothesis is dead (wave 1 already weakened it).

  arm "pool4096soak90" pool_cap=4096, soak 90s
     -> lingering-client-conn test: the cold run leaves ~10k keep-alive
        client sockets inside juggler's 60 s idle grace; a 12 s gap means
        the warm burst fights them for accept. If a 90 s gap restores the
        cold profile, the accept path (not the upstream pool) is the tail.

Each arm answers against the existing baseline; nginx control interleaved.

Usage: python bench/run_poolsize.py --wave2
"""
import json
import os
import sys
import time

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import run_ab as ab  # noqa: E402

# (arm name, pool_cap, warm soak seconds)
ARMS = [("pool4", 4, 12), ("pool4096soak90", 4096, 90)]
ROUNDS = int(os.environ.get("PS_ROUNDS", "4"))


def run_one(arm: str, cap: int, soak: int, rnd: int) -> dict:
    ab.log(f"\n=== wave2 round {rnd + 1}/{ROUNDS} arm {arm} (cap={cap}, soak={soak}s) ===")
    ab.start_juggler(cap)
    ab.apply_route()
    ab.post_settings({**ab.PINNED})
    ab.post_settings({"pool_cap_per_upstream": cap})
    got = ab.get_settings()
    assert got["pool_cap_per_upstream"] == cap, got
    rows = []
    for phase in ("cold", "warm"):
        if phase == "warm":
            time.sleep(soak)
            ab.log(f"  pool after {soak}s soak: {ab.pool_size()} sockets")
        run = ab.wrk("juggler:8080", 10000, wrk_timeout="30s")
        row = {
            "arm": arm, "round": rnd + 1, "phase": phase, "concurrency": 10000,
            "rps": run["rps"], "latency_avg_ms": run["latency_avg_ms"],
            "percentiles_ms": run["percentiles_ms"],
            "non_2xx": run["non_2xx"], "socket_errors": run["socket_errors"],
            "errors": run["errors"], "ts": time.strftime("%H:%M:%S"),
        }
        rows.append(row)
        se = run["socket_errors"]
        ab.log(f"  [{arm} {phase} c=10k] rps={run['rps']:>8.0f} "
               f"p99={row['percentiles_ms'].get('99', 0):>7.0f}ms "
               f"non2xx={run['non_2xx']} sock={sum(se.values())}")
    return {"arm": arm, "pool_cap": cap, "soak_s": soak, "rounds": rows}


def main() -> None:
    ab.preflight()
    out = {"meta": {
        "design": "wave 2: pool4 (pooling off) vs pool4096+90s soak, "
                  "c=10k, wrk timeout 30s; baseline = wave 1 pool4096/soak12",
        "duration": ab.DURATION,
    }, "juggler": []}
    schedule = []
    for r in range(ROUNDS):
        for arm, cap, soak in ARMS:
            schedule.append((r, arm, cap, soak))
            if arm == "pool4":
                schedule.append((r, "nginx", None, 12))
    for rnd, arm, cap, soak in schedule:
        if arm == "nginx":
            ab.sh(["docker", "start", "bench-nginx"], timeout=60)
            time.sleep(1)
            run = ab.wrk("nginx:8081", 10000, wrk_timeout="30s")
            ab.log(f"  [nginx c=10k] rps={run['rps']:>8.0f} "
                   f"p99={run['percentiles_ms'].get('99', 0):>7.0f}ms")
            out["juggler"].append({"arm": "nginx", "round": rnd + 1, "phase": "control",
                                   "concurrency": 10000, "rps": run["rps"],
                                   "percentiles_ms": run["percentiles_ms"],
                                   "errors": run["errors"], "ts": time.strftime("%H:%M:%S")})
            ab.sh(["docker", "stop", "bench-nginx"], timeout=60)
        else:
            out["juggler"].append(run_one(arm, cap, soak, rnd))
        with open("bench/results_poolsize2.json", "w", encoding="utf-8") as f:
            json.dump(out, f, indent=2)
    ab.log("\n📄 written bench/results_poolsize2.json")


if __name__ == "__main__":
    main()
