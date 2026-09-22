#!/usr/bin/env python3
"""Brute-force throttle live test + limiter NAT matrix (compact).

Runs inside bench-attack. Tests:
  B1  60 failed admin auths from attacker IP -> throttled (429) afterwards
  B2  legitimate admin token still works from... same container, so we use
      the documented counter-reset: correct token clears the latch.
  B3  dataplane unaffected during admin brute force (latency + 200s)
  L1  rate limiter semantics: current bench token bucket at several
      concurrency levels (open vs 200/100 retuned via admin) — measures
      burst vs steady RPS and 429 share (per-IP NAT collapse is implied:
      all wrk/attack threads share one source IP inside docker net).

Prints JSON result dict.
"""
import json
import socket
import sys
import time
import urllib.request
import urllib.error

ADMIN = "http://juggler:9090"
JUG = ("juggler", 8080)
TOKEN = "bench"

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

RES = {"ts": time.strftime("%Y-%m-%d %H:%M:%S")}


def admin_get(path, token=TOKEN, timeout=8):
    req = urllib.request.Request(
        f"{ADMIN}{path}", headers={"Authorization": f"Bearer {token}"})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return r.status
    except urllib.error.HTTPError as e:
        return e.code
    except Exception:  # noqa: BLE001
        return -1


def admin_post_json(path, obj, timeout=10):
    req = urllib.request.Request(
        f"{ADMIN}{path}", data=json.dumps(obj).encode(), method="POST",
        headers={"Authorization": f"Bearer {TOKEN}",
                 "Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return r.status
    except urllib.error.HTTPError as e:
        return e.code
    except Exception:  # noqa: BLE001
        return -1


def burst_get(path="/bench", timeout=8):
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


def b1_bruteforce(n=60):
    codes = [admin_get("/api/stats", token=f"wrong{i}") for i in range(n)]
    throttled_429 = codes.count(429)
    # continue hammering: after the latch everything must be 429 fast
    extra = [admin_get("/api/stats", token="wrong") for _ in range(5)]
    return {
        "first_batch": {"401": codes.count(401), "429": throttled_429},
        "latched_all_429": all(c == 429 for c in extra),
    }


def b2_legit_after_bruteforce():
    st = admin_get("/api/stats", token=TOKEN)
    return {"legit_status": st,
            "verdict": "PASS" if st == 200 else "FAIL"}


def b3_dataplane_during_bruteforce(n=40):
    import threading
    stop = threading.Event()
    lat = []

    def poller():
        while not stop.is_set():
            t0 = time.perf_counter()
            burst_get()
            lat.append((time.perf_counter() - t0) * 1000)

    import threading as th
    p = th.Thread(target=poller, daemon=True)
    p.start()
    codes = [admin_get("/api/stats", token=f"bf{i}") for i in range(n)]
    stop.set()
    p.join(timeout=5)
    ok = lat.count
    dataplane_ok = sum(1 for _ in lat)
    p99 = sorted(lat)[int(len(lat) * 0.99) - 1] if lat else 0
    return {"admin_codes_401": codes.count(401), "admin_codes_429": codes.count(429),
            "dataplane_probes": dataplane_ok, "dataplane_p99_ms": round(p99, 1)}


def l1_limiter_matrix():
    out = {}
    for mode, toks, refill in (("off", None, None), ("tight", 200, 100)):
        if toks is None:
            # open: raise limits far beyond traffic
            admin_post_json("/api/settings", {"rate_limit_tokens": 1_000_000,
                                              "rate_limit_refill": 1_000_000})
        else:
            admin_post_json("/api/settings", {"rate_limit_tokens": toks,
                                              "rate_limit_refill": refill})
        time.sleep(0.4)
        # burst: 200 quick requests from one IP (the docker NAT view = 1 client)
        codes = [burst_get() for _ in range(200)]
        ok = codes.count("200")
        r429 = codes.count("429")
        # steady: 60 requests, 20 ms apart
        steady = []
        for _ in range(60):
            steady.append(burst_get())
            time.sleep(0.02)
        sok = steady.count("200")
        out[mode] = {
            "burst_200req": {"ok": ok, "429": r429},
            "steady_60req_20ms": {"ok": sok, "429": steady.count("429")},
        }
    # restore sane defaults for the bench
    admin_post_json("/api/settings", {"rate_limit_tokens": 1_000_000,
                                      "rate_limit_refill": 1_000_000})
    return out


def main():
    print("--- B1 brute force 60 wrong tokens", flush=True)
    RES["B1_bruteforce"] = b1_bruteforce()
    print("--- B2 legit token after brute force", flush=True)
    RES["B2_legit"] = b2_legit_after_bruteforce()
    print("--- B3 dataplane during brute force", flush=True)
    RES["B3_dataplane"] = b3_dataplane_during_bruteforce()
    print("--- L1 limiter matrix", flush=True)
    RES["L1_limiter"] = l1_limiter_matrix()
    print(json.dumps(RES, ensure_ascii=False, indent=1))


if __name__ == "__main__":
    main()
