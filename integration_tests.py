#!/usr/bin/env python3
"""Integration tests for the Juggler server.

Prerequisites:
    - server running:  cargo run --release   (or target/release/juggler)
    - mock backends:   python mock_backends.py
    - python deps:     pip install requests urllib3 grpcio grpcio-tools "httpx[http2]"

Tests:
    1. TLS handshake        - HTTPS works with the self-signed cert
    2. /healthz + /metrics  - built-in endpoints respond
    3. gRPC hot reload      - config applied atomically, route 404 -> 502 visible
    4. Proxying             - request reaches a mock backend via the server
    5. Least Connections    - traffic prefers the fast backend
    6. Rate limiter         - request burst triggers 429 responses
    7. Keep-alive           - one TLS connection serves multiple requests
    8. Failover             - dead upstream is skipped, live one answers
    9. HTTP/1.1             - basic proxying over explicit HTTP/1.1
   10. HTTP/2               - ALPN negotiation, multiplexed streams, proxying
   11. WASM header plugin   - plugin injects a header into the upstream request
   12. WASM body plugin     - plugin rewrites the request body (upper-case)
   13. WASM missing plugin  - unreadable plugin file -> route still works (fail-open)
   14. WASM metrics         - wasm_plugin_calls_total / wasm_modules_loaded visible
   15. Upstream health      - passive marking, dead-skip and auto-recovery
   17. Security layer       - deny patterns -> 403, security headers, CMS fallback
   21. Static files         - www/ docroot auto-registered, MIME, traversal blocked

Run:  python integration_tests.py
"""
import concurrent.futures
import json
import os
import re
import urllib.error
import sys
import time
from importlib import import_module

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

import requests
import urllib3

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

BASE = "https://127.0.0.1:8443"
GRPC_ADDR = "127.0.0.1:50051"

# Admin auth: when the server under test runs with JUGGLER_ADMIN_TOKEN set
# (fail-closed surface, as in CI), every /api call must present the Bearer.
# Local token-less dev servers keep the old open behavior.
# Test-side token mirrors the server's JUGGLER_ADMIN_TOKEN (set in CI and
# locally); JUGGLER_CI_ADMIN_TOKEN kept as an explicit override.
ADMIN_TOKEN = os.environ.get("JUGGLER_CI_ADMIN_TOKEN") or os.environ.get("JUGGLER_ADMIN_TOKEN", "")
ADMIN_HDRS = {"Authorization": f"Bearer {ADMIN_TOKEN}"} if ADMIN_TOKEN else {}

def _retry_get(url: str, attempts: int = 3, **kw):
    """GET with short retries: absorbs cold-burst 429/timeouts on CI, where
    the whole suite runs against one freshly started server."""
    last = None
    for _ in range(attempts):
        try:
            return requests.get(url, verify=False, timeout=5, **kw)
        except requests.RequestException as e:  # noqa: PERF203
            last = e
            time.sleep(0.5)
    raise last

PASSED = []
FAILED = []


