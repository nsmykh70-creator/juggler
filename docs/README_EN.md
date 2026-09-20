# 🤹 Juggler Web Server

High-performance HTTPS web server / reverse proxy written in Rust, designed as
a direct answer to the architectural limits of Apache and Nginx. The name says
it all: a server that keeps thousands of connections, hot routes and a pool of
upstreams in the air — without dropping a single ball.

**HTTP/2 out of the box**: ALPN negotiation (`h2` + `http/1.1`) on one port,
stream multiplexing, and h2↔h1 re-encoding in both directions.

| Apache/Nginx problem | Juggler solution |
|---|---|
| Apache: process/thread per request → memory blow-up under load | **Thread-per-Core**: fixed pool (1 thread = 1 core), every connection is a cheap Tokio task |
| Nginx: any config change = worker reload | **gRPC Control Plane**: config swapped atomically in memory (ArcSwap) without dropping connections |
| Nginx: config lives in files, no API | **API-first**: routes are pushed over gRPC; `routes.json` is only a persistence layer |
| Both: no built-in rate limiting | **Token Bucket per IP** inside the core, before proxying |
| Nginx: upstream dies → clients get 502/504 | **Failover + passive health checks**: an unreachable upstream is skipped, and after N failures it is pulled from balancing until the cooldown ends — traffic no longer burns timeouts on dead backends |
| Both (prototype-level): connection closes after each request | **Keep-alive**: one TLS connection serves many requests |
| Both: HTTP/2 requires an extra port or add-ons | **HTTP/2 via ALPN** on the same port: h2 streams are multiplexed and re-encoded to h1 towards upstreams |
| Both: C modules can crash the server | **Sandboxed WASM plugins** (wasmtime): fuel-limited CPU, memory cap, isolation — a filter crash never takes the server down |

## Architecture

```
                    ┌──────────────────────────────────────────┐
  Admin / CI ──gRPC──▶ Control Plane :50051                    │
                    │   └─ ArcSwap<ServerConfig> ──┐          │
                    │                              │ swap     │
  Clients ──TLS────▶ Data Plane :8443               ▼          │
                    │  keep-alive loop                     │
                    │   parse → rate limit → route          │
                    │   → WASM filters (on_request +        │
                    │     transform_body, fail-closed)      │
                    │   → Least Connections + tie-break     │
                    │   → failover → proxy ───────────────────▶ upstream :7001, :7002 …
                    │   → /metrics, /healthz                │
                    └──────────────────────────────────────────┘
         routes.json ▲ (persistence: auto-saved on reload,
                       auto-loaded at startup)
```

