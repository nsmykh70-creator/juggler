# Scale ladder: Juggler vs nginx vs Apache (2026-09-29)

Same machine (Windows host), same load generator (`bench/scaleload.exe`,
HTTP/1.1 keep-alive, 8 connections, fixed request budget), same content
(1 024-byte static file), two passes per (scale, server) with rotated order.

| Requests | Juggler RPS | Juggler p99 | nginx RPS | nginx p99 | Apache RPS | Apache p99 |
|---------:|------------:|------------:|----------:|----------:|-----------:|-----------:|
|       100 | **2 667** | 4 ms | 797 | 18 ms | 1 033 | 9 ms |
|     1 000 | **3 098** | 4 ms | 948 | 18 ms | 1 074 | 14 ms |
|    10 000 | **2 207** | 10 ms | 946 | 19 ms | 1 286 | 11 ms |
|   100 000 | **3 391** | 35 ms | 1 434 | 60 ms | 1 844 | 28 ms |
| 1 000 000 | **3 436** | 35 ms | 1 308 | 51 ms | 1 672 | 35 ms |

**Juggler / nginx: 2.3–3.3x. Juggler / Apache: 1.7–2.9x.** Lead is stable
across the whole ladder — no crossover at any scale.

Errors (loader-side reconnects after keep-alive cap): symmetric artifact —
Juggler 0.1–0.2%, nginx ~0.2%, Apache ~2% (KeepAliveRequests default 100).
Every error is a reconnect, not a failed response; ok-counts match budgets.

## Caveats (honest interpretation)

- nginx and Apache run in Docker Desktop containers: Windows NAT port-proxy
  + 9P bind-mount docroot penalize nginx's sendfile-heavy path hardest.
  On bare-metal Linux nginx would be considerably faster; this ladder
  measures the Windows-host environment, which is the environment Juggler
  ships in.
- Juggler served plain HTTP on the same hand-off architecture as production
  (12 shards, per-core affinity, prewarmed pools, rate limiter disabled for
  the bench via env).
- Load generator is sequential keep-alive per connection (no pipelining),
  so per-request routing cost dominates; the precomputed RouteTable
  (commit d08dce7) is part of what is being measured here.

## Reproduce

```
docker run -d --name scale-nginx   -p 8181:80 -v %CD%/www_scale:/usr/share/nginx/html:ro nginx:alpine
docker run -d --name scale-apache2 -p 8182:80 -v %CD%/www_scale:/usr/local/apache2/htdocs:ro httpd:alpine
target\scaleload.exe 127.0.0.1 <port> /sample_sites1/bench1k.html 8 <requests>
python bench/scale_ladder.py        # full ladder, JSONL artifacts
```

Artifacts: `bench/scale_results/scale_ladder.jsonl` (30 runs),
`scale_ladder.json` (aggregate), `bench/srv_scale/` (bench stand).
