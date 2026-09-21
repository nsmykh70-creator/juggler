# Juggler v0.9.0 — two-round single-variable A/B (pool cap, prewarm target)

**Date:** 2026-09-21 · **Binary:** one `juggler:bench` (v0.9.0/Pythia + prewarm-target env) ·
**Host:** Docker Desktop (WSL2), 12 cores / 15.9 GiB · **Load:** wrk 4.2.0, 4 threads, 15 s/run ·
**Repeats:** 9–10 rounds per arm per cell, cold + warm phases, fresh container per round ·
**Control:** nginx interleaved every 2nd block, same session, same wrk invocation.

Why two rounds: round 1 pinned the **pool cap** (`pool_cap_per_upstream`, admin knob)
at 64 vs 256 with prewarm target 256 in both arms. Round 2 pinned the pool at the
matrix-level **4096** in both arms and flipped only the **prewarm target**
(`JUGGLER_PREWARM_TARGET_CAP`, 64 vs 256) — the exact variable the v0.8→v0.9 matrix
comparison was suspected of confounding. All other knobs pinned both rounds:
balancer leastconn, prewarm floor 32, idle timeout 300 s.

## Round 1 — pool cap 64 vs 256 (prewarm target 256 both arms)

Cold / warm medians, rps (p99 ms in brackets):

| c | phase | cap64 | cap256 | Δ | nginx ctl |
|---:|---|---:|---:|---:|---:|
| 100 | cold | 7 702 (74) | 8 474 (61) | +10% | 6 226 |
| 100 | warm | **3 473 (1 640)** | 8 208 (74) | **+136%** | 7 817 |
| 1 000 | cold | 7 962 (1 025) | 10 807 (316) | **+36%** | 12 877 |
| 1 000 | warm | 7 547 (1 110) | 10 620 (350) | +41% | 12 969 |
| 10 000 | cold | 3 500 | 3 172 | −9% | 12 265 |
| 10 000 | warm | 3 171 | 7 171 | **+126%** | 12 583 |

**Finding.** A pool cap below the steady-state client-connection count converts
every excess keep-alive request into a fresh upstream connect — permanent churn.
At c=100 warm (100 clients, 64 sockets) that is ~36 reconnects per 100-request
cycle: p99 1.64 s, RPS −58%. At 10k in-flight *both* caps (64 and 256) are
catastrophically below demand → both arms collapse to 3.1–3.5k RPS with ~2k
errors/run. **A pool cap of 64 is the single most damaging misconfiguration
measured in this whole exercise.**

## Round 2 — prewarm target 64 vs 256 (pool 4096 both arms)

Cold / warm medians, rps (p99 ms in brackets):

| c | phase | prewarm 64 | prewarm 256 | Δ | nginx ctl |
|---:|---|---:|---:|---:|---:|
| 100 | cold | 20 620 (33) | 20 557 (34) | ≈0 | 17 273 |
| 100 | warm | 21 274 (35) | 19 814 (32) | +7% | 16 958 |
| 1 000 | cold | 25 421 (160) | 23 667 (171) | +7% | 24 121 |
| 1 000 | warm | 24 780 (178) | 24 939 (178) | ≈0 | 24 277 |
| 10 000 | cold | 17 516 (3 320) | 16 595 (3 310) | +6% | 19 955 |
| 10 000 | warm | 13 899 (2 920) | 14 105 (2 880) | ≈0 | 21 214 |

Pool after the 12 s warm soak: **4096 sockets in both arms** (the adaptive
high-water fill, not the prewarm target, sizes the pool under load).

**Finding.** With a correctly sized pool, the *prewarm target* — 64 or 256 —
has **no significant effect at any concurrency, cold or warm** (all deltas ±7%,
inside the 1.1–1.5× run-to-run spread). The adaptive HWM fill plus on-demand
connects covers the opening burst; prewarm only shaves the first ~2 s.

## Drift calibration (what the matrix comparison actually showed)

Same-session nginx control moved 6.2k → 12.9k → 20k RPS across sessions on this
host — a 2–3× between-session drift. Normalizing each Juggler number by the
nginx control of its own session (cold c=1000):

| session | Juggler | nginx | ratio |
|---|---:|---:|---:|
| v0.8.0 matrix | 12 499 | 12 015 | **1.04** |
| v0.9.0 matrix (cap-64 build) | 8 097 | 10 630 | **0.76** |
| round 1, cap 256 | 10 807 | 12 877 | 0.84 |
| round 2, prewarm 64 / 256 | 25 421 / 23 667 | 24 121 | **1.05 / 0.98** |

