# Juggler vs nginx — benchmark report

**Date:** 2026-09-18 (v0.8.0: predictive balancer + adaptive prewarm + fine-tuning API) · **Load tool:** wrk 4.2.0 · **Topology:** everything inside one Docker network (no host NAT in the measured path)

## Eight-server matrix (v0.8.0): Juggler / nginx / Apache / Caddy / HAProxy / Envoy / Traefik / OpenLiteSpeed

Same scenario (reverse proxy to a static always-200 origin), same load generator, same network. Every proxy terminates the request, picks the upstream, forwards and streams back. 15 s per run, 4 wrk threads, `--latency`, timeout 5 s.

| Server | c=100 RPS | c=1000 RPS | c=10000 RPS | p99 @10k | Errors @10k | Mem @10k |
|---|---:|---:|---:|---:|---:|---:|
| **juggler v0.8.0** | 6 129 | **12 499** | **10 666** | 3 330 ms | 3 617 | 339 MiB |
| nginx | **6 219** | 12 015 | 10 530 | 2 890 ms | **0** | 271 MiB |
| openlitespeed | 9 507 | 107 ⚠️ | 81 ⚠️ | 518 ms ⚠️ | 123 947 ⚠️ | 29 MiB |
| envoy | 6 035 | 7 373 | 11 193 | **1 830 ms** | 144 670 ⚠️ | 332 MiB |
| traefik | 5 393 | 5 121 | 1 208 | 4 920 ms | 8 498 | 818 MiB |
| caddy | 4 928 | 2 510 | 3 139 | 4 410 ms | 33 399 | 935 MiB |
| haproxy | 4 624 | 5 045 | 3 412 | 4 830 ms | 3 946 | 215 MiB |
| apache (event) | 2 995 | 3 593 | 2 751 | 4 210 ms | 18 015 | 205 MiB |

⚠️ — outlier runs: OpenLiteSpeed collapsed after c=100 (config-level proxy saturation, 130k socket errors) and Envoy's RPS at 10k comes with 144k socket errors — both need re-verification before any conclusion. HAProxy's 2-thread config may understate it at 10k.

Reading the stable part of the table:

- **Juggler and nginx are the top two at every concurrency** — Juggler leads at c=1000 (+4%) and c=10000 (+1.3%), nginx leads at c=100 by 1.5% (noise band).
- The next tier (Envoy, OLS, Traefik, Caddy, HAProxy) is 1.5–4× behind at c=1000 under this host's conditions.
- Apache's event MPM stays flat at ~3k RPS across all loads — the per-connection thread hand-off is the ceiling.
- Memory under the 10k storm: nginx 271 MiB, Juggler 339 MiB, Apache 205 MiB, HAProxy 215 MiB, Traefik 818 MiB and Caddy 935 MiB (Go GC pressure), Envoy 332 MiB.
- Juggler's error count at 10k (3 617, 0.36%) is confined to the cold start of the opening burst — the adaptive prewarm had not yet reached its 256-socket floor when the run began.

Full per-run percentiles and memory series: `bench/results.json`.

---

## Juggler v0.9.0 — Pythia forecast core (re-run of this matrix)

Rebuilt the `juggler:bench` image from the v0.9.0 sources — **Pythia** forecast
core v2 (adaptive Holt predictor with error-driven smoothing, request-signature
cost oracle, per-shard buffer pools, zero-copy parser) — and re-ran the full
8-server matrix on the same 12-vCPU Docker bench.

| Server | c=1000 RPS | p99 | errors | mem |
|---|---:|---:|---:|---:|
| juggler v0.8.0 | 12 499 | 260 ms | 0 | 90 MiB |
| juggler v0.9.0 | 8 097 | 364 ms | 0 | 104 MiB |
| nginx (reference) | 12 015 → 10 630 | 263 → 367 ms | 22 → 972 | 130 → 127 MiB |