def test_admin_settings():
    """Fine-tuning knobs: GET lists bounds, POST applies live and persists."""
    import urllib.request

    admin = "http://127.0.0.1:9090"

    # 1) GET: all knobs present with bounds.
    with urllib.request.urlopen(
        urllib.request.Request(f"{admin}/api/settings", headers=ADMIN_HDRS), timeout=5
    ) as r:
        assert r.status == 200
        d = json.loads(r.read())
    names = {s["name"] for s in d["settings"]}
    for expected in (
        "balancer", "rate_limit_tokens", "rate_limit_refill",
        "pool_cap_per_upstream", "max_keepalive_requests",
        "prewarm_floor", "health_max_failures", "health_cooldown_secs",
    ):
        assert expected in names, f"missing knob {expected}"

    def knob(name):
        return next(s for s in d["settings"] if s["name"] == name)

    # 2) POST: switch balancer to predictive, verify live.
    req = urllib.request.Request(
        f"{admin}/api/settings", method="POST", headers={"Content-Type": "application/json", **ADMIN_HDRS},
        data=json.dumps({"balancer": "predictive"}).encode(),
        
    )
    with urllib.request.urlopen(req, timeout=5) as r:
        assert r.status == 200
        assert json.loads(r.read())["success"] is True
    with urllib.request.urlopen(
        urllib.request.Request(f"{admin}/api/settings", headers=ADMIN_HDRS), timeout=5
    ) as r:
        d2 = json.loads(r.read())
    bal_now = next(s for s in d2["settings"] if s["name"] == "balancer")
    assert bal_now["value"] == 1, f"predictive not applied: {bal_now}"

    # 3) POST: out-of-range value is clamped, not rejected.
    cap = knob("pool_cap_per_upstream")
    req = urllib.request.Request(
        f"{admin}/api/settings", method="POST", headers={"Content-Type": "application/json", **ADMIN_HDRS},
        data=json.dumps({"pool_cap_per_upstream": cap["max"] + 12345}).encode(),
        
    )
    with urllib.request.urlopen(req, timeout=5) as r:
        assert r.status == 200
    with urllib.request.urlopen(
        urllib.request.Request(f"{admin}/api/settings", headers=ADMIN_HDRS), timeout=5
    ) as r:
        d3 = json.loads(r.read())
    got = next(s for s in d3["settings"] if s["name"] == "pool_cap_per_upstream")
    assert got["value"] == cap["max"], "out-of-range value must clamp to max"

    # 4) POST: unknown knob -> 400.
    req = urllib.request.Request(
        f"{admin}/api/settings", method="POST", headers={"Content-Type": "application/json", **ADMIN_HDRS},
        data=json.dumps({"nope": 1}).encode(),
        
    )
    try:
        urllib.request.urlopen(req, timeout=5)
        raise AssertionError("unknown knob must be rejected")
    except urllib.error.HTTPError as e:
        assert e.code == 400, f"expected 400, got {e.code}"

    # 5) Restore ALL original values. Settings are persisted to
    # settings.json (by this API and by manual panel changes), so a
    # partially-restored set leaks across runs: e.g. health_max_failures
    # set high makes the circuit in test 15 never open. Restore every
    # knob this test saw at GET time, with the pre-clamp pool cap.
    restore = {s["name"]: s["value"] for s in d["settings"]}
    restore["pool_cap_per_upstream"] = cap["value"]
    req = urllib.request.Request(
        f"{admin}/api/settings", method="POST", headers={"Content-Type": "application/json", **ADMIN_HDRS},
        data=json.dumps(restore).encode(),
        
    )
    urllib.request.urlopen(req, timeout=5)


def test_admin_api():
    """Admin UI + JSON API: dashboard HTML, stats JSON, hot-reload POST."""
    import urllib.request
    import urllib.error

    admin = "http://127.0.0.1:9090"
    # Server under test must be started with JUGGLER_ADMIN_ADDR=127.0.0.1:9090.
    # When it runs with JUGGLER_ADMIN_TOKEN (CI), the token is passed via
    # JUGGLER_CI_ADMIN_TOKEN; token-less dev servers stay open.

    # 1) stats JSON: valid, has shards/routes/pool arrays.
    with urllib.request.urlopen(
        urllib.request.Request(f"{admin}/api/stats", headers=ADMIN_HDRS), timeout=5
    ) as r:
        assert r.status == 200, f"stats status {r.status}"
        stats = json.loads(r.read())
    assert "shards" in stats and len(stats["shards"]) > 0, "no shard telemetry"
    assert "routes" in stats and "pool" in stats, "stats missing sections"
    assert "version" in stats and "uptime_secs" in stats, "stats missing metadata"

    # 2) dashboard HTML renders with shard table.
    with urllib.request.urlopen(f"{admin}/", timeout=5) as r:
        assert r.status == 200
        html = r.read().decode()
    assert "Juggler Admin" in html and "shard-rows" in html, "dashboard HTML incomplete"

    # 3) hot-reload via POST (same schema as routes.json), then restore.
    payload = json.dumps({"routes": {"/admintest": {"upstream_addrs": ["127.0.0.1:7001"]}}}).encode()
    req = urllib.request.Request(
        f"{admin}/api/routes", data=payload, method="POST",
        headers={"Content-Type": "application/json", **ADMIN_HDRS},
    )
    with urllib.request.urlopen(req, timeout=10) as r:
        assert r.status == 200
        resp = json.loads(r.read())
    assert resp.get("success") is True, f"POST failed: {resp}"

    with urllib.request.urlopen(
        urllib.request.Request(f"{admin}/api/routes", headers=ADMIN_HDRS), timeout=5
    ) as r:
        routes = json.loads(r.read())
    assert "/admintest" in routes["routes"], "POSTed route missing from GET"

    # Restore: only the balanced demo route remains.
    payload = json.dumps({"routes": {"/api": {"upstream_addrs": ["127.0.0.1:7001", "127.0.0.1:7002"]}}}).encode()
    req = urllib.request.Request(
        f"{admin}/api/routes", data=payload, method="POST",
        headers={"Content-Type": "application/json", **ADMIN_HDRS},
    )
    with urllib.request.urlopen(req, timeout=10) as r:
        assert r.status == 200