* **Data Plane** (8443, HTTPS): TLS termination (rustls, **ALPN: `h2` +
  `http/1.1`** on one port). Certificate: `JUGGLER_TLS_DIR` with `cert.pem` +
  `key.pem` → otherwise self-signed, **generated once and persisted** as
  `cert.pem`/`key.pem` next to the binary (SANs: `localhost`, `127.0.0.1`) —
  a browser exception accepted once survives restarts.
  - *HTTP/2*: stream multiplexing (each stream is its own Tokio task),
    flow-control body reading, re-encoding to HTTP/1.1 towards upstreams
    (hop-by-hop headers stripped, exact Content-Length).
  - *HTTP/1.1*: keep-alive (`Connection` header rewriting, 1000 requests per
    connection cap), Content-Length body reading.
  - *Shared*: Token Bucket rate limiting, **Least Connections with random
    tie-break**, **failover** (dead upstream skipped, 3s timeout), upstream
    response reading aware of Content-Length **and chunked** framing,
    `X-Forwarded-For` / `X-Request-Id` injection, response version
    normalization (an upstream's HTTP/1.0 is served to clients as HTTP/1.1).
* **Control Plane** (50051, gRPC): `UpdateConfig` atomically swaps the routing
  table and persists it to `routes.json`. Validation: path starts with `/`,
  at least one upstream.
* **Persistence**: routes are restored automatically after a restart — the
  server never starts "empty" after a deploy.
* **Admin web UI + telemetry**: `JUGGLER_ADMIN_ADDR` serves a dashboard
  (per-shard active conns, EMA level, **Holt trend + forecast** of load,
  routes with pool depths, WASM and security policy) and a JSON API
  (`/api/stats`, `/api/routes` GET/POST hot-reload, bearer-token auth).
  Prometheus gauges: `shard_active_conns`, `shard_forecast_conns`,
  `upstream_forecast_load`. `JUGGLER_BALANCER=predictive` switches upstream
  selection to the Holt forecast (send requests where the queue WILL be
  shortest). Pools stay warm via a high-water-mark adaptive prewarm
  (target = ½ peak concurrency, floor 32) and periodic `mi_collect()` on
  Unix returns freed pages to the OS after spikes.
* **Metrics** (`/metrics`): `http_requests_total{path,status,proto}` (the
  `proto` label separates `h1` from `h2`), `http_response_seconds`,
  `upstream_requests_total{upstream,outcome}`, `upstream_failover_total`,
  `keepalive_connections_total`, `rate_limit_rejected_total`,
  `active_upstream_connections`.

## Quick start

Requirements: Rust 1.75+ (stable). protoc is vendored in `third_party/protoc/`
— no system package needed (on Linux/macOS a system protoc in PATH also works).

```bash
cargo run --release
```

```
🤹 Juggler: thread-per-core runtime on N core(s)
⚙️  Control Plane (gRPC) listening on 127.0.0.1:50051
🔒 Data Plane (HTTPS) listening on https://127.0.0.1:8443
```

At startup the server reads `routes.json` (create one yourself or push it via
the Control Plane — see the example in the repository).

### Environment variables

| Variable | Default | Description |
|---|---|---|
| `JUGGLER_HTTP_ADDR` | `127.0.0.1:8443` | Data Plane listen address |
| `JUGGLER_GRPC_ADDR` | `127.0.0.1:50051` | Control Plane listen address |
| `JUGGLER_ROUTES_FILE` | `routes.json` | route persistence file |
| `JUGGLER_TLS_DIR` | — | directory with `cert.pem` + `key.pem` (PEM, full chain). Unset — self-signed cert |
| `JUGGLER_REDIRECT_HTTP` | — | plain-HTTP listener address answering `301` to HTTPS (e.g. `0.0.0.0:80`). Host is preserved; the port comes from `JUGGLER_HTTP_ADDR` |
| `JUGGLER_ADMIN_ADDR` | — | admin web UI address (e.g. `127.0.0.1:9090`): dashboard, `/api/stats`, `/api/routes` |
| `JUGGLER_ADMIN_TOKEN` | — | bearer token for the admin API. Unset — auth disabled (localhost use) |
| `JUGGLER_BALANCER` | `leastconn` | `predictive` — pick upstreams by Holt load forecast (level + trend) instead of current connections only |

For production: `JUGGLER_HTTP_ADDR=0.0.0.0:443` (privileged port — mind
capabilities / running as a service user with `CAP_NET_BIND_SERVICE`).
Full Linux VPS deployment guide — **[docs/DEPLOY.md](DEPLOY.md)**: systemd,
Let's Encrypt with a renew hook, firewall, WordPress.

### HTTPS without the browser warning (mkcert)

```bash
scripts/dev-cert.sh                                # or: scripts/dev-cert.sh mysite.dev
JUGGLER_TLS_DIR=dev-certs cargo run --release
```

The script uses `mkcert` (installs a local CA into the OS/browser trust
store) or falls back to `openssl` with a local CA (`dev-certs/dev-ca.pem` —
import manually). No more "Proceed anyway".

## Pushing configuration

### Option A: demo client

```bash
python grpc_client.py          # /api → 127.0.0.1:7001 + 127.0.0.1:7002
```

### Option B: your own JSON

`routes.json`:
```json
{
  "routes": {
    "/api":  { "upstream_addrs": ["10.0.0.1:5000", "10.0.0.2:5000"] },
    "/app":  { "upstream_addrs": ["10.0.0.3:8000"] }
  }
}
```

```bash
python grpc_client.py routes.json
```

### Option C: from Python

```python
import grpc
import control_plane_pb2 as pb2
import control_plane_pb2_grpc as pb2_grpc

with grpc.insecure_channel("127.0.0.1:50051") as ch:
    stub = pb2_grpc.ControlPlaneStub(ch)
    stub.UpdateConfig(pb2.UpdateConfigRequest(routes={
        "/api": pb2.RouteConfig(upstream_addrs=["127.0.0.1:7001"]),
    }))
```

(`control_plane_pb2*.py` are generated automatically on the first run of
`grpc_client.py` / `integration_tests.py`.)

## Testing

```bash
# unit tests (45: bucket, balancer, parser, keep-alive, chunked framing,
# ALPN, h2->h1 encoding, end-to-end h2 over a duplex pipe, WASM plugins, etc.)
cargo test --release

# integration (16 scenarios): start backends and the server, then
pip install requests urllib3 grpcio grpcio-tools "httpx[http2]"
python mock_backends.py &
./target/release/juggler &          # or: cargo run --release
python integration_tests.py
```

The integration suite covers: TLS handshake, `/healthz` + `/metrics`, hot
reload over gRPC, proxying (the `X-Backend` header), Least Connections
behaviour, rate limiter (429 under burst), keep-alive (3 requests over one
connection), failover (dead upstream → live one), health checks (marking
after N failures, dead-skip, auto-recovery via half-open probe), explicit HTTP/1.1,
**HTTP/2 over ALPN with multiplexing** (verified via httpx[http2]), and
**WASM plugins**: header injection, body rewrite, fail-open on a missing
plugin file, plus the `wasm_plugin_calls_total` / `wasm_modules_loaded`
metrics.

## Docker

```bash
docker build -t juggler-server .
docker run -d -p 8443:8443 -p 50051:50051 --name juggler juggler-server
```

Inside the container the default addresses are `0.0.0.0` (see ENV in the
Dockerfile).

With the monitoring stack:

```bash
docker compose up -d
# Grafana: http://localhost:3000 (admin/admin), datasource: http://prometheus:9090
```

Useful PromQL:
```text
rate(http_requests_total[1m])                                       # RPS per path
sum by (proto) (rate(http_requests_total[1m]))                      # RPS: h1 vs h2
histogram_quantile(0.95, rate(http_response_seconds_bucket[5m]))    # p95 latency
rate(rate_limit_rejected_total[1m])                                 # limiter rejections
sum by (upstream) (rate(upstream_requests_total{outcome="error"}[5m]))  # upstream errors
upstream_failover_total                                             # failover events
```

## CI/CD

`.github/workflows/ci.yml` — three jobs per push/PR:
1. **tests** — `cargo fmt --check`, `cargo clippy -D warnings`, unit tests
   run 3 times (the balancer uses random tie-breaks).
2. **integration** — build, start mocks + server, run the full
   `integration_tests.py`, **verify persistence** (server restart with route
   restoration), upload logs on failure.
3. **docker** — image build (publishing enabled by adding a registry login).

## Data Plane HTTP API

| Path | Description |
|---|---|
| `/healthz` | health check, always `200 ok` |
| `/metrics` | Prometheus metrics |
| anything else | routes from the config, otherwise `404 route not found` |

Headers added to upstream requests: `X-Forwarded-For: client`,
`X-Request-Id: req-<64-bit hex>`.

## WASM plugins (wasmtime)

Every route can carry a `wasm_plugin` field — a path to a `.wasm` module
executed by the Data Plane on each request to that route. Modules are
compiled at hot-reload time (one Cranelift cache per process) and
instantiated per-request in an isolated `Store`.

**Per-request lifecycle inside the plugin:**

1. The host publishes the request body (offset 0) and the request path
   (offset 1 MiB) into the plugin's linear memory.
2. Optional export `on_request()` — the header phase: the plugin calls the
   host functions `juggler.add_header(k,kl,v,vl)` and `juggler.path()` to
   attach headers to the proxied request.
3. Optional export `transform_body(ptr, len) -> ptr` — the body phase: the
   plugin rewrites the body (including via `juggler.alloc`) and returns a
   pointer to `[len: u32 le][bytes]`.

**Sandbox hardening:** a fuel budget of 100 000 per call (an infinite loop
traps instead of hanging), a 2 MiB memory cap per instance, scratch
allocations ≤ 4 KiB, body rewrites ≤ 1 MiB; no file or network access —
only the three host functions. A route referencing a missing plugin file
still reloads and serves (fail-open); a **trap** inside a running plugin
answers 502 (fail-closed — a clean error beats a silently unfiltered
request).

Examples live in `plugins/*.wat` (add_header: header injection; upper_body:
upper-casing the body). Build them with `python plugins/compile_plugins.py`
(requires `pip install wasmtime`); `cargo test` also compiles and exercises
the examples itself via the `wat` dev-dependency.

## Upstream health checks (passive)

The dispatcher feeds every attempt outcome into a health tracker. After N
consecutive failures (3 by default, `JUGGLER_HEALTH_MAX_FAILURES`) an
upstream is marked unhealthy and removed from balancing: dead backends stop
receiving traffic and stop costing a connect timeout on every request.

Recovery is automatic, circuit-breaker style: after a cooldown (5 s by
default, `JUGGLER_HEALTH_COOLDOWN_SECS`) the circuit turns half-open and
exactly one probe request is let through. Success closes the circuit and
returns the upstream to rotation; failure re-opens it immediately. If every
upstream of a route is unhealthy, the request still goes out (to the
least-loaded "suspect") so a route does not die entirely during a shared
outage. Health history of upstreams removed by a hot reload is reset.

The state is exported as `upstream_health_state{upstream,state}`
(healthy / unhealthy / half-open).

## Security & .htaccess compatibility (v0.6.0)

Instead of scattered per-directory `.htaccess` files (the Apache way),
Juggler keeps each route's security policy in the config — validated,
hot-reloadable and auditable as a whole:

```json
"/cms": {
  "upstream_addrs": ["127.0.0.1:9000"],
  "security": {
    "deny_substrings": ["wp-config.php", ".git", ".htaccess"],
    "response_headers": [["X-Frame-Options", "DENY"],
                         ["X-Content-Type-Options", "nosniff"]],
    "cms_fallback_path": "/cms"
  }
}
```

- **deny_substrings** — paths containing any of these substrings
  (case-insensitive) get 403 before any upstream contact. Equivalent of
  `<FilesMatch "^\.(htaccess|git)"> Require all denied`.
- **response_headers** — security headers injected into every response of
  the route (`Header always set …`); existing values are replaced.
- **cms_fallback_path** — WordPress/Laravel/Drupal front controller
  (equivalent of `RewriteRule ^(.*)$ index.php [L]`): unknown paths of the
  route are proxied to its upstreams, the CMS resolves the URI itself.
  Pretty URLs of popular CMSes work without mod_rewrite.

Bridge from existing `.htaccess`: `python htaccess_import.py
/path/to/.htaccess /route > patch.json && python grpc_client.py patch.json`.

Comparison against nginx/Apache/Caddy/OpenLiteSpeed — see `bench/REPORT.md`.

## Limitations & roadmap

- HTTP/2: server-side streams are handled concurrently; upstreams still speak
  HTTP/1.1 only (h2→h1 re-encoding per request). HTTP/2 to upstreams
  (h2c pool) is on the roadmap.
- Keep-alive is capped at 1000 requests per connection (resource-leak guard).
- Failover reacts at the connection level (connect/EOF); 5xx responses from a
  live upstream are not retried (idempotency is the client's concern).
- Rate limiting is per-IP, without honouring `X-Forwarded-For` from trusted
  proxies.
- WASM plugins: filters run on the request phase only (headers/body);
  upstream response filtering, response-phase hooks and an AOT module cache
  are on the roadmap. HTTP/3 (QUIC) and certificates served from the Control
  Plane — also on the roadmap.

## Project layout

```
├── Cargo.toml              # juggler v0.6.0 (tokio, h2, tonic, rustls, prometheus, mimalloc…)
├── build.rs                # .proto compilation via vendored protoc
├── proto/control_plane.proto   # package juggler.control (+SecurityConfig)
├── third_party/protoc/     # vendored protoc 32.1 (win64)
├── src/main.rs             # the whole server: sharded data plane (SO_REUSEPORT,
│                             thread-per-core), control plane, security layer,
│                             WASM pipeline, keep-alive pool, health checks, 45 unit tests
├── routes.json             # route persistence file
├── routes_wasm.json        # example config with wasm_plugin on routes
├── htaccess_import.py      # .htaccess -> Juggler security policy bridge
├── plugins/                # example plugins (.wat + .wasm) and the compiler
├── bench/                  # comparative benchmark vs nginx (+report, HTML)
├── integration_tests.py    # e2e tests (18 scenarios: WASM, health, security)
├── grpc_client.py          # management gRPC client
├── mock_backends.py        # test backends (fast/slow/echo)
├── Dockerfile, docker-compose.yml, prometheus.yml, .gitignore
└── .github/workflows/ci.yml
```
