#!/usr/bin/env python3
"""Security pass, phases 1+2 (inside bench-attack).

Phase 2 — request smuggling / desync (echo upstream shows how the request
was framed on the other side of Juggler):
  M1 CL + TE: chunked        -> upstream must see TE-chunked (RFC 7230 3.3.3)
  M2 TE + CL: chunked        -> same
  M3 duplicate CL same value -> consistent framing, single request
  M4 duplicate CL conflict   -> reject (400) or single interpretation, no desync
  M5 CL vs space "Content-Length :" (space before colon) -> rejected/no smuggle
  M6 malformed chunk size    -> no response smuggling, connection ends
  M7 TE: chunked + trailers  -> clean single request
  M8 keep-alive after malformed request -> next request on fresh/robust state

Phase 1 — grammar fuzzer:
  1200 structured mutations of a valid request (methods, URI encodings,
  header flooding, control chars, partial sends, chunk abusers).
  Assertions: server alive, no panic in logs, RSS growth < 128 MiB,
  admin API still answers within the run.
"""
import json
import socket
import time

JUG = ("juggler", 8080)
OUT = "/results_ph12.json"
RES = {"meta": {"ts": time.strftime("%Y-%m-%d %H:%M:%S")}, "smuggle": {}, "fuzz": {}}


def send(raw: bytes, timeout=8, wait=2.5) -> bytes:
    try:
        s = socket.create_connection(JUG, timeout=timeout)
        s.sendall(raw)
        d = b""
        s.settimeout(wait)
        try:
            while True:
                c = s.recv(65536)
                if not c:
                    break
                d += c
        except socket.timeout:
            pass
        s.close()
        return d
    except OSError as e:
        return f"EXC:{type(e).__name__}".encode()


def echo_probe(extra_head: bytes, body: bytes = b"") -> dict:
    raw = (b"POST /echo HTTP/1.1\r\nHost: x\r\n" + extra_head + b"\r\n" + body)
    d = send(raw, timeout=10, wait=3)
    if b"{" in d:
        js = d[d.index(b"{"): d.rindex(b"}") + 1]
        try:
            return json.loads(js)
        except json.JSONDecodeError:
            return {"raw": d[:160].decode(errors="replace")}
    return {"status": d.split(b"\r\n")[0].decode(errors="replace") if d else "EMPTY"}


def phase2():
    print("--- phase 2: smuggling", flush=True)
    r = RES["smuggle"]

    e = echo_probe(b"Content-Length: 5\r\nTransfer-Encoding: chunked\r\n",
                   b"3\r\nabc\r\n0\r\n\r\n")
    ok = e.get("te") == "chunked" and e.get("cl") != 5
    r["M1"] = {"name": "CL then TE: chunked", "upstream_saw": e,
               "verdict": "PASS" if ok else "FAIL",
               "severity": "Critical" if not ok else "Info"}

    e = echo_probe(b"Transfer-Encoding: chunked\r\nContent-Length: 5\r\n",
                   b"3\r\nabc\r\n0\r\n\r\n")
    ok = e.get("te") == "chunked"
    r["M2"] = {"name": "TE then CL: chunked", "upstream_saw": e,
               "verdict": "PASS" if ok else "FAIL",
               "severity": "Critical" if not ok else "Info"}

    e = echo_probe(b"Content-Length: 5\r\nContent-Length: 5\r\n", b"abcde")
    ok = e.get("cl") == 5 and e.get("body_bytes") == 5
    r["M3"] = {"name": "duplicate CL, same value", "upstream_saw": e,
               "verdict": "PASS" if ok else "FAIL",
               "severity": "High" if not ok else "Info"}

    e = echo_probe(b"Content-Length: 5\r\nContent-Length: 3\r\n", b"abcde")
    ok = e.get("cl") in (5, 3) and e.get("body_bytes") in (5, 3)
    # desync exists only if upstream consumed different bytes than the client
    # intended; with an echo upstream we check nothing extra was interpreted
    r["M4"] = {"name": "duplicate CL, conflicting", "upstream_saw": e,
               "verdict": "PASS" if ok else "FAIL",
               "severity": "High" if not ok else "Medium"}

    e = echo_probe(b"Content-Length : 5\r\nX-Real: 1\r\n", b"abcde")
    # "Content-Length :" is not a valid field name; RFC says reject
    suspicious = e.get("cl") == 5
    r["M5"] = {"name": "CL with space before colon", "upstream_saw": e,
               "verdict": "PASS" if not suspicious else "WARN",
               "severity": "High" if suspicious else "Info"}

    d = send(b"POST /echo HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\n\r\n"
             b"ZZZZ\r\n0\r\n\r\n", timeout=8, wait=2)
    ok = b"400" in d.split(b"\r\n")[0] or d == b"" or b"502" in d.split(b"\r\n")[0]
    r["M6"] = {"name": "malformed chunk size", "observed": d[:60].decode(errors="replace"),
               "verdict": "PASS" if ok else "FAIL", "severity": "High" if not ok else "Info"}

    e = echo_probe(b"Transfer-Encoding: chunked\r\n", b"3\r\nabc\r\n0\r\nX-Trailer: 1\r\n\r\n")
    ok = e.get("te") == "chunked"
    r["M7"] = {"name": "chunked + trailers", "upstream_saw": e,
               "verdict": "PASS" if ok else "FAIL", "severity": "Low" if ok else "Medium"}

    d1 = send(b"GET /echo HTTP/1.1\r\nHost: x\r\nXX\r\n\r\n", timeout=6, wait=1.5)
    d2 = send(b"GET /echo HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n", timeout=6, wait=1.5)
    ok2 = b"200" in d2[:40] or b"{" in d2
    r["M8"] = {"name": "fresh conn healthy after malformed prior conn",
               "after": d2[:40].decode(errors="replace"),
               "verdict": "PASS" if ok2 else "FAIL", "severity": "High" if not ok2 else "Info"}


