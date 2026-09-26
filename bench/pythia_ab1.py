#!/usr/bin/env python3
"""A/B driver for Test 1: accept mode (handoff vs reuseport).

For each mode run:
  - steady phase: 3 rounds x (h1plain, 32 conns, 15 s) -> rps/p50/p99
  - churn phase: 10 waves x (h1plain, 128 conns, 6 s); during each wave
    sample shard_active_conns from the admin /api/metrics and record the
    max-min shard imbalance.

Usage: python bench/pythia_ab1.py <mode> <port> <admin_port> <out.json>
"""
import http.client
import json
import os
import re
import statistics
import subprocess
import sys
import time

H3LOAD = os.path.abspath("target/release/h3load.exe")
PORT = None  # set in main()


def metrics(admin_port):
    for attempt in range(3):
        try:
            conn = http.client.HTTPConnection("127.0.0.1", admin_port, timeout=4)
            conn.request("GET", "/api/metrics")
            text = conn.getresponse().read().decode()
            conn.close()
            return [int(m) for m in re.findall(r'shard_active_conns\{shard="\d+"\} (\d+)', text)]
        except OSError:
            if attempt == 2:
                return []
            time.sleep(0.2)


def h3load(mode, conns, seconds):
    out = subprocess.run(
        [H3LOAD, mode, "127.0.0.1", str(PORT), "/", str(conns), str(seconds)],
        capture_output=True, text=True, timeout=seconds + 30,
    ).stdout
    d = json.loads(out.strip().splitlines()[-1])
    return {"rps": d["rps"], "p50": d["p50_ms"], "p99": d["p99_ms"], "err": d["err"]}


def churn_wave(admin_port, results):
    """Run one churn wave while sampling shard imbalance."""
    proc = subprocess.Popen(
        [H3LOAD, "h1plain", "127.0.0.1", str(PORT), "/", "128", "6"],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    samples = []
    while proc.poll() is None:
        m = metrics(admin_port)
        if m:
            samples.append(max(m) - min(m))
        time.sleep(0.15)
    proc.wait()
    results.append(samples)


def main():
    global PORT
    mode, PORT, admin_port, out_path = sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), sys.argv[4]

    steady = [h3load(mode, 32, 15) for _ in range(3)]
    waves = []
    for _ in range(10):
        churn_wave(admin_port, waves)
        time.sleep(1)

    flat = [s for w in waves for s in w]
    result = {
        "mode": mode,
        "steady_rps": statistics.mean(r["rps"] for r in steady),
        "steady_p50": statistics.mean(r["p50"] for r in steady),
        "steady_p99": statistics.mean(r["p99"] for r in steady),
        "steady_err": sum(r["err"] for r in steady),
        "imbalance_mean": statistics.mean(flat),
        "imbalance_max": max(flat),
        "waves": len(waves),
    }
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(result, f, indent=2)
    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    main()