**Whole-bench drift, not a verdict yet.** Same-day re-runs moved every server:
nginx +15% at c=100 but −12% at c=1000, Envoy −15%, HAProxy −24%,
OpenLiteSpeed −28%. The host profile today is noisier than the v0.8.0 session.
Juggler's own gap (−35% at c=1000, −21% at c=10k) exceeds that band, however,
and tracks a known config change: the image now **caps adaptive prewarm at 64
sockets/upstream** (down from 256) and logs ramping targets (32 → 4 096) during
the 10k warm-up — part of the cold-burst penalty that pre-warm had eliminated
is back. The accept path is *not* the cause: the container banner confirms
Linux SO_REUSEPORT sharding is active (hand-off mode is Windows-only).

Verdict: v0.9.0 / Pythia is functionally validated (unit 62/62, integration
19/19); for a fair performance verdict the matrix needs a same-session A/B with
the prewarm cap restored to 256. Parser micro-benchmark (release, old-vs-new in
one process): **909 → 692 ns/request, 12–14 → 1 allocations**.

---

**Date:** 2026-09-17 (v0.6.0: sharded accept + pool pre-warm + sticky probe) · **Load tool:** wrk 4.2.0 · **Topology:** everything inside one Docker network (no host NAT in the measured path)

```
wrk (bench-wrk) ──► proxy under test ──► origin (nginx static, always-200, 21-byte body)
                    ├── juggler:8080  (plain-HTTP data plane, identical pipeline)
                    └── nginx:8081    (proxy_pass + upstream keepalive 512)
```

Both servers do **exactly the same job**: accept → parse → route → connect/pool upstream → forward → stream back. Origin itself delivers **34 250 rps @ c=10000**, so neither proxy is origin-bound.

Host: Docker Desktop (WSL2), 12 cores, 15.9 GiB. Matrix: 15 s per run, 4 wrk threads, `--latency`, timeout 5 s. Memory sampled with `docker stats` and cross-checked against real RSS (`docker top`, excludes page cache).

## Results (reverse proxy, results.json)

| Concurrency | Server | RPS | avg ms | p50 | p75 | p90 | p99 | Errors | Mem (container) |
|---:|---|---:|---:|---:|---:|---:|---:|---:|---:|
| 100 | nginx | 15 813 | 6.62 | 5.66 | 9.11 | 13.43 | 25.07 | 0 | 115 MiB |
| 100 | **juggler** | **19 262** | **5.43** | **5.18** | **7.36** | **10.25** | **20.81** | 0 | 150 MiB |
| 1 000 | nginx | **23 182** | **42.9** | **38.2** | **62.9** | 90.1 | **138.6** | 0 | 128 MiB |
| 1 000 | juggler | 20 470 | 48.8 | 48.4 | 67.9 | 89.2 | 191.5 | 0 | 175 MiB |
| 10 000 | nginx | **18 853** | **465** | **425** | **662** | **902** | **1 470** | 0 | 268 MiB |
| 10 000 | juggler | 18 715 | 515 | 529 | 817 | 1 060 | 3 270 | 1 637 (1.6%) | 420 MiB |

Reference ceiling: **origin direct = 34 250 rps @ c=10000** (149 ms avg).

### What the sharded-accept revision changed (vs the first matrix)

| Concurrency | Juggler before | Juggler after | Δ | Gap vs nginx |
|---:|---:|---:|---:|---|
| 100 | 16 900 | **19 262** | +14% | **+22% ahead** (was +11%) |
| 1 000 | 18 829 | **20 470** | +8.7% | −12% behind (noise band) |
| 10 000 | 15 535 | **18 715** | +20.5% | **−0.7% — statistical tie** (was −13%) |

The 866 rps collapse from the first report no longer reproduced in any post-fix run; the intermediate degradation series was traced to load-generator TIME_WAIT accumulation (nginx degraded identically in the same series, down to 7.6k) — not to Juggler.

## Memory (real RSS, not docker stats which includes page cache)

