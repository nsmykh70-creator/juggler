# Juggler v0.9.0 A/B: upstream pool cap 64 vs 256 (single variable)

**Design:** 10 rounds/arm × cold/warm × c=[100, 1000, 10000], wrk 15s per run, 4 threads. One v0.9 binary (juggler:bench); between arms ONLY `pool_cap_per_upstream` flips (64 vs 256, admin API). Startup prewarm = 256 sockets in BOTH arms, floor 32, balancer leastconn — the clamp is the only variable. Fresh container per round. nginx interleaved as the drift control.

## Medians per cell (over all rounds)

| phase | c | cap64 rps | cap256 rps | Δ(cap256 vs 64) | cap64 p99 | cap256 p99 | cap64 errs | cap256 errs | mem64 MiB | mem256 MiB |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| cold | 100 | 7 702 | 8 474 | +10% | 74 | 61 | 3 | 0 | 57.6 | 59.4 |
| cold | 1000 | 7 962 | 10 807 | +36% | 1 025 | 316 | 0 | 0 | 94.6 | 94.3 |
| cold | 10000 | 3 500 | 3 172 | -9% | 3 510 | 1 940 | 2 096 | 0 | 389.8 | 333.2 |
| warm | 100 | 3 473 | 8 208 | +136% | 1 640 | 74 | 3 | 0 | 346.2 | 147.7 |
| warm | 1000 | 7 547 | 10 620 | +41% | 1 110 | 350 | 39 | 0 | 170.6 | 167.3 |
| warm | 10000 | 3 171 | 7 171 | +126% | 3 490 | 3 180 | 1 990 | 3 944 | 422.4 | 432.6 |

## Run-to-run spread (max/min rps per cell)

| phase | c | cap64 | cap256 |
|---|---:|---:|---:|
| cold | 100 | 1.53× | 1.38× |
| cold | 1000 | 1.13× | 1.24× |
| cold | 10000 | 1.75× | 2.89× |
| warm | 100 | 2.65× | 1.51× |
| warm | 1000 | 1.31× | 1.14× |
| warm | 10000 | 1.50× | 1.35× |

## nginx drift control (same session, interleaved)

- cold c=100: median **6 226** rps (9 runs, 5714–7719)
- cold c=1000: median **12 877** rps (9 runs, 10676–13797)
- cold c=10000: median **12 265** rps (9 runs, 10418–12828)
- warm c=100: median **7 817** rps (9 runs, 6028–8996)
- warm c=1000: median **12 969** rps (9 runs, 10399–13546)
- warm c=10000: median **12 583** rps (9 runs, 11278–13696)

## Drift-calibrated Juggler (cold medians)

Juggler's cold median rescaled by the same-session nginx control to the historical session's nginx level (`juggler × hist_nginx / nginx_session`). This removes the between-session drift before comparing with v0.8.0.

| c | v0.8.0 (hist) | v0.9 cap64 (hist matrix) | v0.9 cap64 (this run) | v0.9 cap256 (this run) | v0.9 cap256 restored |
|---:|---:|---:|---:|---:|---:|
| 100 | 6 129 | 5 646 | 7 702 | 8 474 | **8 464** |
| 1000 | 12 499 | 8 097 | 7 962 | 10 807 | **10 084** |
| 10000 | 10 666 | 8 416 | 3 500 | 3 172 | **2 723** |
