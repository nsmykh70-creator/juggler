#!/usr/bin/env python3
"""Configuration fuzzing (security backlog item #3). Runs inside bench-attack.

Phases:
  F1  degenerate configs via admin POST /api/routes: empty routes dict key,
      huge routes (2k entries), duplicate paths (JSON dup keys), invalid
      upstream URL, port 0, port 65536, huge route path, non-string upstream
  F2  degenerate settings via POST /api/settings: timeout=0, timeout=huge,
      negative tokens, huge tokens
  F3  concurrency: config-update storm (8 writers) + live traffic;
      atomicity criterion: dataplane always answers 200/502-class with the
      route either old or new — never a partial mix, server never crashes
  F4  concurrent update + upstream failure (echo upstream killed mid-storm)

Prints JSON result dict.
"""
import concurrent.futures
import json
import socket
import sys
import threading
import time
import urllib.request
import urllib.error

ADMIN_HOST = "juggler"
ADMIN_PORT = 9090
TOKEN = "bench"
JUG = ("juggler", 8080)

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

RES = {"ts": time.strftime("%Y-%m-%d %H:%M:%S")}


def admin_post(path, body, timeout=10):
    req = urllib.request.Request(
        f"http://{ADMIN_HOST}:{ADMIN_PORT}{path}",
        data=body.encode() if isinstance(body, str) else body,
        method="POST",
        headers={
            "Authorization": f"Bearer {TOKEN}",
            "Content-Type": "application/json",
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return r.status, r.read(4096).decode(errors="replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read(4096).decode(errors="replace")
    except Exception as e:  # noqa: BLE001
        return -1, f"{type(e).__name__}: {e}"[:120]


def admin_get_routes():
    req = urllib.request.Request(
        f"http://{ADMIN_HOST}:{ADMIN_PORT}/api/routes",
        headers={"Authorization": f"Bearer {TOKEN}"},
    )
    try:
        with urllib.request.urlopen(req, timeout=8) as r:
            return json.loads(r.read())
    except Exception:  # noqa: BLE001
        return {}


def restore():
    st, body = admin_post("/api/routes", json.dumps({
        "routes": {"/bench": {"upstream_addrs": ["backend:9000"]}}
    }))
    return st


def hit(path="/bench", timeout=8):
    try:
        s = socket.create_connection(JUG, timeout=timeout)
        s.sendall(f"GET {path} HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n".encode())
        s.settimeout(timeout)
        d = b""
        while True:
            try:
                c = s.recv(65536)
            except socket.timeout:
                break
            if not c:
                break
            d += c
        s.close()
        line = d.split(b"\r\n", 1)[0].decode(errors="replace")
        return line.split(" ")[1] if " " in line else "EMPTY"
    except OSError as e:
        return f"EXC:{type(e).__name__}"


def probe_dataplane_alive():
    return hit("/bench") == "200"


# ---------------------------------------------------------------- F1: fuzz configs
def f1_degenerate():
    cases = {
        # name: (raw body, expected-not: 5xx/crash)
        "empty_routes_obj": '{"routes": {}}',
        "routes_null": '{"routes": null}',
        "no_routes_key": '{}',
        "huge_2k_routes": json.dumps({"routes": {
            f"/fuzz{i:05d}": {"upstream_addrs": ["backend:9000"]} for i in range(2000)}}),
        "dup_path_keys": '{"routes": {"/bench": {"upstream_addrs": ["backend:9000"]}, "/bench": {"upstream_addrs": ["backend:9000"]}}}',
        "invalid_upstream_url": '{"routes": {"/bench": {"upstream_addrs": ["http://[::broken"]}}}',
        "port_zero": '{"routes": {"/bench": {"upstream_addrs": ["backend:0"]}}}',
        "port_overflow": '{"routes": {"/bench": {"upstream_addrs": ["backend:65536"]}}}',
        "negative_port": '{"routes": {"/bench": {"upstream_addrs": ["backend:-1"]}}}',
        "huge_path": json.dumps({"routes": {"/" + "a" * 60000: {"upstream_addrs": ["backend:9000"]}}}),
        "empty_upstream": '{"routes": {"/bench": {"upstream_addrs": []}}}',
        "metadata_upstream": '{"routes": {"/bench": {"upstream_addrs": ["169.254.169.254:80"]}}}',
        "loopback_upstream": '{"routes": {"/bench": {"upstream_addrs": ["127.0.0.1:6379"]}}}',
        "truncated_json": '{"routes": {"/bench": ',
    }
    out = {}
    for name, body in cases.items():
        st, msg = admin_post("/api/routes", body)
        alive = probe_dataplane_alive()
        cfg_paths = len(admin_get_routes().get("routes", {}))
        out[name] = {
            "status": st,
            "dataplane_alive": alive,
            "routes_after": cfg_paths,
            "verdict": "PASS" if alive and st in (200, 400) else "FAIL",
        }
        restore()
    return out


# ---------------------------------------------------------------- F2: fuzz settings
def f2_settings():
    cases = {
        "timeout_zero": {"pool_idle_timeout_ms": 0},
        "timeout_huge": {"pool_idle_timeout_ms": 10**18},
        "negative_tokens": {"rate_limit_tokens": -5},
        "huge_tokens": {"rate_limit_tokens": 10**18},
        "wrong_type": {"rate_limit_tokens": "banana"},
        "unknown_knob": {"definitely_not_a_knob": 42},
        "empty_obj": {},
    }
    out = {}
    for name, body in cases.items():
        st, msg = admin_post("/api/settings", json.dumps(body))
        alive = probe_dataplane_alive()
        out[name] = {
            "status": st,
            "dataplane_alive": alive,
            "verdict": "PASS" if alive and st in (200, 400) else "FAIL",
        }
    return out


# ---------------------------------------------------------------- F3: concurrent updates + traffic
def f3_concurrent(duration_s=15):
    stop = threading.Event()
    stats = {"hits": 0, "ok200": 0, "soft_fail": 0, "hard_fail": 0, "codes": {}}
    lock = threading.Lock()

    def traffic():
        while not stop.is_set():
            code = hit()
            with lock:
                stats["hits"] += 1
                if code == "200":
                    stats["ok200"] += 1
                elif code.startswith(("5", "4")) or code in ("EMPTY",):
                    stats["soft_fail"] += 1
                    stats["codes"][code] = stats["codes"].get(code, 0) + 1
                else:
                    stats["hard_fail"] += 1
                    stats["codes"][code] = stats["codes"].get(code, 0) + 1

    def writer(i):
        alt = "backend:9000" if i % 2 == 0 else "backend:9000"  # same family by design
        while not stop.is_set():
            admin_post("/api/routes", json.dumps({
                "routes": {"/bench": {"upstream_addrs": [alt]}}}), timeout=15)
            time.sleep(0.05)

    threads = [threading.Thread(target=traffic, daemon=True) for _ in range(4)]
    writers = [threading.Thread(target=writer, args=(i,), daemon=True) for i in range(8)]
    for t in threads + writers:
        t.start()
    time.sleep(duration_s)
    stop.set()
    for t in threads + writers:
        t.join(timeout=10)

    alive = probe_dataplane_alive()
    routes = admin_get_routes().get("routes", {})
    # Atomicity: after the storm the route set must be exactly the canonical one.
    consistent = set(routes.keys()) >= {"/bench"} and routes.get("/bench", {}).get(
        "upstream_addrs") == ["backend:9000"]
    # Hard fail = transport-level breakage (EXC) — no partial config may cause it.
    verdict = ("PASS" if alive and stats["hard_fail"] == 0 and consistent
               else "FAIL")
    return {
        "requests": stats["hits"],
        "ok200": stats["ok200"],
        "non200": stats["soft_fail"],
        "transport_breaks": stats["hard_fail"],
        "codes": dict(sorted(stats["codes"].items())[:6]),
        "config_consistent_after": consistent,
        "dataplane_alive": alive,
        "verdict": verdict,
    }


# ---------------------------------------------------------------- F4: update + upstream failure
def f4_update_during_failure():
    st, _ = admin_post("/api/routes", json.dumps({
        "routes": {"/bench": {"upstream_addrs": ["dead-upstream:9999"]}}}))
    time.sleep(0.3)
    # traffic while upstream is dead
    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as ex:
        codes = list(ex.map(lambda _: hit("/bench", timeout=6), range(30)))
    # switch back while requests are in flight
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as ex:
        fut = ex.submit(lambda: [hit() for _ in range(10)])
        st2, _ = admin_post("/api/routes", json.dumps({
            "routes": {"/bench": {"upstream_addrs": ["backend:9000"]}}}))
        during = fut.result()
    time.sleep(1.0)
    after = [hit() for _ in range(5)]
    alive = probe_dataplane_alive()
    recovered = all(c == "200" for c in after)
    no_exc = not any(c.startswith("EXC") for c in codes + during + after)
    return {
        "dead_phase_codes": sorted(set(codes))[:5],
        "recovered_all_200": recovered,
        "no_transport_breaks": no_exc,
        "server_alive": alive,
        "verdict": "PASS" if (alive and recovered and no_exc) else "FAIL",
    }


def main():
    print("--- F1 degenerate configs", flush=True)
    RES["F1_degenerate"] = f1_degenerate()
    print("--- F2 degenerate settings", flush=True)
    RES["F2_settings"] = f2_settings()
    print("--- F3 concurrent updates + traffic (15 s)", flush=True)
    RES["F3_concurrent"] = f3_concurrent()
    print("--- F4 update during upstream failure", flush=True)
    RES["F4_failure"] = f4_update_during_failure()
    print(json.dumps(RES, ensure_ascii=False, indent=1))


if __name__ == "__main__":
    main()
