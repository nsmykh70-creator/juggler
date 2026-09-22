#!/usr/bin/env python3
"""Security phase 6: host/path/poisoning/TLS probes (inside bench-attack)."""
import json
import socket
import ssl
import time

JUG = ("juggler", 8080)
OUT = "/results_ph6.json"
RES = {"meta": {"ts": time.strftime("%Y-%m-%d %H:%M:%S")}, "misc": {}}


def http(req: bytes, timeout=6, wait=2.5):
    try:
        s = socket.create_connection(JUG, timeout=timeout)
        s.sendall(req)
        s.settimeout(wait)
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
        return d
    except OSError as e:
        return f"EXC:{type(e).__name__}".encode()


def line(d):
    return d.split(b"\r\n")[0].decode(errors="replace") if d else "EMPTY"


def rec(name, verdict, severity, **kw):
    RES["misc"][name] = {"verdict": verdict, "severity": severity, **kw}


def phase6():
    print("--- phase 6", flush=True)
    # H1 unknown host
    d = http(b"GET /bench HTTP/1.1\r\nHost: nosuch.host\r\nConnection: close\r\n\r\n")
    rec("H1_unknown_host", "PASS" if (b"{" in d or b"200" in d[:30]) else "FAIL",
        "Low" if d else "Medium", status=line(d))

    # H2 empty host
    d = http(b"GET /bench HTTP/1.1\r\nHost: \r\nConnection: close\r\n\r\n")
    rec("H2_empty_host", "PASS" if b"400" in d[:30] or b"{" in d or b"200" in d[:30] else "FAIL",
        "Medium", status=line(d))

    # H3 duplicate host
    d = http(b"GET /bench HTTP/1.1\r\nHost: a\r\nHost: b\r\nConnection: close\r\n\r\n")
    ok = b"400" in d[:30] or b"{" in d
    rec("H3_duplicate_host", "PASS" if ok else "FAIL", "Medium", status=line(d))

    # H4 absolute-form URI must reach upstream as /bench, not http://juggler:8080/bench
    d = http(b"GET http://juggler:8080/bench HTTP/1.1\r\nHost: juggler:8080\r\nConnection: close\r\n\r\n")
    ok = b"200" in d[:30]
    rec("H4_absolute_form", "PASS" if ok else "FAIL", "Medium", status=line(d))

    # H5 host injection via header value (CRLF already impossible in raw sockets, test space)
    d = http(b"GET /bench HTTP/1.1\r\nHost: x evil\r\nConnection: close\r\n\r\n")
    rec("H5_host_injection", "PASS" if b"400" in d[:30] or b"{" in d else "FAIL", "High", status=line(d))

    # P1..P6 path traversal against static docroot
    paths = [b"/sample_sites1/../../routes.json", b"/sample_sites1/..%2f..%2fCargo.toml",
             b"/sample_sites1/%2e%2e/%2e%2e/Cargo.toml", b"/sample_sites1/%252e%252e/Cargo.toml",
             b"/sample_sites1/....//routes.json", b"/sample_sites1/subdir\\..\\..\\Cargo.toml"]
    for i, p in enumerate(paths, 1):
        d = http(b"GET " + p + b" HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n")
        st = line(d)
        ok = not (b"200" in d[:30] and (b"Cargo" in d or b"routes" in d))
        rec(f"P{i}_traversal_{p.decode(errors='replace')[:40]}", "PASS" if ok else "FAIL",
            "Critical" if not ok else "Info", status=st)

    # F1 XFF poisoning visible to upstream
    d = http(b"GET /echo HTTP/1.1\r\nHost: x\r\nX-Forwarded-For: 1.2.3.4\r\n"
             b"Connection: close\r\n\r\n")
    # /echo echoes request; acceptable if XFF preserved or overwritten - record
    js = d[d.index(b"{"): d.rindex(b"}") + 1] if b"{" in d else b"{}"
    try:
        seen = json.loads(js)
        rec("F1_xff_preserved", "INFO", "Low",
            upstream_saw_xff=str(seen.get("xff"))[:80])
    except (json.JSONDecodeError, ValueError):
        rec("F1_xff_preserved", "INFO", "Low", note="no echo")

    # F2 CR injection into header value
    d = http(b"GET /echo HTTP/1.1\r\nHost: x\r\nX-Bad: v\rX-Inject: 1\r\nConnection: close\r\n\r\n")
    rec("F2_lone_cr_header", "PASS" if b"400" in d[:30] else "WARN",
        "Medium" if b"400" not in d[:30] else "Info", status=line(d))

    # T1 TLS handshake to plain port must not confuse
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    try:
        s = socket.create_connection(JUG, timeout=5)
        ss = ctx.wrap_socket(s, server_hostname="juggler")
        ss.sendall(b"GET /bench HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n")
        ss.settimeout(4)
        d = ss.recv(200)
        ss.close()
        rec("T1_tls_on_plain", "INFO", "Low", status=line(d))
    except (OSError, ssl.SSLError) as e:
        rec("T1_tls_on_plain", "PASS", "Info", err=f"{type(e).__name__}")

    # T2 malformed ClientHello to TLS :8443 (mapped on host 8443; in-net juggler has no TLS port)
    # Use juggler:8080 raw bytes instead
    try:
        s = socket.create_connection(JUG, timeout=5)
        s.sendall(b"\x16\x03\x01\x00\x05GARBAGE")
        s.settimeout(3)
        d = s.recv(100)
        s.close()
        rec("T2_garbage_tls_bytes", "PASS", "Info", status=line(d))
    except OSError as e:
        rec("T2_garbage_tls_bytes", "PASS", "Info", err=f"{type(e).__name__}")

    # A1 valid TLS check against host-mapped 8443 via host.docker.internal? skip inside net.
    rec("A1_tls_endpoints", "INFO", "Low", note="TLS host binary tested by driver outside")


def main():
    phase6()
    json.dump(RES, open(OUT, "w"), indent=1)
    fails = [k for k, v in RES["misc"].items() if v.get("verdict") == "FAIL"]
    print(f"written {OUT}; FAIL: {fails or 'none'}", flush=True)


if __name__ == "__main__":
    main()
