#!/usr/bin/env python3
"""Run the Juggler vs nginx benchmark matrix inside the bench docker net.

For each scenario (hello / proxy) and each concurrency level, run wrk for a
fixed duration against both servers, sample both containers' memory during
the run, and collect wrk's latency distribution. Results land in
bench/results.json for bench/report.py to render.

Usage:  python bench/run_bench.py            # full matrix
        python bench/run_bench.py 1000 10000 # only these concurrency levels
"""
import json
import re
import statistics
import subprocess
import sys
import threading
import time

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

DURATION = "15s"
THREADS = 4
SCENARIOS = {
    # scenario: list of (server, url, container). All four do the same job:
    # terminate the request, pick the upstream, forward, stream back.
    "proxy": [
        ("nginx", "http://nginx:8081/bench", "bench-nginx"),
        ("juggler", "http://juggler:8080/bench", "bench-juggler"),
        ("apache", "http://apache:8082/bench", "bench-apache"),
        ("caddy", "http://caddy:8083/bench", "bench-caddy"),
        ("haproxy", "http://haproxy:8084/bench", "bench-haproxy"),
        ("envoy", "http://envoy:8085/bench", "bench-envoy"),
        ("traefik", "http://traefik:8086/bench", "bench-traefik"),
        ("openlitespeed", "http://openlitespeed:8087/bench", "bench-ols"),
    ],
}
CONCURRENCY = [int(a) for a in sys.argv[1:]] or [100, 1000, 10000]

WRK_LATENCY_RE = re.compile(
    r"Latency\s+([\d.]+)(us|ms|s)\s+([\d.]+)(us|ms|s)\s+([\d.]+)(us|ms|s).*?([\d.]+)%"
)
WRK_RPS_RE = re.compile(r"Requests/sec:\s+([\d.]+)")
WRK_ERRORS_RE = re.compile(r"Non-2xx or 3xx responses: (\d+)")
WRK_READ_ERRORS_RE = re.compile(r"Socket errors: connect (\d+), read (\d+), write (\d+), timeout (\d+)")

UNIT = {"us": 1e-3, "ms": 1.0, "s": 1000.0}  # -> milliseconds


def to_ms(value: str, unit: str) -> float:
    return float(value) * UNIT[unit]


def docker_exec(*args: str) -> str:
    return subprocess.run(
        ["docker", "exec", "bench-wrk", "sh", "-c", " ".join(args)],
        capture_output=True, text=True, timeout=180,
    ).stdout


def wrk_run(url: str, connections: int) -> dict:
    cmd = (
        f"wrk -t{THREADS} -c{connections} -d{DURATION} --latency --timeout 5s {url}"
    )
    t0 = time.monotonic()
    out = subprocess.run(
        ["docker", "exec", "bench-wrk", "sh", "-c", cmd],
        capture_output=True, text=True, timeout=180,
    )
    elapsed = time.monotonic() - t0
    text = out.stdout
    rps = float(WRK_RPS_RE.search(text).group(1))
    m = WRK_LATENCY_RE.search(text)
    latency = {
        "avg_ms": to_ms(m.group(1), m.group(2)),
        "p75_stdev_marker": m.group(7),
        # wrk's distribution lines (percentiles) are parsed separately below.
    }
    errors = 0
    if (e := WRK_ERRORS_RE.search(text)):
        errors += int(e.group(1))
    if (e := WRK_READ_ERRORS_RE.search(text)):
        errors += sum(int(g) for g in e.groups())
    percentiles = {}
    for line in text.splitlines():
        pm = re.match(r"\s*([\d.]+)%\s+([\d.]+)(us|ms|s)", line)
        if pm:
            percentiles[str(int(float(pm.group(1))))] = to_ms(pm.group(2), pm.group(3))
    return {
        "rps": rps,
        "latency_avg_ms": latency["avg_ms"],
        "percentiles_ms": percentiles,
        "errors": errors,
        "wall_s": round(elapsed, 1),
        "raw": text,
    }


def sample_memory(container: str, stop: threading.Event, samples: list) -> None:
    """Poll `docker stats --no-stream` for the container's memory usage."""
    while not stop.is_set():
        out = subprocess.run(
            ["docker", "stats", "--no-stream", "--format",
             "{{.MemUsage}}", container],
            capture_output=True, text=True, timeout=30,
        ).stdout.strip()
        # "12.5MiB / 15.9GiB"
        if out:
            used = out.split("/")[0].strip()
            m = re.match(r"([\d.]+)(MiB|GiB|KiB)", used)
            if m:
                value = float(m.group(1))
                if m.group(2) == "GiB":
                    value *= 1024
                elif m.group(2) == "KiB":
                    value /= 1024
                samples.append(value)
        time.sleep(0.5)


def mem_of(container: str) -> dict:
    stop = threading.Event()
    samples: list = []
    t = threading.Thread(target=sample_memory, args=(container, stop, samples), daemon=True)
    t.start()
    return {"stop": stop, "samples": samples}


def main() -> None:
    results = {"meta": {"duration": DURATION, "threads": THREADS,
                        "concurrency": CONCURRENCY,
                        "host": "Docker Desktop (WSL2), 12 cores / 15.9 GiB"},
               "runs": []}
    for scenario, targets in SCENARIOS.items():
        for c in CONCURRENCY:
            for server, url, container in targets:
                print(f"▶ {server:8s} {scenario:6s} c={c} ... ", flush=True)
                mem = mem_of(container)
                run = wrk_run(url, c)
                mem["stop"].set()
                time.sleep(0.6)  # let the sampler deliver the last value
                entry = {
                    "scenario": scenario,
                    "server": server,
                    "concurrency": c,
                    "rps": run["rps"],
                    "latency_avg_ms": run["latency_avg_ms"],
                    "percentiles_ms": run["percentiles_ms"],
                    "errors": run["errors"],
                    "wall_s": run["wall_s"],
                    "mem_samples_mib": mem["samples"],
                    "mem_median_mib": round(statistics.median(mem["samples"]), 1) if mem["samples"] else None,
                    "mem_max_mib": round(max(mem["samples"]), 1) if mem["samples"] else None,
                }
                results["runs"].append(entry)
                print(f"  {run['rps']:>10.0f} rps, avg {run['latency_avg_ms']:.2f} ms, "
                      f"p99 {entry['percentiles_ms'].get('99', float('nan')):.2f} ms, "
                      f"errors {run['errors']}, "
                      f"mem {entry['mem_median_mib']} MiB", flush=True)
    with open("bench/results.json", "w", encoding="utf-8") as f:
        json.dump(results, f, indent=2)
    print("\n📄 written bench/results.json")


if __name__ == "__main__":
    main()
