#!/usr/bin/env python3
"""A/B driver for Test 3: adaptive prewarm on/off (cold-start premium).

Procedure per arm:
  1. write settings.json for the arm (adaptive_prewarm true/false),
     routes.json is already persisted with the /pyab2 proxy route
  2. start juggler.exe (bench/srv_bench, ports 9085/9091)
  3. the instant :9085 accepts, run 5 back-to-back bursts
     (h1plain, 64 conns, 3 s each, 0.5 s gap) and record rps/p50/p99

Usage: python bench/pythia_ab3.py <out.json>
"""
import json
import os
import socket
import subprocess
import sys
import time

H = os.path.abspath("target/release/h3load.exe")
BENCH = os.path.abspath("bench/srv_bench")
EXE = os.path.abspath("target/release/juggler.exe")
ENV = {
    **os.environ,
    "JUGGLER_ADMIN_TOKEN": "ab2",
    "JUGGLER_ADMIN_ADDR": "127.0.0.1:9091",
    "JUGGLER_HTTP_ADDR": "127.0.0.1:9443",
    "JUGGLER_PLAIN_HTTP_ADDR": "127.0.0.1:9085",
    "JUGGLER_GRPC_ADDR": "127.0.0.1:50052",
    "JUGGLER_ALLOW_PRIVATE_UPSTREAMS": "1",
    "JUGGLER_RATE_LIMIT_TOKENS": "1000000",
    "JUGGLER_RATE_LIMIT_REFILL": "1000000",
}


def kill_bench():
    out = subprocess.run(["netstat", "-ano"], capture_output=True, text=True).stdout
    pids = {ln.split()[-1] for ln in out.splitlines()
            if ":9085 " in ln and "LISTENING" in ln.upper()}
    for p in pids:
        subprocess.run(["taskkill", "/F", "/PID", p], capture_output=True)
    if pids:
        time.sleep(1)


def run_arm(name, prewarm):
    kill_bench()
    settings = os.path.join(BENCH, "settings.json")
    if os.path.exists(settings):
        os.remove(settings)
    with open(settings, "w", encoding="utf-8") as f:
        json.dump({"adaptive_prewarm": prewarm}, f)

    boot = open(os.path.join(BENCH, "boot.log"), "w")
    proc = subprocess.Popen([EXE], cwd=BENCH, env=ENV, stdout=boot, stderr=boot)
    t0 = time.time()
    while True:
        try:
            s = socket.create_connection(("127.0.0.1", 9085), timeout=0.2)
            s.close()
            break
        except OSError:
            if time.time() - t0 > 30:
                proc.kill()
                raise SystemExit(f"{name}: server did not open port")
            time.sleep(0.02)
    ready = round(time.time() - t0, 3)

    rows = []
    for i in range(5):
        out = subprocess.run(
            [H, "h1plain", "127.0.0.1", "9085", "/pyab2/", "64", "3"],
            capture_output=True, text=True,
        ).stdout
        d = json.loads(out.strip().splitlines()[-1])
        rows.append({"burst": i + 1, "rps": d["rps"], "p50": d["p50_ms"],
                     "p99": d["p99_ms"], "err": d["err"]})
        time.sleep(0.5)
    proc.kill()
    return {"arm": name, "ready_s": ready, "bursts": rows}


def main():
    out = sys.argv[1]
    res = [run_arm("prewarm_on", True), run_arm("prewarm_off", False)]
    with open(out, "w", encoding="utf-8") as f:
        json.dump(res, f, indent=2)
    for arm in res:
        print(f"== {arm['arm']} (port open after {arm['ready_s']}s)")
        for r in arm["bursts"]:
            print("  ", r)


if __name__ == "__main__":
    main()
