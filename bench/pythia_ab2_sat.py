#!/usr/bin/env python3
"""Saturated A/B driver for the Pythia balancer (test 2, deep-queue regime).

One arm:
  1. flip the balancer knob via POST /api/settings ({"balancer": "..."})
  2. settle 3 s (drain previous arm's in-flight requests)
  3. run h3load h1plain <port> <N conns> <duration> against /pyab2/foo
     — N conns far above upstream concurrency => deep queues + timeouts
  4. read p50/p99/rps/errors from the h3load log; read the ground-truth
     origin split from the upstream counter files

Usage: python bench/pythia_ab2_sat.py <h3load> <port> <admin_port> <out.json>
"""
import json
import re
import statistics
import subprocess
import sys
import time
import urllib.request

FAST_CNT = "bench/ab_sat_fast.cnt"
SLOW_CNT = "bench/ab_sat_slow.cnt"


def _session_token(admin_port: int) -> str:
    req = urllib.request.Request(
        f"http://127.0.0.1:{admin_port}/api/login",
        data=json.dumps({"user": "admin", "password": "admin1"}).encode(),
        headers={"Content-Type": "application/json"}, method="POST")
    with urllib.request.urlopen(req, timeout=5) as r:
        return json.load(r)["token"]


def flip(admin_port: int, balancer: str) -> None:
    tok = _session_token(admin_port)
    req = urllib.request.Request(
        f"http://127.0.0.1:{admin_port}/api/settings",
        data=json.dumps({"balancer": balancer}).encode(),
        headers={"Content-Type": "application/json",
                 "Authorization": "Bearer " + tok},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=5) as r:
        assert r.status == 200, f"flip failed: {r.status}"
    print(f"  knob -> {balancer}")


def counters() -> tuple[int, int]:
    def one(port: int) -> int:
        try:
            with urllib.request.urlopen(f"http://127.0.0.1:{port}/count",
                                        timeout=4) as r:
                return json.load(r)["count"]
        except Exception as e:  # counter endpoint down
            print(f"  ! counter :{port}: {e}")
            return -1
    return one(8101), one(8102)


def parse_h3load(log: str) -> dict:
    # h3load emits a single-line JSON summary.
    m = re.search(r"\{.*\}", log)
    if m:
        try:
            d = json.loads(m.group(0))
            return {"rps": d.get("rps"), "p50_ms": d.get("p50_ms"),
                    "p99_ms": d.get("p99_ms"), "ok": d.get("ok"),
                    "err": d.get("err")}
        except json.JSONDecodeError:
            pass
    return {"rps": None, "p50_ms": None, "p99_ms": None,
            "ok": None, "err": None}


def run_arm(h3load: str, port: int, admin_port: int, balancer: str,
            conns: int, dur_s: int) -> dict:
    f0, s0 = counters()
    flip(admin_port, balancer)
    time.sleep(3)  # settle: drain in-flight from the previous arm
    proc = subprocess.run(
        [h3load, "h1plain", "127.0.0.1", str(port), "/pyab2/foo",
         str(conns), str(dur_s)],
        capture_output=True, text=True, timeout=dur_s + 60)
    time.sleep(2)  # let the tail of in-flight upstream work land
    f1, s1 = counters()
    out = parse_h3load(proc.stdout + proc.stderr)
    out.update(balancer=balancer, conns=conns, duration_s=dur_s,
               fast_served=(f1 - f0) if f0 >= 0 and f1 >= 0 else None,
               slow_served=(s1 - s0) if s0 >= 0 and s1 >= 0 else None)
    return out


def main():
    h3load, port, admin_port, out_path = (
        sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), sys.argv[4])
    conns = int(sys.argv[5]) if len(sys.argv) > 5 else 256
    dur = int(sys.argv[6]) if len(sys.argv) > 6 else 60
    res = {}
    for arm in ("leastconn", "predictive"):
        print(f"arm {arm}: {conns} conns x {dur}s")
        res[arm] = run_arm(h3load, port, admin_port, arm, conns, dur)
        print("  ", res[arm])
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(res, f, indent=2)
    a, b = res["leastconn"], res["predictive"]
    print("\n== summary ==")
    for k in ("rps", "p50_ms", "p99_ms", "err", "fast_served", "slow_served"):
        print(f"{k:14} leastconn={a.get(k)}  predictive={b.get(k)}")


if __name__ == "__main__":
    main()
