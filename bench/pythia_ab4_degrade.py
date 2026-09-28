#!/usr/bin/env python3
"""Test 2c: balancer A/B with a DEGRADING backend.

Stand:
  :8101 fast healthy origin (64 workers x 8 ms)
  :8103 degrading origin (8 workers; phase script loops
         healthy -> saturate(cap 2) -> healthy -> spike(+400 ms)
         -> healthy -> drop(50% RST))

Question: does the predictive balancer (cost EMA + forecast) route around
a backend whose *effective* capacity collapses — where leastconn's
in-flight counter lies (requests already parked in the origin queue are
still counted "in flight", so leastconn keeps feeding the sick backend)?

Arms, identical load each:
  flip knob -> settle -> h3load 256 conns x 60 s against /pyab4/foo
  -> rps/p50/p99/err + ground-truth served split per origin.

Usage: python bench/pythia_ab4_degrade.py <h3load> <port> <admin_port> <out.json> [conns] [dur]
"""
import json
import re
import subprocess
import sys
import time
import urllib.request

FAST_PORT = 8101
DEG_PORT = 8103


def _session_token(admin_port: int) -> str:
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
        method="POST")
    with urllib.request.urlopen(req, timeout=5) as r:
        assert r.status == 200, f"flip failed: {r.status}"
    print(f"  knob -> {balancer}")


def counters() -> tuple[int, int]:
    def one(port: int) -> int:
        try:
            with urllib.request.urlopen(f"http://127.0.0.1:{port}/count",
                                        timeout=4) as r:
                return json.load(r)["count"]
        except Exception as e:
            print(f"  ! counter :{port}: {e}")
            return -1
    return one(FAST_PORT), one(DEG_PORT)


def phase() -> str:
    try:
        with urllib.request.urlopen(f"http://127.0.0.1:{DEG_PORT}/phase",
                                    timeout=3) as r:
            return json.load(r)["phase"]
    except Exception:
        return "?"


def parse_h3load(log: str) -> dict:
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
    f0, d0 = counters()
    flip(admin_port, balancer)
    time.sleep(3)  # settle: drain in-flight from the previous arm
    proc = subprocess.run(
        [h3load, "h1plain", "127.0.0.1", str(port), "/pyab4/foo",
         str(conns), str(dur_s)],
        capture_output=True, text=True, timeout=dur_s + 60)
    time.sleep(2)
    f1, d1 = counters()
    out = parse_h3load(proc.stdout + proc.stderr)
    out.update(balancer=balancer, conns=conns, duration_s=dur_s,
               fast_served=(f1 - f0) if f0 >= 0 and f1 >= 0 else None,
               degrading_served=(d1 - d0) if d0 >= 0 and d1 >= 0 else None)
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
    print("\n== summary ==")
    print(f"rps         leastconn={res['leastconn']['rps']}  "
          f"predictive={res['predictive']['rps']}")
    print(f"p99_ms      leastconn={res['leastconn']['p99_ms']}  "
          f"predictive={res['predictive']['p99_ms']}")
    print(f"err         leastconn={res['leastconn']['err']}  "
          f"predictive={res['predictive']['err']}")
    print(f"degrading_served  leastconn={res['leastconn']['degrading_served']}  "
          f"predictive={res['predictive']['degrading_served']}")
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(res, f, indent=1)


if __name__ == "__main__":
    main()