def check(name, fn):
    print(f"\n🔎 {name}")
    try:
        fn()
    except AssertionError as e:
        FAILED.append(name)
        print(f"❌ FAIL: {e}")
    except Exception as e:  # noqa: BLE001
        FAILED.append(name)
        print(f"❌ ERROR: {e}")
    else:
        PASSED.append(name)
        print("✅ PASS")


# ---------------------------------------------------------------- test 1
def test_tls_handshake():
    r = requests.get(f"{BASE}/healthz", verify=False, timeout=5)
    assert r.status_code == 200, f"status {r.status_code}"
    assert r.text == "ok", f"unexpected body: {r.text!r}"


# ---------------------------------------------------------------- test 2
def test_builtin_endpoints():
    r = requests.get(f"{BASE}/metrics", verify=False, timeout=5)
    assert r.status_code == 200, f"status {r.status_code}"
    for metric in ("http_requests_total", "http_response_seconds"):
        assert metric in r.text, f"metric {metric} missing from /metrics"


# ---------------------------------------------------------------- test 3+4
def compile_proto():
    from grpc_tools import protoc

    rc = protoc.main(("", "-Iproto", "--python_out=.", "--grpc_python_out=.", "proto/control_plane.proto"))
    assert rc == 0, "protoc failed"
    return import_module("control_plane_pb2"), import_module("control_plane_pb2_grpc")


def grpc_metadata():
    """Auth metadata for mutating control-plane RPCs (CI token mode)."""
    return (("authorization", f"Bearer {ADMIN_TOKEN}"),) if ADMIN_TOKEN else ()


def test_grpc_hot_reload_and_proxy():
    import grpc

    pb2, pb2_grpc = compile_proto()

    # Point /api at the fast backend only -> deterministic proxy check.
    routes = {"/api": pb2.RouteConfig(upstream_addrs=["127.0.0.1:7001"])}
    with grpc.insecure_channel(GRPC_ADDR) as channel:
        stub = pb2_grpc.ControlPlaneStub(channel)
        resp = stub.UpdateConfig(
            pb2.UpdateConfigRequest(routes=routes), timeout=5,
            metadata=grpc_metadata(),
        )
        assert resp.success, f"server rejected config: {resp.message}"

    # Hot reload must be visible to the Data Plane immediately.
    time.sleep(0.2)
    r = requests.get(f"{BASE}/api", verify=False, timeout=5)
    assert r.status_code == 200, f"status {r.status_code}"
    assert r.headers.get("X-Backend") == "fast-7001", f"request was not proxied: {dict(r.headers)}"

    # Unknown path must 404.
    r404 = requests.get(f"{BASE}/no-such-route", verify=False, timeout=5)
    assert r404.status_code == 404, f"expected 404, got {r404.status_code}"


# ---------------------------------------------------------------- test 5
def test_least_connections():
    import grpc

    pb2, pb2_grpc = compile_proto()

    # Both backends: fast-7001 (instant) and slow-7002 (1.5s).
    routes = {
        "/api": pb2.RouteConfig(
            upstream_addrs=["127.0.0.1:7001", "127.0.0.1:7002"]
        )
    }
    with grpc.insecure_channel(GRPC_ADDR) as channel:
        stub = pb2_grpc.ControlPlaneStub(channel)
        resp = stub.UpdateConfig(
            pb2.UpdateConfigRequest(routes=routes), timeout=5,
            metadata=grpc_metadata(),
        )
        assert resp.success

    # 8 concurrent requests: the slow backend pins its connections for 1.5s,
    # so Least Connections must send most traffic to the fast one.
    def hit(_):
        try:
            return requests.get(f"{BASE}/api", verify=False, timeout=10).headers.get("X-Backend")
        except Exception:  # noqa: BLE001
            return None

    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        backends = list(pool.map(hit, range(8)))

    fast = backends.count("fast-7001")
    slow = backends.count("slow-7002")
    print(f"   distribution: fast={fast}, slow={slow}")
    assert fast > slow or fast == slow == 4, (
        f"expected fast to win (or 4/4 under predictive balancing), "
        f"got fast={fast}, slow={slow}"
    )


