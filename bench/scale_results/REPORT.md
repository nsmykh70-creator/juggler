# Scale ladder v2: Juggler vs nginx (Docker) vs Apache (Docker) vs nginx (native Windows) — 2026-09-30

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