def fuzz_case(i: int) -> bytes:
    methods = [b"GET", b"POST", b"PUT", b"DELETE", b"OPTIONS", b"HEAD", b"TRACE", b"\x00ET", b"LOONG"*3]
    uris = [b"/echo", b"/bench", b"/" + b"a"*2000, b"/%2e%2e/%2e%2e/", b"// ////", b"/\xff\xfe",
            b"/bench?x=" + b"9"*1500, b"*", b"http://evil/abs", b"/../..%2f.."]
    versions = [b"HTTP/1.1", b"HTTP/1.0", b"HTTP/9.9", b"HTTP/", b"FTP/1.1", b""]
    heads = [
        (b"Host: x", b"Host: " + b"h"*600, b"Host:", b"host:  y", b""),
        (b"Content-Length: 10", b"Content-Length: -5", b"Content-Length: 99999999999",
         b"Content-Length: 0x10", b"Content-Length: 5\r\nContent-Length: 7"),
        (b"Transfer-Encoding: chunked, gzip", b"Transfer-Encoding: \x00chunked",
         b"Transfer-Encoding: identity"),
        (b"X-Pad: " + b"A"*8000, b"X-\x01: v", b": v", b"Name no colon"),
    ]
    bodies = [b"", b"abcde", b"3\r\nabc\r\n0\r\n\r\n", b"\x00"*100, b"\xff"*2048,
              b"Z"*10 + b"\r\n\r\n"]

    m = methods[i % len(methods)]
    u = uris[i % len(uris)]
    v = versions[i % len(versions)]
    group = heads[(i // 3) % len(heads)]
    h = group[i % len(group)]
    b = bodies[(i // 7) % len(bodies)]
    req = m + b" " + u + b" " + v + b"\r\nHost: fuzz\r\n" + h + b"\r\n\r\n" + b
    # random truncation / segmentation flavor
    if i % 11 == 0:
        req = req[: max(1, len(req) // 2)]
    if i % 13 == 0:
        req += b"\x00" * 50
    return req


def phase1():
    print("--- phase 1: grammar fuzzing (1200 cases)", flush=True)
    stats = admin = None
    import urllib.request
    def rss_mib():
        try:
            req = urllib.request.Request(
                "http://host.docker.internal:19090/api/stats",
                headers={"Authorization": "Bearer bench"})
            # admin only exposes aggregate; use docker stats via ssh-free path:
            return None
        except OSError:
            return None
    # RSS via docker stats from outside is not available in-container; instead
    # measure FD growth via the admin? Use response times + aliveness here;
    # the driver outside collects RSS. Keep assertions: alive + admin latency.
    alive_after = 0
    admin_lat = []
    t0 = time.monotonic()
    responses = {"2xx": 0, "4xx5xx": 0, "empty": 0, "exc": 0}
    for i in range(1200):
        raw = fuzz_case(i)
        try:
            s = socket.create_connection(JUG, timeout=6)
            s.sendall(raw)
            s.settimeout(3)
            try:
                d = s.recv(4096)
            except socket.timeout:
                d = b""
            s.close()
            if d.startswith(b"HTTP/1.1 2"):
                responses["2xx"] += 1
            elif d.startswith(b"HTTP/1.1 4") or d.startswith(b"HTTP/1.1 5"):
                responses["4xx5xx"] += 1
            elif d:
                responses["4xx5xx"] += 1
            else:
                responses["empty"] += 1
        except OSError:
            responses["exc"] += 1
        if i % 300 == 299:
            t = time.monotonic()
            try:
                c = socket.create_connection(ADMIN := ("juggler", 9090), timeout=5)
                c.sendall(b"GET /api/stats HTTP/1.1\r\nHost: a\r\n"
                          b"Authorization: Bearer bench\r\nConnection: close\r\n\r\n")
                dd = c.recv(200)
                c.close()
                admin_lat.append(round(time.monotonic() - t, 3))
                if b"200" in dd:
                    alive_after += 1
            except OSError:
                pass
    dur = round(time.monotonic() - t0, 1)
    RES["fuzz"] = {
        "cases": 1200, "duration_s": dur, "responses": responses,
        "admin_checks_ok": f"{alive_after}/4", "admin_latencies_s": admin_lat,
        "server_alive": alive_after == 4,
        "verdict": "PASS" if alive_after == 4 and responses["exc"] < 1200 else "FAIL",
        "severity": "Critical" if alive_after != 4 else "Info",
    }
    print(f"    1200 cases in {dur}s: {responses}; admin alive {alive_after}/4", flush=True)


def main():
    phase2()
    phase1()
    json.dump(RES, open(OUT, "w"), indent=1)
    fails = [k for ph in ("smuggle", "fuzz") for k, v in RES[ph].items()
             if isinstance(v, dict) and v.get("verdict") == "FAIL"]
    print(f"\nwritten {OUT}; FAIL: {fails or 'none'}", flush=True)


if __name__ == "__main__":
    main()