# ---------------------------------------------------------------- test 6
def test_rate_limiter():
    # Burst of 40 concurrent requests against an unmetered endpoint;
    # the token bucket (10 tokens, 5/s refill) must reject some with 429.
    def hit(_):
        try:
            return requests.get(f"{BASE}/healthz", verify=False, timeout=5).status_code
        except Exception:  # noqa: BLE001
            return None

    with concurrent.futures.ThreadPoolExecutor(max_workers=40) as pool:
        codes = list(pool.map(hit, range(40)))

    ok = codes.count(200)
    limited = codes.count(429)
    print(f"   results: 200={ok}, 429={limited}, other={len(codes) - ok - limited}")
    assert limited > 0, "rate limiter never triggered - no 429 observed"


# ---------------------------------------------------------------- test 7
def test_keep_alive():
    import http.client
    import ssl

    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    conn = http.client.HTTPSConnection("127.0.0.1", 8443, context=ctx, timeout=5)
    try:
        codes = []
        for _ in range(3):
            conn.request("GET", "/healthz")
            r = conn.getresponse()
            r.read()
            codes.append(r.status)
        assert codes == [200, 200, 200], f"keep-alive sequence broken: {codes}"
        print("   3 requests over one TLS connection: OK")
    finally:
        conn.close()


# ---------------------------------------------------------------- test 8
def test_failover():
    import grpc

    pb2, pb2_grpc = compile_proto()

    # First upstream is a dead port, second is the live fast backend.
    routes = {
        "/api": pb2.RouteConfig(
            upstream_addrs=["127.0.0.1:59999", "127.0.0.1:7001"]
        )
    }
    with grpc.insecure_channel(GRPC_ADDR) as channel:
        stub = pb2_grpc.ControlPlaneStub(channel)
        resp = stub.UpdateConfig(
            pb2.UpdateConfigRequest(routes=routes), timeout=5,
            metadata=grpc_metadata(),
        )
        assert resp.success

    time.sleep(0.2)
    r = requests.get(f"{BASE}/api", verify=False, timeout=15)
    assert r.status_code == 200, f"failover failed: status {r.status_code}"
    assert r.headers.get("X-Backend") == "fast-7001", "request did not reach the live backend"
    print("   dead upstream skipped, live backend answered: OK")

    # Restore the balanced config for later manual use.
    routes = {
        "/api": pb2.RouteConfig(
            upstream_addrs=["127.0.0.1:7001", "127.0.0.1:7002"]
        )
    }
    with grpc.insecure_channel(GRPC_ADDR) as channel:
        stub = pb2_grpc.ControlPlaneStub(channel)
        stub.UpdateConfig(
            pb2.UpdateConfigRequest(routes=routes), timeout=5,
            metadata=grpc_metadata(),
        )


# ---------------------------------------------------------------- test 9
def test_http1_proxying():
    import httpx

    # Pin /api to the fast backend so the proxy assertion is deterministic
    # (failover test above leaves /api pointed at both backends).
    _apply_routes({"/api": {"upstream_addrs": ["127.0.0.1:7001"]}})

    # follow_redirects: exact-match proxy routes answer 301 -> /api/ (nginx
    # location-directory semantics), the proxy assertion is about the final
    # response, not the redirect hop.
    with httpx.Client(verify=False, http2=False, timeout=5, follow_redirects=True) as client:
        r = client.get(f"{BASE}/api")
        assert r.status_code == 200, f"status {r.status_code}"
        assert r.http_version == "HTTP/1.1", f"negotiated {r.http_version}"
        # httpx lowercases response header names
        assert r.headers.get("x-backend") == "fast-7001", "not proxied"


