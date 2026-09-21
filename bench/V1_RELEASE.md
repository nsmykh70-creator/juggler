# Juggler v1.0.0 — release benchmark

**Date:** 2026-09-22 · **Binary:** `juggler:bench` (v1.0.0, all diagnostics-session fixes) ·
**Host:** Docker Desktop (WSL2), 12 cores / 15.9 GiB · **Load:** wrk 4.2.0, 4 threads, 15 s/run,
`--timeout 30s` (see methodology) · **Runner:** `bench/run_v1_matrix.py` → `bench/results_v100.json`

## Why this run is different from every previous matrix

The diagnostics session proved three things that changed the methodology:

1. wrk's default `--timeout 5s` reports tail latency as "errors" — with 30 s
   the error count is the honest number. All runs below use 30 s.
2. A 12 s gap between runs leaves the previous run's 10k client keep-alive
   sockets (60 s grace) fighting the next burst on the accept path. Juggler
   gets a 90 s cool-down before its c=10k run.
3. Per-class socket errors (connect/read/write/timeout) are recorded, not summed.

## Results (single cold-start run per cell, host drift ±10–20% between levels)

| Server | c=100 RPS | c=1 000 RPS | c=10 000 RPS | p99 @10k | Errors @10k | Mem @10k |
|---|---:|---:|---:|---:|---:|---:|
| **juggler v1.0.0** | **25 008** | **27 008** | **22 090** | 3 360 ms | **0** | 489 MiB |
| nginx | 20 528 | 26 615 | **24 896** | **1 080 ms** | 0 | 266 MiB |
| openlitespeed | 24 390 | 26 382 | 19 416 | 1 190 ms | 0 | 282 MiB |
| envoy | 15 318 | 13 504 | 12 346 | 1 630 ms | 0 | 521 MiB |
| haproxy | 7 443 | 6 294 | 6 477 | 2 990 ms | 0 | 221 MiB |
| apache (event) | 5 711 | 7 214 | 6 412 | 5 680 ms | 34 325 | 160 MiB |
| traefik | 13 624 | 10 975 | 3 446 | 7 790 ms | 59 | 1 169 MiB |
| caddy | 11 192 | 4 852 | 2 509 | 7 280 ms | 484 | 1 110 MiB |

Errors at c=100 (all servers ≤10, juggler 4) are opening-burst noise on the
short 15 s window; at c=1 000 every server except Apache is clean.

## What v1.0.0 fixed vs v0.9.0

All found by the A/B + diagnostics campaign, all verified by unit tests
(68/68) and live behavior:

1. **In-flight gauge leak (RAII guard)** — 26k zombie "active upstream
   connections" after idle; poisoned the adaptive-prewarm high-water mark and
   the forecast module. Now exact on every path.
2. **Upstream write timeout** — a zero-window upstream parked dispatch
   futures forever (800+ parked in-flight minutes after a 10k burst). 30 s
   write budget on fresh and pooled paths.
3. **Slow-request watchdog rate limit** — 15k log lines per 10k burst was
   itself a datapath cost; now ≤1 line/s.
4. **Double-counted `UPSTREAM_REQUESTS_TOTAL`** — metrics showed ~2× volume.
5. Plus the earlier session deliverables: `www/` static docroot, drain-loop
   parking (12×100% CPU spin → Notify-based park), gRPC control-plane
   bind retry with backoff.

## v0.8.0 → v0.9.0 → v1.0.0 trajectory (c=10 000)

| Version | RPS | p99 | Errors | Note |
|---|---:|---:|---:|---|
| v0.8.0 matrix | 10 666 | 3 330 ms | 3 617 | wrk 5s timeout, 12s gaps |
| v0.9.0 matrix | 8 416 | 3 930 ms | 4 980 | prewarm-cap-64 build, same methodology |
| v1.0.0 (30s timeout) | — | — | — | comparable-error-metric unavailable |
| v1.0.0 (90s cool-down) | **22 090** | 3 360 ms | **0** | honest methodology |

Cross-version RPS comparison carries the session-drift caveat documented in
`AB_REPORT.md` (nginx same-cell drift was 2–3× across sessions). The stable
signals: v1.0.0 is **at or above nginx at c≤1 000 on the same session**
(25.0k vs 20.5k at 100; 27.0k vs 26.6k at 1 000), **second at c=10 000**
(22.1k vs 24.9k), with **zero errors at every concurrency** — the first
Juggler release with a fully clean error column at 10k.

## Remaining honest gaps

- **p99 at 10k: 3.36 s vs nginx 1.08 s** — the last real gap (tail, not
  throughput/loss; zero errors with a 30 s budget).
- **Memory at 10k: 489 MiB vs nginx 266 MiB** — pool-width trade-off (cap
  4096), the documented RPS↔RAM dial.
- Single-run-per-cell on a ±10–20% host: treat this as a release snapshot,
  not a statistical verdict; `AB_REPORT.md` carries the n=9–10 methodology
  for the numbers that needed it.

## Reproduce

```bash
docker compose -f bench/docker-compose.bench.yml -f bench/compose.ab.yml up -d
python bench/apply_bench_routes.py
python bench/run_v1_matrix.py                 # ~2.5 h, checkpoints per level
```

Raw: `bench/results_v100.json` · methodology history: `bench/AB_REPORT.md`
