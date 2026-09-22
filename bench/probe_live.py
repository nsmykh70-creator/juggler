#!/usr/bin/env python3
"""Load probe: run wrk against live Juggler while polling /api/stats every
0.5 s to capture stage timings (parse/route/respond/upstream) DURING load,
not after (fold swaps counters each second). Also captures handoff steering
distribution and shard acceptance balance. Writes bench/probe_live.json."""
import json
import subprocess
import threading
import time

import urllib.request

BASE = "http://127.0.0.1:9090/api/stats"
DURATION = int(__import__("os").environ.get("PROBE_SECS", "20"))
CONC = int(__import__("os").environ.get("PROBE_CONC", "200"))
THREADS = int(__import__("os").environ.get("PROBE_THREADS", "4"))
WRK_SECS = int(__import__("os").environ.get("PROBE_WRK_SECS", "15"))


def get_stats():
    with urllib.request.urlopen(BASE, timeout=2) as r:
        return json.load(r)


def main():
    samples = []
    stop = threading.Event()

    def poller():
        while not stop.is_set():
            try:
                samples.append((time.time(), get_stats()))
            except Exception:
                pass
            time.sleep(0.5)

    t = threading.Thread(target=poller, daemon=True)
    t.start()
    time.sleep(1.5)  # baseline samples

    # Real load from the bench wrk container (Docker, WSL2 side) at the live
    # server's Windows-host IP. Use concurrency high enough to expose the
    # tail but short enough to iterate: c=200, 15s.
    wrk = subprocess.Popen(
        [
            "docker", "exec", "bench-wrk", "sh", "-c",
            f"wrk -t{THREADS} -c{CONC} -d{WRK_SECS}s --timeout 30s "
            "http://host.docker.internal:8085/api",
        ],
        stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
    )
    wrk_out = wrk.communicate()[0]

    stop.set()
    t.join(timeout=3)

    # Aggregate: max stage counts observed, avg/p99 per stage across samples.
    agg = {}
    steering = None
    shard_acc = []
    for ts, d in samples:
        for i, st in enumerate(d.get("stages", [])):
            a = agg.setdefault(st["stage"], {"max_count": 0, "samples": 0})
            a["samples"] += 1
            a["max_count"] = max(a["max_count"], st["count"])
            for k in ("avg_us", "p99_ms"):
                if st.get(k) is not None:
                    prev = a.get(f"max_{k}", 0) or 0
                    a[f"max_{k}"] = max(prev, st[k])
        hs = d.get("handoff_steering") or {}
        if hs:
            steering = hs
        sh = d.get("shards") or []
        if sh:
            shard_acc = [s.get("accepted", s.get("accepted_total", 0)) for s in sh]

    print("=== wrk ===")
    print(wrk_out)
    print("=== stage timings (max across 0.5s samples during load) ===")
    for name, a in sorted(agg.items()):
        print(
            f"{name:9s} n={a['max_count']:6d} "
            f"avg={a.get('max_avg_us', 0):8.1f}us "
            f"max_ring={a.get('max_p99_ms', 0):7.1f}ms"
        )
    print("=== handoff steering ===")
    print(json.dumps(steering))
    print("=== shard accepted ===")
    print(json.dumps(shard_acc))

    out = {
        "wrk": wrk_out,
        "stages": agg,
        "steering": steering,
        "shard_accepted": shard_acc,
        "samples": len(samples),
    }
    with open("bench/probe_live.json", "w", encoding="utf-8") as f:
        json.dump(out, f, indent=2)
    print(f"saved bench/probe_live.json ({len(samples)} samples)")


if __name__ == "__main__":
    main()