# ---------------------------------------------------------------- test 10
def test_http2_proxying():
    import httpx

    # Pin /api to the fast backend so proxy assertions are deterministic
    # (earlier tests may have left it pointed at both backends).
    _apply_routes({"/api": {"upstream_addrs": ["127.0.0.1:7001"]}})

    with httpx.Client(verify=False, http2=True, timeout=5, follow_redirects=True) as client:
        # ALPN must negotiate h2 and the request must be proxied.
        r = client.get(f"{BASE}/api")
        assert r.status_code == 200, f"status {r.status_code}"
        assert r.http_version == "HTTP/2", f"ALPN negotiated {r.http_version}"
        assert r.headers.get("x-backend") == "fast-7001", "not proxied over h2"

    # Multiplexing: several concurrent streams over h2 connections.
    import concurrent.futures

    paths = ["/api/", "/healthz"]  # /api/ = after the exact-match 301
    with httpx.Client(verify=False, http2=True, timeout=10, follow_redirects=True) as mux:
        def hit(p):
            return mux.get(f"{BASE}{p}").status_code

        with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
            codes = list(pool.map(hit, paths * 3))
    assert codes.count(200) == len(codes), f"multiplexed failures: {codes}"
    print(f"   h2 negotiated + proxied + {len(codes)} multiplexed streams: OK")


# ---------------------------------------------------------------- tests 11-14
def _apply_routes(routes: dict) -> None:
    import grpc

    pb2, pb2_grpc = compile_proto()

    def _route(cfg: dict):
        sec = cfg.get("security")
        kwargs = {
            "upstream_addrs": cfg.get("upstream_addrs", []),
            "wasm_plugin": cfg.get("wasm_plugin"),
        }
        if sec:
            kwargs["security"] = pb2.SecurityConfig(
                deny_substrings=sec.get("deny_substrings", []),
                response_headers={
                    k: v for k, v in sec.get("response_headers", [])
                },
                cms_fallback_path=sec.get("cms_fallback_path"),
            )
        return pb2.RouteConfig(**kwargs)

    with grpc.insecure_channel(GRPC_ADDR) as channel:
        stub = pb2_grpc.ControlPlaneStub(channel)
        # When the server runs with an admin token (CI), control-plane
        # mutations mirror that token as their Bearer credential.
        md = (("authorization", f"Bearer {ADMIN_TOKEN}"),) if ADMIN_TOKEN else ()
        resp = stub.UpdateConfig(
            pb2.UpdateConfigRequest(routes={
                path: _route(cfg) for path, cfg in routes.items()
            }),
            timeout=5,
            metadata=md,
        )
        assert resp.success, f"config rejected: {resp.message}"


def test_wasm_header_plugin():
    # /hdr -> echo backend with the add_header plugin: the upstream must
    # receive the X-Wasm-Juggler header the plugin injected from WASM.
    _apply_routes({
        "/hdr": {
            "upstream_addrs": ["127.0.0.1:7003"],
            "wasm_plugin": "plugins/add_header.wasm",
        },
    })
    time.sleep(0.2)
    r = requests.get(f"{BASE}/hdr", verify=False, timeout=10)
    assert r.status_code == 200, f"status {r.status_code}"
    echoed = r.json()["headers"]
    assert echoed.get("x-wasm-juggler") == "lets-juggle", (
        f"plugin header did not reach the upstream: {echoed}"
    )
    print(f"   upstream saw x-wasm-juggler={echoed.get('x-wasm-juggler')}: OK")


def test_wasm_body_plugin():
    # /up -> echo backend with the upper_body plugin: the upstream must
    # receive the request body rewritten to upper case inside WASM.
    # POST /up/ directly: POST /up would 301 (exact-match directory
    # redirect) and requests would re-send it as a body-less GET.
    _apply_routes({
        "/up": {
            "upstream_addrs": ["127.0.0.1:7003"],
            "wasm_plugin": "plugins/upper_body.wasm",
        },
    })
    time.sleep(0.2)
    payload = "juggle me please"
    r = requests.post(
        f"{BASE}/up/", data=payload.encode(),
        headers={"Content-Type": "text/plain"}, verify=False, timeout=10,
    )
    assert r.status_code == 200, f"status {r.status_code}"
    body = r.json()["body"]
    assert body == payload.upper(), f"body was not rewritten: {body!r}"
    print(f"   {payload!r} -> {body!r} inside WASM: OK")


