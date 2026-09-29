#!/usr/bin/env python3
"""Juggler vs nginx vs Apache scale ladder: 100/1k/10k/100k/1M requests.

Same generator (bench/scaleload.exe), same 1 KB file, same machine.
Two passes per (scale, server); per-scale artifacts in bench/scale_results/.
Order is rotated per pass to spread warm-up bias.
"""
import json
import os
import subprocess
import time

EXE = r"C:\newwebser\target\scaleload.exe"
OUT = r"C:\newwebser\bench\scale_results"
SERVERS = [("juggler", 8180), ("nginx", 8181), ("apache", 8182)]
SCALES = [100, 1_000, 10_000, 100_000, 1_000_000]
PASSES = 2

def run(server, port, conns, total):
    r = subprocess.run([EXE, "127.0.0.1", str(port), "/sample_sites1/bench1k.html",
                        str(conns), str(total)], capture_output=True, text=True, timeout=1500)
    d = json.loads(r.stdout.strip())
    d["server"], d["total"], d["conns"] = server, total, conns
    return d

def main():
    os.makedirs(OUT, exist_ok=True)
    jsonl = os.path.join(OUT, "scale_ladder.jsonl")
    done = set()
    if os.path.exists(jsonl):  # resume: skip (scale, server, pass) triples already measured
        for line in open(jsonl):
            d = json.loads(line)
            done.add((d["total"], d["server"], d.get("pass", 0)))
        print(f"resuming: {len(done)} runs already on disk")
    out_f = open(jsonl, "a")
    results = [json.loads(l) for l in open(jsonl)] if done else []
    # conns scale with the request count; 8 is enough to saturate loopback
    # keep-alive but stays fair for Apache's process pool.
    for scale in SCALES:
        conns = 8 if scale <= 10_000 else 32
        for p in range(PASSES):
            for name, port in SERVERS[p:] + SERVERS[:p]:  # rotate order
                if (scale, name, p + 1) in done:
                    continue
                d = run(name, port, conns, scale)
                d["pass"] = p + 1
                results.append(d)
                out_f.write(json.dumps(d) + "\n")
                out_f.flush()
                print(f"scale={scale:>9,} pass={p+1} {name:8s} "
                      f"ok={d['ok']:>9,} err={d['err']:>4} rps={d['rps']:>9,.1f} "
                      f"p50={d['p50_ms']:>7.2f} p99={d['p99_ms']:>8.2f}", flush=True)
        # inter-scale cool-down so background load from the previous scale decays
        time.sleep(5)
    path = os.path.join(OUT, "scale_ladder.json")
    json.dump(results, open(path, "w"), indent=1)
    print("saved:", path)

if __name__ == "__main__":
    main()
