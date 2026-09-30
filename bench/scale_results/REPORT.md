# Scale ladder v5: Juggler (batched writes) vs nginx (Docker) vs Apache (Docker) vs nginx (native Windows) — 2026-09-30

Same machine (Windows host), same load generator (`bench/scaleload.exe`,
HTTP/1.1 keep-alive, fixed request budget, 8 conns ≤10k / 32 conns ≥100k),
same content (1 024-byte static file), two passes per (scale, server) with
rotated order. 40 runs total.

**What changed since v1:**

1. **SIMD parsing** — hot request scans (`\r\n\r\n` head terminator,
   request-line splits, `Connection:` patching) now use `memchr`
   (AVX2-class, 32 bytes/instruction) instead of 1-byte loops.
2. **Unlimited keep-alive for benches** — `JUGGLER_MAX_KEEPALIVE_REQUESTS=0`
   now disables the per-connection cap entirely (previously `0` meant
   "close after every response"; default remains 1000). Loader reconnect
   errors on Juggler dropped from ~0.1% to **0 across all 2.2M requests**.
3. **Native nginx on Windows** (official 1.27.4 binary, port 8185) —
   removes the Docker Desktop NAT/9P objection for the nginx comparison.
4. Loader gained a 15 s read timeout (a mid-response stall previously
   hung the whole run).

## Results

RPS / p99 ms, mean of 2 passes:

| Requests | Juggler | nginx-Docker | nginx-native | Apache-Docker | J/ngxD | J/ngxn | J/ap |
|---------:|--------:|-------------:|-------------:|--------------:|-------:|-------:|-----:|
|       100 | **4 186** | 679 | 1 771 | 885 | 6.2x | 2.4x | 4.7x |
|     1 000 | **5 235** | 520 | 2 112 | 1 040 | 10.1x | 2.5x | 5.0x |
|    10 000 | **6 027** | 774 | 1 995 | 1 070 | 7.8x | 3.0x | 5.6x |
|   100 000 | **5 997** | 774 | 1 997 | 1 648 | 7.7x | 3.0x | 3.6x |
| 1 000 000 | **5 073** | 584 | 1 944 | 1 611 | 8.7x | 2.6x | 3.1x |

**Juggler / native nginx: 2.4–3.0x. Juggler / Apache: 3.1–5.6x.
Juggler / nginx-in-Docker: 6.2–10.1x.** Lead is stable at every scale,
including 1M requests (~8.6–10.9M total requests per server across the
ladder). p99 is 5–7x lower than nginx-Docker, ~equal to native nginx
at 100k, 2–3x better at small scales.

Errors: **0 for all four servers across 2.22M requests each.**

## Honest interpretation

- **Docker caveat eliminated for nginx:** native nginx.exe still loses by
  2.4–3.0x, so the v1 lead was not a virtualization artifact.
- Native nginx on Windows is historically weaker than on Linux (select-based
  event loop, no IOCP); on bare-metal Linux the gap would likely shrink but
  not invert — Juggler's per-core shard hand-off architecture is the same
  on both OSes.
- This ladder measures small-file routing cost (1 KB payload), not bulk
  transfer. For large files, kernel sendfile()/TransmitFile zero-copy
  favors nginx-class servers more.
- The remaining unfair advantage: Juggler owns native thread-per-core
  scheduling on the host; Dockerized nginx/Apache cannot match affinity
  through the vSwitch. Native nginx can, but its Windows event loop
  squanders it.
- 1M-request runs on nginx-Docker took up to ~13 min each (stall-prone);
  the loader now times out reads at 15 s instead of hanging forever.

## Windows-native optimizations applied / rejected

| Technique | Status | Notes |
|---|---|---|
| IOCP event loop | **already on** | tokio/mio uses IOCP on Windows, epoll on Linux, kqueue on BSD |
| RIO (Registered I/O) | rejected | raw Winsock API, incompatible with tokio runtime; rewrite cost ≫ win at these scales |
| TransmitFile zero-copy | rejected | plaintext-only kernel path; TLS termination must encrypt in userspace anyway |
| TCP_NODELAY | already on | all client and upstream sockets |
| SO_REUSEPORT | already on | Linux accept path (hand-off on Windows where REUSEPORT is unavailable) |
| Keep-alive pooling | already on | upstream pool + prewarm; client side 0=unlimited now |
| SIMD header scanning | **applied this pass** | memchr on hot scans |
| Buffer pool 16 KiB | already on | per-shard freelist, malloc-free keep-alive path |
| mimalloc | already on | same class as jemalloc/tcmalloc |
| Static-file cache | already on | 64 MB byte-bounded, stat-validated |
| gzip | already on | hand-rolled DEFLATE (fixed-Huffman + LZ77) |
| TLS resumption | already on | rustls default session tickets |