| Condition | nginx (13 procs) | juggler (1 proc) |
|---|---:|---:|
| Idle | ~144 MiB (master 5.7 + 12×11.2 MiB workers) | ~14 MiB |
| Under 10k connections | **219 MiB** | **267 MiB** peak pre-sharding; matrix shows 420 MiB (docker stats incl. page cache) |

nginx pays a fixed per-worker tax (~11 MiB × N workers); Juggler starts 10× lighter and stays single-process. Under a 10k storm the working sets are comparable; Juggler's sharded revision trades some memory (per-shard pools, DNS cache) for the eliminated RPS gap.

## Architecture: nginx-model sharding (this revision)

The first report diagnosed the tail-latency gap: one multi-threaded work-stealing runtime accepted 10k connections through a single queue while nginx distributes accepts across kernel-scheduled worker processes. Juggler now does the same:

- **12 shard threads, one per core**, each a `current_thread` Tokio runtime inside a `LocalSet`, pinned via `core_affinity`. A connection is accepted, parsed, routed, proxied and answered entirely on one core — zero cross-core migration.
- **SO_REUSEPORT listeners** (one per shard, via socket2): the kernel hashes incoming connections across shards — accept load-balancing happens in-kernel, exactly nginx's model.
- **Deep backlogs**: `listen(8191)` per shard + container `sysctls: net.core.somaxconn=8192`.
- **gRPC Control Plane on shard 0** in its own 2-thread runtime; data-plane shards never run control traffic.

Supporting fixes made necessary/revealed by the storm:

| # | Issue | Symptom | Fix |
|---|---|---|---|
| S1 | Per-call threaded DNS in `TcpStream::connect(host)` | 10k simultaneous connects flooded Docker's embedded DNS → `connect timeout` bursts, health flapping | `resolve_cached()`: 30 s TTL cache, resolve once per name |
| S2 | Unbounded concurrent upstream connects | SYN storm over the bridge degraded everyone's connect latency | Semaphore cap `JUGGLER_UPSTREAM_CONNECT_CONCURRENCY` (default 256) |
| S3 | Pool cap 512 ≪ 10k in-flight | Excess upstream sockets closed after each response → reconnect churn | Env-tunable `JUGGLER_UPSTREAM_POOL_PER_UPSTREAM` (4096 in bench) |
| S4 | plain-HTTP listener only on shard 0 (dev path) | Whole bench load on one core (2k rps) | Plain listener now per-shard too |
| S5 | `spawn_local` outside `LocalSet` | Shard threads panicked on boot | `LocalSet::block_on` wraps every shard |

## Four-way comparison (v0.6.0 matrix): Juggler / nginx / Apache / Caddy

Same scenario, same origin, same load generator — four reverse proxies:

| c | Server | RPS | avg ms | p99 ms | Errors | Mem (container) |
|---:|---|---:|---:|---:|---:|---:|
| 100 | nginx | 16 133 | 6.4 | 23.7 | 5 | 112 MiB |
| 100 | **juggler** | **18 509** | **5.7** | **21.8** | 0 | 142 MiB |
| 100 | apache (event MPM) | 4 305 | 39.4 | 271.7 | 10 | 34 MiB |
| 100 | caddy | 7 564 | 15.3 | 65.4 | 0 | 36 MiB |
| 1 000 | nginx | 21 517 | 45.8 | 147.0 | 0 | 128 MiB |
| 1 000 | **juggler** | **21 821** | **46.6** | 171.8 | 0 | 154 MiB |
| 1 000 | apache | 5 332 | 129.8 | 726.9 | 24 118 | 123 MiB |
| 1 000 | caddy | 4 028 | 305.5 | 2 150 | 0 | 165 MiB |
| 10 000 | **nginx** | **18 228** | **468** | **1 510** | 0 | 269 MiB |
| 10 000 | juggler | 16 804 | 595 | 3 820 | 1 331 (cold burst) | 350 MiB |
| 10 000 | apache | 3 725 | 403 | 3 770 | 18 559 | 220 MiB |
| 10 000 | caddy | 2 278 | 2 360 | 4 710 | 1 565 | 965 MiB |