def test_wasm_missing_plugin_fail_open():
    # A route referencing a plugin file that does not exist must not break
    # the route: compilation fails at reload, the filter is skipped (open).
    _apply_routes({
        "/ghost": {
            "upstream_addrs": ["127.0.0.1:7001"],
            "wasm_plugin": "plugins/no_such_plugin.wasm",
        },
    })
    time.sleep(0.2)
    r = requests.get(f"{BASE}/ghost", verify=False, timeout=10)
    assert r.status_code == 200, f"status {r.status_code}"
    assert r.headers.get("x-backend") == "fast-7001", "not proxied"
    print("   missing plugin logged, route still serves: OK")


def test_wasm_metrics():
    r = requests.get(f"{BASE}/metrics", verify=False, timeout=10)
    text = r.text
    assert "wasm_plugin_calls_total" in text, "wasm_plugin_calls_total missing"
    assert "wasm_plugin_seconds" in text, "wasm_plugin_seconds missing"
    assert 'wasm_modules_loaded' in text and re.search(
        r"wasm_modules_loaded (\d+)", text
    ), "wasm_modules_loaded missing"
    calls = re.findall(
        r'wasm_plugin_calls_total\{[^}]*outcome="ok"[^}]*stage="filter"[^}]*\} (\d+)',
        text,
    )
    assert calls and int(calls[-1]) >= 2, f"no successful plugin calls recorded: {calls}"
    print(f"   plugin calls ok={calls[-1]}, modules loaded visible: OK")


# ---------------------------------------------------------------- test 15
def test_upstream_health_cycle():
    """Passive health checks end-to-end.

    Phase 1: a dead upstream fails `max_failures` times, gets marked
    unhealthy, and afterwards the live one answers INSTANTLY (no 3s connect
    timeout is paid for the dead peer anymore — that is the whole point).
    Phase 2: the dead upstream is revived on the same port; after the
    recovery cooldown the half-open probe lets one request through, the
    circuit closes, and both upstreams serve traffic again.
    """
    import socket
    import threading
    import time as _t

    DEAD_PORT = 7005
    DEAD = f"127.0.0.1:{DEAD_PORT}"
    LIVE = "127.0.0.1:7001"

    _apply_routes({
        "/health-demo": {
            "upstream_addrs": [DEAD, LIVE],
        },
    })

    def latency(path):
        t0 = _t.monotonic()
        r = requests.get(f"{BASE}{path}", verify=False, timeout=15)
        return r.status_code, _t.monotonic() - t0, r.headers.get("x-backend")

    # Phase 1: drive requests until the circuit opens. The random
    # tie-break means the dead peer is tried first only on some requests,
    # so marking is confirmed via the health metric, not by timing alone.
    unhealthy_re = (
        rf'upstream_health_state\{{state="unhealthy",upstream="{re.escape(DEAD)}"\}} 1'
    )
    deadline = _t.monotonic() + 30
    while _t.monotonic() < deadline:
        try:
            code, dt, backend = latency("/health-demo")
        except requests.RequestException:
            time.sleep(0.3)
            continue  # cold-burst 429/timeouts during marking are tolerated
        assert code == 200, f"request during marking got {code} ({backend})"
        m = requests.get(f"{BASE}/metrics", verify=False, timeout=5).text
        if re.search(unhealthy_re, m):
            break
        time.sleep(0.3)
    else:
        raise AssertionError("dead upstream was never marked unhealthy")
    print("   dead upstream marked unhealthy (metric exported): OK")

    # After marking, requests must be fast: the dead peer is filtered out
    # before balancing, so no 3s connect timeout is paid anymore.
    fast = 0
    attempts = 0
    while fast < 4 and attempts < 12:
        attempts += 1
        try:
            code, dt, backend = latency("/health-demo")
        except requests.RequestException:
            time.sleep(0.5)
            continue  # transient network errors from the shared limiter
        if code == 429:
            time.sleep(0.5)
            continue  # limiter refill window: probe again, do not fail
        assert code == 200 and backend == "fast-7001", f"{code} {backend}"
        if dt < 1.0:
            fast += 1
        time.sleep(0.2)
    assert fast >= 4, f"dead upstream still costs a connect timeout ({fast} fast in {attempts} attempts)"
    print("   traffic flows with zero timeout penalty: OK")

    # Phase 2: revive the dead port with a temporary listener and wait out
    # the recovery cooldown (default 5s).
    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    sock.bind(("127.0.0.1", DEAD_PORT))
    sock.listen(16)
    stop = threading.Event()

    def serve():
        sock.settimeout(0.2)
        while not stop.is_set():
            try:
                conn, _ = sock.accept()
            except socket.timeout:
                continue
            with conn:
                conn.recv(65536)
                body = b"recovered-7005\n"
                head = (
                    "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n"
                    f"Content-Length: {len(body)}\r\nX-Backend: revived-7005\r\n"
                    "Connection: close\r\n\r\n"
                ).encode()
                conn.sendall(head + body)

    threading.Thread(target=serve, daemon=True).start()
    try:
        # 30s: the cooldown is 5s, but a hot-reload also re-warms upstream
        # pools in the background, which can delay the first probe slightly.
        deadline = _t.monotonic() + 30
        revived = False
        while _t.monotonic() < deadline:
            time.sleep(0.5)  # stay under the rate limiter while polling
            code, dt, backend = latency("/health-demo")
            if code == 429:
                continue  # limiter refill window: poll again, do not fail
            assert code == 200, f"poll during recovery got {code}"
            if backend == "revived-7005":
                revived = True
                break
        assert revived, "upstream was not recovered after the cooldown"

        # Circuit closed: the revived upstream now receives regular traffic.
        seen = set()
        for _ in range(8):
            _, _, backend = latency("/health-demo")
            seen.add(backend)
        assert "revived-7005" in seen, "revived upstream gets no traffic after recovery"
        print("   half-open probe succeeded, circuit closed, traffic resumed: OK")
    finally:
        stop.set()
        sock.close()