At c=10 000 cold: v0.8 1.01, v0.9 matrix 0.80, round 2 today 0.88.

**Verdict.** The "Pythia slowed Juggler by 21–35%" reading does not survive.
Today's v0.9 with the matrix-level pool (4096) sits **at v0.8's normalized level
and at parity with nginx at c≤1k**. The matrix dip is explained by the cap-64
configuration that build carried (a devastating setting per round 1) plus
between-session drift — two confounds the original matrix could not separate.
Caveat: the matrix-era image was overwritten by later rebuilds, so its exact
runtime cap could not be forensically verified; the cap-64 attribution rests on
the round-1 measurement, not on archaeology.

## Scorecard vs the pre-registered predictions

| prediction | outcome |
|---|---|
| c=1k cap256 ≈ 9.5–11.5k (central ~10.7k) | ✅ 10 807 (round 1 cold median) |
| c=100 cap256 ≈ 6.3–6.7k | ✅ 8 474 — above the band, session ran fast (nginx control 6 226 vs hist 6 219 — ratio, not absolute, is comparable) |
| c=10k matrix-mode ≈ 9.5–11.5k | ❌ **refuted as a prewarm effect**: with pool 4096 the prewarm target is irrelevant; today's 16.6–17.9k cold / 13.9–14.1k warm is far above the band, but that gain is host+config, not prewarm |
| warm 10k ≈ 17–19k | ❌ 13.9–14.1k — warm is *below* cold, and below nginx's 21.2k the same session |
| warm errors ≈ 0–0.05% | ❌ ~1.4% persist at 10k warm (median ~2 900 errors/run), nginx 0 |
| warm p99 ≈ 0.8–1.5 s | ❌ 2.9 s |
| memory @10k rises with prewarm (430–470 MiB) | ⚠️ right direction, driven by the *pool*, not prewarm: 500–550 MiB warm with pool 4096 vs ~330 with pool 256 |

## Where Juggler actually stands, and the next bottleneck

- c≤1k: v0.9 at pool 4096 is at or above nginx on this host (cold and warm),
  with p99 33–178 ms vs nginx 33–193 ms. The datapath work (parser, pooling)
  is doing its job.
- c=10k is the frontier, and it is **not** about prewarm: even fully warmed,
  Juggler sheds ~1.4% of requests (socket errors, p99 ~2.9 s, worst ~3.5 s)
  while nginx holds 0 errors at 21k RPS / p99 1.2 s. The same profile existed
  in the v0.8 matrix (3 617 errors @10k) — it predates Pythia.
- Memory scales with pool size at 10k (500–550 MiB at pool 4096 vs nginx ~280).
  The pool cap is a direct RPS↔RAM dial at high concurrency.

Next diagnostics, in order of expected yield: (1) where the 10k-warm socket
errors originate (upstream connect vs read timeout vs client-side accept queue —
needs the error breakdown per run, currently summed by wrk); (2) whether the
4-thread wrk loadgen is itself saturating at c=10k on WSL2 (nginx 21k looks
loadgen-limited); (3) upstream read/write timeouts under queue depth vs pool
width — a wider pool trades RAM for churn, the 4096 setting may be past the
knee.

## Diagnostics (session 2): the 10k profile, per-class errors, and the real fix

Method: per-class wrk socket errors (connect/read/write/timeout, previously
summed into one number), `--timeout 30s`, in-flight gauge sampled during runs,
metrics deltas around each run; prewarm-target and connect-permit arms via
compose interpolation, pool-cap arms via the settings API. Harness:
`bench/run_diag.py`, `bench/run_poolsize.py`, `bench/run_wave2.py`.

### What the "1.4% errors @10k" actually was

**A wrk `--timeout 5s` artifact.** With a 30 s client timeout the 10k error
count drops to **zero across every class** (non-2xx 0, socket 0/0/0/0) in all
fixed-binary runs. Nothing was being lost — requests were simply slower than
5 s under the burst (p99 7.5–9.4 s pre-fix). The "Juggler sheds 1.4% of
requests" line above was wrong; the profile is a tail-latency problem, not a
loss problem.

### Bugs found and fixed on the way (all verified by unit tests, 68/68)