## High-concurrency probe (2026-09-30)

The v2 ladder uses 8 conns (32 for >=100k). To answer "is the ~5-8k RPS a
loader-side ceiling?", a dedicated concurrency sweep was run against Juggler
(single scaleload process unless noted; budget-fixed, keep-alive):

| Conns | RPS | p50 ms | p99 ms |
|------:|----:|-------:|-------:|
| 8 | 7 323 | 1.07 | 3.2 |
| 32 | **8 616** | 2.43 | 15.6 |
| 64 | 7 033 | 5.25 | 42.4 |
| 128 | 6 265 | 6.10 | 113 |
| 512 | 6 198 | 80.7 | 245 |
| 4 procs x 512 | 5 471 | 85-95 | 263-292 |

Reading: RPS *rises* from 8 to 32 conns, then decays as loopback+scheduler
saturation dominates; at 512 conns Juggler still delivers 6.2k RPS with zero
errors, 3x the native-nginx figure at 128 conns (2 060 RPS). Native nginx at
its own sweet spot (32 conns): 2 022 RPS; Apache: 1 660 RPS. Multi-process
loading (512 conns) does not unlock a hidden Juggler ceiling - the ~6-8.6k
RPS plateau on this laptop is the shared loopback/CPU envelope, not an
8-connection artifact. The v2 ladder's 8-conn numbers understate Juggler by
~20% at the sweet spot, overstate nothing.

## Reproduce

```
docker run -d --name scale-nginx   -p 8181:80 -v %CD%/www_scale:/usr/share/nginx/html:ro nginx:alpine
docker run -d --name scale-apache2 -p 8182:80 -v %CD%/www_scale:/usr/local/apache2/htdocs:ro httpd:alpine
cd bench/nginx-win/nginx-1.27.4 && start nginx.exe   # listens on 8185
python bench/scale_ladder.py                          # full ladder, JSONL artifacts
```

Artifacts: `bench/scale_results/scale_ladder.jsonl` (40 runs),
`scale_ladder.json` (aggregate), `bench/srv_scale/` (bench stand),
`bench/nginx-win/` (native nginx config).

## v3 (2026-09-30): event_interval, buffer pool, pipelined loader

Third-party tuning advice, checked against the code and measured live
(final binary, bench stand :8181, 32 conns x 300 reqs x depth, 3 reps):