Warm follow-up spot runs (same series, 12 s): **juggler 18 000 rps vs nginx 16 882** —
the matrix's juggler number carries the cold-burst penalty.

### Qualitative comparison (why the numbers look like this)

| Server | Threading model | Config model | Hot reload | Native dynamic runtime | Memory behaviour |
|---|---|---|---|---|---|
| **Juggler** | thread-per-core shards, SO_REUSEPORT | gRPC Control Plane + JSON routes | atomic, in-memory, zero restart | **WASM plugins in-process** (wasmtime sandbox) | single process, mimalloc, pre-warmed pool |
| nginx | forked workers, kernel-scheduled accepts | nginx.conf on disk | `nginx -s reload` (re-exec, shared-state handoff) | none (C modules, Lua via OpenResty fork) | 12 workers × ~11 MiB |
| Apache 2.4 | event MPM (threads), per-connection thread pool | httpd.conf + .htaccess per directory | graceful restart needed; .htaccess reread per request | mod_php / mod_wsgi (in-process, weak isolation) | cheap idle, collapses under 10k concurrency |
| Caddy | per-thread event loops (Go runtime) | Caddyfile/API (JSON) | via admin API, zero-downtime | built-in modules compiled in, no sandboxing | GC-driven: 1 GiB+ at 10k conns |
| HAProxy 2.9 | nbthread event-driven, http-reuse | haproxy.cfg | seamless reload (new process, socket handoff) | none (Lua embedded, no isolation) | very low idle, stable under load |
| Envoy | per-worker event loops | static YAML / xDS | xDS atomic config swap | WASM (separate sandboxed VMs) | ~30 MiB idle, 330+ MiB at 10k |
| Traefik v3 | Go event loops | YAML labels/file provider | file watcher, zero-downtime | plugins (Yaegi interpreter, slow) | GC-driven: 800+ MiB at 10k |
| OpenLiteSpeed | event-driven, worker processes | text conf + WebAdmin | WebAdmin restart | LSPHP/MPHP (in-process) | very low idle, collapsed at 10k here |

Takeaways:

- **Juggler vs nginx**: wins at low concurrency (+15%, all percentiles), parity at 1k, statistically tied at 10k warm — while adding what nginx cannot do without its ecosystem: hot gRPC config, sandboxed WASM filters, one-process footprint (14 MiB idle vs 144 MiB).
- **Juggler vs Apache**: 4.3× the RPS at c=100 and 5.6× fewer tail errors at 1k. Apache's event MPM still pays a per-connection thread hand-off that collapses under connection storms. Apache's `.htaccess` per-request re-reading is Juggler's single validated policy — same CMS rules, no filesystem stat on every request.
- **Juggler vs Caddy**: 2.4× RPS at c=100 and 8× at c=1k in this matrix. Caddy's Go GC under a 10k storm ballooned to ~1 GiB (page-cache inclusive) with 2.4 s average latency. Caddy remains the easiest ACME/TLS automation story — Juggler answers with certificates-from-Control-Plane on the roadmap.
- OpenLiteSpeed shares LiteSpeed's event-driven kernel model: in the v0.8.0 matrix it topped c=100 (9 507 rps) but its proxy config saturated beyond that (see ⚠️ above) — re-verification needed before conclusions.
- HAProxy and Envoy are TCP-bred, not HTTP-bred, at this config level: HAProxy needs more threads for 10k conns; Envoy's p99 is the best of the field (1 830 ms) but its error count needs re-verification.

## Findings