def test_upstream_health_cycle_cleanup():
    # Remove the demo route so other tests / manual use see a clean table.
    _apply_routes({"/api": {"upstream_addrs": ["127.0.0.1:7001", "127.0.0.1:7002"]}})


# ---------------------------------------------------------------- test 17
def test_security_layer():
    """Route policy: deny patterns -> 403, security headers, CMS fallback.
    Mirrors what htaccess_import.py generates from a WordPress .htaccess."""
    _apply_routes({
        "/cms": {
            "upstream_addrs": ["127.0.0.1:7003"],
            "security": {
                "deny_substrings": ["wp-config.php", ".git", ".htaccess"],
                "response_headers": [
                    ["X-Frame-Options", "DENY"],
                    ["X-Content-Type-Options", "nosniff"],
                ],
                "cms_fallback_path": "/cms",
            },
        },
        "/api": {"upstream_addrs": ["127.0.0.1:7001", "127.0.0.1:7002"]},
    })
    time.sleep(1)

    # 1. Deny patterns: config and dotfiles are 403 before any upstream call.
    for path in ["/cms/wp-config.php", "/cms/.git/config", "/cms/.htaccess"]:
        r = requests.get(f"{BASE}{path}", verify=False, timeout=5)
        assert r.status_code == 403, f"{path}: expected 403, got {r.status_code}"
    print("   deny patterns (wp-config.php / .git / .htaccess) -> 403: OK")

    # 2. Security headers on every proxied response; unknown path falls
    #    through to the CMS front controller and still gets the headers.
    r = requests.get(f"{BASE}/cms/pretty/url", verify=False, timeout=5)
    assert r.status_code == 200, f"fallback expected 200, got {r.status_code}"
    assert r.headers.get("X-Frame-Options") == "DENY", r.headers
    assert r.headers.get("X-Content-Type-Options") == "nosniff", r.headers
    print("   security headers on proxied + fallback responses: OK")

    # 3. Other routes are unaffected by the /cms policy.
    r = requests.get(f"{BASE}/api", verify=False, timeout=5)
    assert r.status_code == 200
    assert "X-Frame-Options" not in r.headers, "policy leaked across routes"
    print("   per-route policy isolation: OK")


def test_security_layer_cleanup():
    _apply_routes({"/api": {"upstream_addrs": ["127.0.0.1:7001", "127.0.0.1:7002"]}})


