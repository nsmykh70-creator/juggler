#!/usr/bin/env python3
"""DDoS phase 1+2: HTTP flood storm on the bench matrix.

Phase 1: every server gets wrk -t4 -c10000 -d30s (a flood of hundreds of
thousands of requests each); we sample RSS during the storm (docker stats
polls in a thread) and capture wrk's full stats (requests, latency, errors).
Phase 2: juggler endurance — same storm but -d until ~1M requests, then a
recovery probe (is the server still answering instantly?).

Usage: python bench/attack_flood.py
Output: bench/results_ddos.json + progress lines to stdout.
"""
import json
import re
import subprocess
import sys
import threading
import time

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

TARGETS = [
    ("juggler", "http://juggler:8080/bench", "bench-juggler"),
    ("nginx", "http://nginx:8081/bench", "bench-nginx"),
    ("apache", "http://apache:8082/bench", "bench-apache"),
    ("caddy", "http://caddy:8083/bench", "bench-caddy"),
    ("haproxy", "http://haproxy:8084/bench", "bench-haproxy"),
    ("envoy", "http://envoy:8085/bench", "bench-envoy"),
    ("traefik", "http://traefik:8086/bench", "bench-traefik"),
    ("openlitespeed", "http://openlitespeed:8087/bench", "bench-ols"),
]

DURATION = "30s"
CONC = 10000
THREADS = 4


def wrk(target: str, dur: str = DURATION, conc: int = CONC) -> str:
    cmd = ["docker", "exec", "bench-wrk", "sh", "-c",
           f"wrk -t{THREADS} -c{conc} -d{dur} --timeout 30s {target}"]
    return subprocess.run(cmd, capture_output=True, text=True, timeout=600).stdout


def parse(out: str) -> dict:
    d = {}
    m = re.search(r"Requests/sec:\s+([0-9.]+)", out)
    d["rps"] = float(m.group(1)) if m else 0.0
    m = re.search(r"(\d+)\s+requests in", out)
    d["requests"] = int(m.group(1)) if m else 0
    m = re.search(r"Latency\s+([0-9.]+)ms\s+([0-9.]+)ms\s+([0-9.]+)([ms]*)\s+", out)
    if m:
        val, unit = float(m.group(3)), m.group(4)
        d["lat_max_ms"] = val * 1000 if unit == "s" else val
    m = re.search(r"Socket errors: connect (\d+), read (\d+), write (\d+), timeout (\d+)", out)
    if m:
        d["sock_errors"] = {"connect": int(m.group(1)), "read": int(m.group(2)),
                            "write": int(m.group(3)), "timeout": int(m.group(4))}
    else:
        d["sock_errors"] = {"connect": 0, "read": 0, "write": 0, "timeout": 0}
    m = re.search(r"Non-2xx or 3xx responses: (\d+)", out)
    d["non2xx"] = int(m.group(1)) if m else 0
    d["total_errors"] = d["non2xx"] + sum(d["sock_errors"].values())
    return d


class MemSampler:
    """Polls docker stats for a container while the storm runs."""

    def __init__(self, container: str):
        self.container = container
        self.samples = []
        self.cpu = []
        self.stop = threading.Event()
        self.thread = threading.Thread(target=self._loop, daemon=True)

    def _loop(self):
        while not self.stop.is_set():
            try:
                out = subprocess.run(
                    ["docker", "stats", "--no-stream", "--format",
                     "{{.CPUPerc}} {{.MemUsage}}", self.container],
                    capture_output=True, text=True, timeout=15).stdout.strip()
                cpu, mem = out.split(" ", 1)
                mib = float(re.search(r"([0-9.]+)", mem).group(1))
                self.cpu.append(float(cpu.rstrip("%")))
                self.samples.append(mib)
            except Exception:
                pass
            self.stop.wait(2.0)

    def __enter__(self):
        self.thread.start()
        return self

    def __exit__(self, *a):
        self.stop.set()
        self.thread.join(timeout=5)

    def summary(self) -> dict:
        return {
            "mem_peak_mib": max(self.samples) if self.samples else None,
            "mem_last_mib": self.samples[-1] if self.samples else None,
            "cpu_peak_pct": max(self.cpu) if self.cpu else None,
        }


def probe_alive(target: str) -> dict:
    t0 = time.time()
    try:
        out = subprocess.run(
            ["docker", "exec", "bench-wrk", "sh", "-c",
             f"wget -qO- -T5 {target.replace('/bench', '/bench')} | head -c 20"],
            capture_output=True, text=True, timeout=10).stdout
        return {"alive": bool(out.strip()), "first_byte_ms": round((time.time() - t0) * 1000)}
    except Exception:
        return {"alive": False, "first_byte_ms": None}


def main() -> None:
    results = {"meta": {
        "date": time.strftime("%Y-%m-%d %H:%M"),
        "method": f"HTTP flood: wrk -t{THREADS} -c{CONC} -d{DURATION} --timeout 30s per server, "
                  "always-200 upstream, docker-net, RSS sampled every 2s during the storm",
    }, "flood": {}}

    for name, target, container in TARGETS:
        print(f"--- flood {name} ...", flush=True)
        with MemSampler(container) as sampler:
            out = wrk(target)
        stats = parse(out)
        stats.update(sampler.summary())
        stats["recovery"] = probe_alive(target)
        results["flood"][name] = stats
        print(f"    rps={stats['rps']:.0f} req={stats['requests']} "
              f"errors={stats['total_errors']} mem_peak={stats['mem_peak_mib']} "
              f"recovery={stats['recovery']}", flush=True)

    print("--- endurance: juggler ~1M requests (c=10000, up to 90s)", flush=True)
    est = max(60, int(1_000_000 / max(results["flood"]["juggler"]["rps"], 1)))
    est = min(est, 90)
    out = wrk("http://juggler:8080/bench", dur=f"{est}s")
    stats = parse(out)
    stats["recovery"] = probe_alive("http://juggler:8080/bench")
    results["endurance_juggler"] = stats
    print(f"    rps={stats['rps']:.0f} req={stats['requests']} errors={stats['total_errors']} "
          f"recovery={stats['recovery']}", flush=True)

    with open("bench/results_ddos.json", "w", encoding="utf-8") as f:
        json.dump(results, f, indent=1)
    print("written bench/results_ddos.json", flush=True)


if __name__ == "__main__":
    main()