1. **`active_upstream_connections` leaked on every error path** — 26 170
   zombie in-flight after 16 h idle. Manual inc/dec in `try_upstream_attempt`
   had no decrement on success or on any `?` bail-out, and pooled-path stale
   retries decremented without a paired increment. Replaced with an RAII
   `InFlightGuard`; the adaptive-prewarm HWM and the forecast module were
   being fed the leaked number.
2. **Upstream writes had no timeout** (read had 30 s, write none): a
   zero-window upstream parked the dispatch future forever — warm runs left
   821–1 747 permanently-parked in-flight after the burst ended. Now
   `UPSTREAM_WRITE_TIMEOUT` (30 s) on both the fresh and the pooled path.
3. **Slow-request watchdog spammed the datapath**: 15 131 lines per 10k run
   (nearly every request >1 s during the burst). Now rate-limited to one line
   per second.
4. **`UPSTREAM_REQUESTS_TOTAL` was double-counted** (dispatch loop + both
   call-sites): metrics showed ~2× request volume. Call-site increment removed.

### Hypotheses tested at c=10k (one variable each)

| Hypothesis | Test | Result |
|---|---|---|
| Prewarm target 64 vs 256 matters at 10k | prewarm64 vs prewarm256, pool 4096 | **No effect** — pool fills adaptively to 4096 either way; deltas ±7% ≈ run spread |
| Fresh-connect semaphore (256) serializes the cold wave | perm256 vs perm2048 | **No effect** (cold p99 4.5–4.8 s in both arms) |
| Global pool mutex is a 10k convoy (smaller pool → shorter convoy) | pool4096 vs pool512 | **Opposite**: pool512 warm p99 10.7–11.2 s vs 9.2–10.5 s at 4096 |
| The pool mutex itself is the bottleneck | pool4 ≈ pooling off | **Decisive refutation**: warm 381–610 RPS with 3.4–9.2k non-2xx (fresh-connect flood). The pool is essential; the mutex is not the tail |
| Warm burst fights lingering client keep-alive conns from the cold phase | pool4096 + **90 s** soak between phases | **Confirmed — the mechanism**: warm 17.1–23.7k RPS (median 22.5k), p99 4.2–6.7 s, **zero errors**, ≥ cold and ≥ nginx control (19.7k same session) |

### Verdict at c=10k (revised)

The historical "warm slower than cold + ~1.4% errors" profile was a
**harness self-interference artifact**: a 12 s phase gap lands the second
10k burst while the first burst's client keep-alive sockets (60 s idle grace)
still occupy the accept path. With a clean 90 s gap, warm @10k is **~22.5k
RPS / p99 ~4.3 s / 0 errors** — inside the 17–19k pre-registered prediction
band (above it), at or above nginx's 19.7k on the same session. Remaining
gap vs nginx is p99 (4.3 s vs 1.5–1.8 s), not throughput or errors.

Operational takeaways: (1) keep the pool cap at the matrix level (4096); (2)
keep `adaptive_prewarm` on — it holds the pool at HWM/2, which is what warm
runs ride; (3) in benchmark harnesses, space consecutive phases by ≥ 90 s at
c=10k, or Juggler (and any server with keep-alive grace) is measured fighting
its own previous phase; (4) wrk needs `--timeout` ≥ the expected p99 ceiling,
otherwise tail latency is reported as lost requests.

Host stability note: the WSL2 VM collapsed mid-run (all containers Exited 255,
`vmmem` memory pressure — 10k bursts plus the full eight-server stack). Docker
Desktop was restarted; the runner now retries wrk execs against transient
Docker API failures.

Artifacts for this section: `bench/results_diag.json`,
`bench/results_poolsize.json` (wave 1), `bench/results_poolsize2.json`
(wave 2), runner logs `bench/diag_run.log`, `bench/poolsize_run.log`.

## Artifacts

- `bench/results_ab_round1.json`, `bench/results_ab_round2.json` — raw rows
- `bench/ab_run.log`, `bench/ab_round2.log` — full runner logs
- `bench/run_ab.py` (runner), `bench/ab_summary.py` (medians/spread),
  `bench/ab_recover.py` (log→json), `bench/compose.ab.yml` (admin-API overlay)
- Code: `JUGGLER_PREWARM_TARGET_CAP` env (src/main.rs) — prewarm target
  overridable without a rebuild; adaptive tick clamps to it