- **`JUGGLER_EVENT_INTERVAL`** (tokio current-thread worker budget before
  re-polling IOCP; default 61 = tokio's own default). Medians: evt61
  d1 4.7k / d4 5.3k / d8 5.1k RPS vs evt256 d1 4.6k / d4 5.2k / d8 5.5k.
  Within run-to-run noise (~15%) except a slight d8 win; default stays 61.
- **Buffer pool cap 64 -> 256 per shard** (`BUF_POOL_CAP_PER_SHARD`):
  at the observed 512-conn peak 64/shard already covered demand (no
  off-pool allocations seen), raised anyway as cheap headroom.
- **Pipelining in `scaleload`** (depth 1/4/8, batched writes): best
  throughput at depth 4-8 (~5.3-7.7k RPS vs ~4.2-4.9k at depth 1 across
  the day's runs). d8 did not beat d4 consistently - the H1 loop still
  writes one response per `write_all`; batched response writes are the
  next server-side reserve, not a defect.
- **Windows TCP registry keys (MaxUserPort, TcpTimedWaitDelay)**:
  rejected with evidence. `netsh int ipv4 show dynamicport tcp` shows the
  modern 49152-65535 range (MaxUserPort is an XP/2003-era key), TIME_WAIT
  sockets observed in the double digits under full load, and 0 reconnect
  errors on 2.2M requests - the stack was never the bottleneck.
- **`advance()` vs slice parsing**: not applicable - the parser works on
  raw byte ranges and advances the buffer once per request via
  `split_to` (O(1), no per-chunk shifts).

Two production defects found and fixed while re-validating this pass:

1. **Backpressure counter underflow (race).** `ShardMailbox::push` enqueued
   into the SegQueue *before* incrementing `PENDING_CONNS`; a pop that
   interleaved decremented a not-yet-incremented counter, `0usize - 1`
   wrapped to `usize::MAX`, and the hysteresis saw a phantom full mailbox
   (live symptom: `Backpressure ON: 18446744073709551615 connections
   queued`, accept paused, 99% of requests failing). Fix: count before
   enqueue. Regression test replays the interleaving with a concurrent
   drainer thread.
2. **`JUGGLER_MAX_KEEPALIVE_REQUESTS=0` semantics** (from the earlier
   v2 pass, kept here for reference): 0 now means unlimited keep-alive,
   not close-every-connection.

Validation: 115/115 unit tests, clippy clean in server code
(remaining 4 warnings live in `bench/h3load.rs`, out of server scope).

## v4 (2026-09-30): batched response writes

The H1 loop used to issue one `write_all` per response, so a depth-8
pipeline paid 8 write syscalls (and 8 IOCP completions) for one socket
read. Now every dispatch arm appends into a per-connection accumulator
(`wtr`, reused across requests — no steady-state allocation) and the
batch ships in ONE write at three points:

- before each blocking read (the natural pipeline-batch boundary — placed
  after the parse-first check, so requests already in the buffer are
  served and batched before the syscall);
- at 256 KiB (`BATCH_FLUSH_CAP`), so giant proxy bodies flush instead of
  growing the accumulator;
- before every close path (431/400/429, `Connection: close`, EOF). Proxy
  arms additionally flush right before dispatching upstream, so a slow
  origin never delays responses already owed to the client.

Live A/B (same stand, 32 conns x 300 reqs, 3 reps, medians):

| depth | before | after | note |
|---:|---:|---:|---|
| 1 | ~4.7k | ~6.4k | +36% (even unpipelined keep-alive wins: one write per drain) |
| 4 | ~5.3k | ~6.6k | +25% |
| 8 | ~5.1k | ~6.0k | the d8 dip below d1 is gone |
| 16 | — | ~7.0k | best mode |

0 errors across all reps. Unit tests cover `flush_batch` semantics
(empty no-op, exact bytes, accumulator reuse); 118/118 total.

### d8 vs d4 focused A/B (same day, final binary)

The v4 sweep above used 300-req runs and showed d4 > d8 — a small-sample
artifact. Rigorous protocol: 2000 reqs per run, 12 runs per depth,
order-reversed between repetitions to cancel warm-up drift. Pooled
medians: **d8 8 951 vs d4 8 464 RPS (+5.7%)**, p99 comparable (d8
slightly better); distributions overlap, both sit at the machine's
envelope (the 32-conn ceiling from the high-concurrency probe, ~8.6k).

The pre-batch d8 dip below d4 is gone. That dip was the 8-writes-per-1-
read syscall multiplication; with batching the write count is constant
(1 per batch) regardless of depth, so deeper pipelines gain relatively
more (d8 +76% vs pre-batch median, d4 +60%).

## v5 full ladder re-run (batched-writes binary)

All 40 runs re-measured on the final binary (5 scales x 4 servers x 2
passes, rotated order, 8 conns up to 10k / 32 conns above; Juggler plain
plane :8180, unlimited keep-alive). Prior artifacts kept as
`scale_ladder_v2_batchless.{jsonl,json}`.

| Requests | Juggler | nginx-Docker | nginx-native | Apache | J/ngxn | J/ngx | J/ap |
|---:|---:|---:|---:|---:|---:|---:|---:|
| 100 | 4 609 | 499 | 1 849 | 1 121 | 2.5× | 9.2× | 4.1× |
| 1 000 | 7 303 | 742 | 1 912 | 1 284 | 3.8× | 9.8× | 5.7× |
| 10 000 | 6 314 | 736 | 2 174 | 1 151 | 2.9× | 8.6× | 5.5× |
| 100 000 | **9 527** | 884 | 2 184 | 1 884 | 4.4× | 10.8× | 5.1× |
| 1 000 000 | **8 955** | 892 | 2 122 | 1 850 | 4.2× | 10.0× | 4.8× |

**8 888 800 total requests, 0 errors across all 40 runs** (both passes
aggregated; the ladder's reconnect issue stays at zero with unlimited
keep-alive).

Juggler vs its own v2 (pre-batch) medians: 100 +10%, 1k +39%, 10k +5%,
100k +59%, 1M +77%. The gain grows with scale — longer keep-alive
sessions amortize the batch accumulator and the single-write drain, and
the 100k/1M legs (32 conns) additionally benefit from the unlimited
keep-alive semantics fixed in v2. The edge over native nginx widened
from 2.4-3.0× (v2) to 2.5-4.4× (v5). p99 medians: 1.6-2.3 ms at short
scales, 13-15 ms at 100k/1M under full saturation.

Known noise: laptop-class host with Docker/browser background; the two
passes of some Juggler legs differ up to 40% (e.g. 1M: 8 274 / 9 637),
which is why per-scale medians — not single runs — are reported.
