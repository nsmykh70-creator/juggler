#!/usr/bin/env python3
"""1M-request A/B: leastconn vs predictive on the /pyab3 route.

For each arm: set the balancer knob live via admin API, run h3load until
1M OK requests (256 conns), record rps/p50/p99/err, then read ground-truth
origin counters. Repeat in both orders plus an echo pair to bound drift.
Usage: python bench/ab3_driver.py <plain_port> <admin_port> <admin_token> <out.json>
"""

import json
import pathlib
import subprocess
import sys
import time
import urllib.request

H3LOAD = str((pathlib.Path(__file__).resolve().parent.parent / "target/release/h3load.exe"))
TARGET = 1_000_000


def admin(port, token, path):
    req = urllib.request.Request(
        f"http://127.0.0.1:{port}{path}",
        headers={"Authorization": f"Bearer {token}"},
    )
    with urllib.request.urlopen(req, timeout=5) as r:
        return json.loads(r.read())


def origin_count(port):
    with urllib.request.urlopen(f"http://127.0.0.1:{port}/count", timeout=5) as r:
        return json.loads(r.read())["count"]


def set_balancer(admin_port, token, value):
    body = json.dumps({"balancer": value}).encode()
    req = urllib.request.Request(
        f"http://127.0.0.1:{admin_port}/api/settings",
        data=body, method="POST",
        headers={"Authorization": f"Bearer {token}",
                 "Content-Type": "application/json"},
    )
    with urllib.request.urlopen(req, timeout=5) as r:
        r.read()


def run_arm(name, balancer_value, plain_port, admin_port, token, seconds_cap=1200):
    set_balancer(admin_port, token, balancer_value)
    time.sleep(0.5)
    c1a, c2a = origin_count(8101), origin_count(8102)
    proc = subprocess.run(
        [H3LOAD, "h1plain", "127.0.0.1", str(plain_port), "/pyab3/",
         "256", str(seconds_cap)], capture_output=True, text=True, timeout=seconds_cap + 120)
    line = proc.stdout.strip().splitlines()[-1]
    stats = json.loads(line)
    c1b, c2b = origin_count(8101), origin_count(8102)
    stats["arm"] = name
    stats["origin_fast"] = c1b - c1a
    stats["origin_slow"] = c2b - c2a
    return stats


def main():
    plain_port, admin_port, token, out = sys.argv[1:5]
    results = []
    # leastconn, predictive, echo-pair to bound session drift.
    for name, val in [("leastconn", 0), ("predictive", 1),
                      ("leastconn_echo", 0), ("predictive_echo", 1)]:
        s = run_arm(name, val, plain_port, admin_port, token)
        print(json.dumps(s), flush=True)
        results.append(s)
    with open(out, "w", encoding="utf-8") as f:
        json.dump(results, f, indent=1)
    print("WROTE", out)


if __name__ == "__main__":
    main()