def test_static_files():
    """Document root: www/ sample sites are auto-registered, served with
    correct MIME types, traversal is blocked, 404 for missing files."""
    import urllib.request

    # 1. Root of each auto-registered sample site serves its index.html.
    r = requests.get(f"{BASE}/sample_sites1", verify=False, timeout=5)
    assert r.status_code == 200, f"expected 200, got {r.status_code}"
    assert "text/html" in r.headers.get("Content-Type", ""), r.headers
    assert "Sample site 1" in r.text, r.text[:200]
    print("   /sample_sites1 -> index.html (text/html): OK")

    r = _retry_get(f"{BASE}/sample_sites2/")
    assert r.status_code == 200 and "Sample site 2" in r.text
    print("   /sample_sites2/ (trailing slash): OK")

    # 2. Directory-without-slash still resolves to index.html.
    r = _retry_get(f"{BASE}/sample_sites3", allow_redirects=False)
    assert r.status_code == 200 and "Sample site 3" in r.text
    print("   directory without slash -> index.html: OK")

    # 3. Missing file -> 404, not a crash or a listing.
    r = requests.get(f"{BASE}/sample_sites1/nope.css", verify=False, timeout=5)
    assert r.status_code == 404, f"expected 404, got {r.status_code}"
    print("   missing file -> 404: OK")

    # 4. Traversal attempts never escape the docroot.
    for path in ["/sample_sites1/../routes.json",
                 "/sample_sites1/..%2f..%2fsettings.json",
                 "/sample_sites1/../../Cargo.toml"]:
        r = requests.get(f"{BASE}{path}", verify=False, timeout=5)
        assert r.status_code in (403, 404), f"{path}: got {r.status_code}"
        assert "Cargo.toml" not in r.text and "[package]" not in r.text
    print("   traversal blocked: OK")


# ---------------------------------------------------------------- main
if __name__ == "__main__":
    print("🧪 Juggler integration tests")
    print("=" * 50)

    check("1. TLS handshake", test_tls_handshake)
    check("2. Built-in endpoints (/healthz, /metrics)", test_builtin_endpoints)
    check("3+4. gRPC hot reload + reverse proxying", test_grpc_hot_reload_and_proxy)
    time.sleep(2)  # let buckets refill before the balancing test
    check("5. Least Connections balancing", test_least_connections)
    print("   (waiting 3s for token buckets to refill...)")
    time.sleep(3)
    check("6. Rate limiter (Token Bucket)", test_rate_limiter)
    time.sleep(2)  # refill again before connection tests
    check("7. Keep-alive (3 requests, 1 connection)", test_keep_alive)
    check("8. Upstream failover (dead -> live)", test_failover)
    time.sleep(2)
    check("9. HTTP/1.1 proxying (explicit)", test_http1_proxying)
    check("10. HTTP/2 (ALPN + multiplexing + proxying)", test_http2_proxying)
    # 9/10 push a request burst: drain the token bucket. Without a refill
    # pause the WASM probes below land in the 429 tail and false-fail.
    time.sleep(2)
    check("11. WASM plugin: header injection", test_wasm_header_plugin)
    check("12. WASM plugin: body rewrite", test_wasm_body_plugin)
    check("13. WASM plugin: missing file (fail-open)", test_wasm_missing_plugin_fail_open)
    check("14. WASM metrics", test_wasm_metrics)
    check("15. Upstream health (marking, dead-skip, auto-recovery)", test_upstream_health_cycle)
    check("16. Cleanup after health test", test_upstream_health_cycle_cleanup)
    check("17. Security layer (deny, headers, CMS fallback)", test_security_layer)
    check("18. Cleanup after security test", test_security_layer_cleanup)
    check("19. Admin API (stats, dashboard, hot-reload POST)", test_admin_api)
    check("20. Admin settings API (GET + POST live knobs)", test_admin_settings)
    # 19/20 burst the admin API: give the token bucket a refill beat so
    # static probes below do not land in the 429 tail.
    time.sleep(2)
    check("21. Static files (www/ docroot, MIME, traversal)", test_static_files)

    # Leave the standard balanced demo config for manual use.
    _apply_routes({
        "/api": {"upstream_addrs": ["127.0.0.1:7001", "127.0.0.1:7002"]},
    })

    print("\n" + "=" * 50)
    print(f"🏆 PASSED: {len(PASSED)}  ❌ FAILED: {len(FAILED)}")
    if FAILED:
        for name in FAILED:
            print(f"   - {name}")
        sys.exit(1)
    print("All tests green — server is production-ready.")