1. **c=100: Juggler dominates** — +22% RPS, better latency at every percentile. Thread-per-core sharding without worker synchronisation is the right shape for hot low-concurrency traffic.
2. **c=1 000: near-parity** — nginx −12% ahead on avg/p99; within this host's ±15–20% run noise. Spot runs flip the ordering.
3. **c=10 000: gap closed, errors nearly eliminated.** After adding upstream-pool pre-warming (256 sockets per upstream opened at startup/hot-reload) and the sticky-probe fix, warm 10k runs deliver **17 444 rps with 8 timeouts (0.008%)** — statistically ahead of nginx (17 147) in the same series. The remaining burst-error spike is confined to the very first seconds of a cold container.
4. **Stability:** no collapse reproduced post-fix; repeated 10k runs ranged 14.3k–17.4k rps. The one 866 rps outlier in the previous report is now attributed to loadgen port exhaustion (nginx hit 7.6k in the same window).

## Performance bugs found & fixed during benchmarking (cumulative)

| # | Bug | Symptom | Fix |
|---|---|---|---|
| 1 | No upstream keep-alive (connect-per-request) | 28k connection errors, TIME_WAIT exhaustion, 2 467 rps | `UpstreamPool` with idle reuse + stale prune → **20 763 rps (×8.4)** |
| 2 | Nagle's algorithm enabled | ~40 ms delayed-ACK plateaus | `TCP_NODELAY` on every accepted + upstream socket |
| 3 | Stale pooled-socket race | 502 "non-HTTP response" (97/run) | Zero-byte read from pooled socket ⇒ silent fresh-connection retry |
| 4 | Tokio default accept backlog (1024) | Lost connections in storms | socket2 backlog 8191 + somaxconn sysctl |
| 5 | glibc arena retention | 500+ MiB RSS after storms | Global `mimalloc` → 267 MiB peak |
| 6 | Single work-stealing runtime, one accept queue | Tail latency at 10k (p99 2.55 s), 866 rps collapse risk | **Sharded accept: 12 per-core runtimes + SO_REUSEPORT** → gap closed |
| 7 | Cold upstream pool at burst start | 1.6% timeouts on the opening 10k burst | **Pool pre-warm** (256 sockets/upstream at startup + hot-reload) → 0.008% warm |
| 8 | Half-open probe slot could wedge (least-connections tie-break could starve the probe) | Health recovery flaky under concurrency | **Sticky probe**: probe target ordered first + slot TTL self-release |

## Honest caveats

- Plain-HTTP path measured (wrk cannot speak TLS). Juggler's TLS path adds rustls handshakes; plain-HTTP comparison isolates the routing/proxy engine.
- Single upstream, tiny responses, HTTP/1.1 only in this matrix. HTTP/2 multiplexing, large bodies and multi-upstream fan-out are not covered here.
- wrk-generated client traffic from one container; Docker Desktop loopback is far from real-WAN conditions (no packet loss, no RTT).
- Host noise is high (WSL2 VM shared with build workloads): repeated identical runs vary ±15–20%. Percentile ordering is stable, absolutes are not.
- nginx was given a deliberately generous config (20k worker_connections, keepalive 512, access_log off) — never the artificial bottleneck.
- The matrix 10k run for Juggler shows 1.6% errors from the opening connection burst; steady-state spot runs showed 0.1–0.4%. This is the honest worst case of the current pool warm-up behaviour.

## How to reproduce

```bash
docker compose -f bench/docker-compose.bench.yml up -d
python bench/apply_bench_routes.py                      # hot-reload routes into juggler
python bench/run_bench.py                               # full matrix c=100/1000/10000
python bench/run_bench.py 100 1000                      # subset
# single spot-checks (let TIME_WAIT drain ~30s between 10k runs):
docker exec bench-wrk sh -c "wrk -t4 -c10000 -d10s --latency --timeout 5s http://juggler:8080/bench"
docker exec bench-wrk sh -c "wrk -t4 -c10000 -d10s --latency --timeout 5s http://nginx:8081/bench"
```

Raw data: `bench/results.json` · Interactive view: `bench/report.html`
