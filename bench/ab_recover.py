#!/usr/bin/env python3
"""Rebuild bench/results_ab.json from bench/ab_run.log (source of truth).

The full 10-round run crashed on a docker-exec timeout mid round 10, before
writing results_ab.json (which still held a --quick smoke). Every completed
run is printed to the log as one row, so the log is the complete dataset for
rounds 1..9. Rebuilds the JSON from scratch; overwrites any stale content.
"""
import json
import re
import sys

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

ROW_RE = re.compile(
    r"\[(cap64|cap256) (cold|warm) c=\s*(\d+)\]\s+rps=\s*(\d+)\s+"
    r"p99=\s*([\d.]+)ms\s+errs=\s*(\d+)\s+mem=([\d.]+)")
CTL_RE = re.compile(
    r"\[nginx (cold|warm) c=\s*(\d+)\]\s+rps=\s*(\d+)\s+"
    r"p99=\s*([\d.]+)ms\s+errs=\s*(\d+)\s+mem=([\d.]+)")


def main():
    with open("bench/ab_run.log", encoding="utf-8", errors="replace") as f:
        lines = f.readlines()

    juggler, control = [], []
    round_no = 0
    for ln in lines:
        m = re.match(r"=== round (\d+) arm", ln)
        if m:
            round_no = int(m.group(1))
            continue
        m = ROW_RE.search(ln)
        if m:
            juggler.append({
                "arm": m.group(1), "round": round_no, "phase": m.group(2),
                "concurrency": int(m.group(3)), "rps": float(m.group(4)),
                "p99_ms": float(m.group(5)), "errors": int(m.group(6)),
                "mem_median_mib": float(m.group(7)),
                "recovered_from_log": True,
            })
            continue
        m = CTL_RE.search(ln)
        if m:
            control.append({
                "arm": "nginx", "round": round_no, "phase": m.group(1),
                "concurrency": int(m.group(2)), "rps": float(m.group(3)),
                "p99_ms": float(m.group(4)), "errors": int(m.group(5)),
                "mem_median_mib": float(m.group(6)),
                "recovered_from_log": True,
            })

    rounds = max((r["round"] for r in juggler), default=0)
    data = {
        "meta": {
            "design": "single-variable A/B: pool_cap_per_upstream 64 vs 256, "
                      "one v0.9 binary, fresh container per round",
            "prewarm_target": "256 (compiled const; identical in both arms)",
            "pinned": {"balancer": 0, "pool_idle_timeout_secs": 300,
                       "prewarm_floor": 32},
            "rounds_per_arm": rounds,
            "duration": "15s", "threads": 4,
            "concurrency": [100, 1000, 10000],
            "host": "Docker Desktop (WSL2), 12 cores / 15.9 GiB",
            "load_tool": "wrk 4.2.0",
            "recovery_note": ("rebuilt from ab_run.log after a docker-exec "
                              "timeout crash; rows 1..9 complete, round 10 "
                              "partial"),
        },
        "juggler": juggler,
        "nginx_control": control,
    }
    with open("bench/results_ab.json", "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2)
    print(f"rebuilt: juggler={len(juggler)} control={len(control)} "
          f"rounds={rounds}")


if __name__ == "__main__":
    main()
