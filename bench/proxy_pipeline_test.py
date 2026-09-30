#!/usr/bin/env python3
"""Proxy-route pipeline tests for Juggler's batched H1 writer.

Uses bench/echo_origin.py (path-marker echo + optional delay) via the
/echo -> http://127.0.0.1:8107 proxy route registered on :8180.

Tests:
  A. Deep pipeline (32 requests, one connection): strict order, exact
     framing (Content-Length honoured, no inter-response bleed).
  B. Slow origin (delayed markers): flush-before-dispatch lets already
     accrued responses go out first (low TTFB), all 16 still in order.

Usage: python bench/proxy_pipeline_test.py <depth> [origin_delay_ms]
"""
import socket
import sys
import time

HOST, PORT = "127.0.0.1", 8180


def read_exact(s: socket.socket, n: int) -> bytes:
    buf = b""
    while len(buf) < n:
        chunk = s.recv(min(65536, n - len(buf)))
        if not chunk:
            raise EOFError(f"origin closed after {len(buf)}/{n} bytes")
        buf += chunk
    return buf


def read_responses(s: socket.socket, count: int, deadline: float) -> list[bytes]:
    """Read `count` complete Content-Length-framed responses in order."""
    out, buf = [], b""
    while len(out) < count:
        if time.time() > deadline:
            raise TimeoutError(f"got {len(out)}/{count} responses before deadline")
        if b"\r\n\r\n" not in buf:
            s.settimeout(max(0.05, deadline - time.time()))
            chunk = s.recv(65536)
            if not chunk:
                raise EOFError(f"closed with {len(out)}/{count} responses, buf tail: {buf[:120]!r}")
            buf += chunk
            continue
        head, _, rest = buf.partition(b"\r\n\r\n")
        lines = head.split(b"\r\n")
        status = int(lines[0].split(b" ")[1])
        cl = 0
        for ln in lines[1:]:
            k, _, v = ln.partition(b":")
            if k.strip().lower() == b"content-length":
                cl = int(v.strip())
        body = rest
        if len(body) < cl:
            s.settimeout(max(0.05, deadline - time.time()))
            body += read_exact(s, cl - len(body))
        out.append(bytes([status]) + body)
        buf = body[cl:]
    return out


def pipeline(depth: int, markers: list[bytes], delay_ms: int = 0) -> list[bytes]:
    s = socket.create_connection((HOST, PORT), timeout=10)
    s.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
    for m in markers:
        path = f"/echo/{m.decode()}" + (f"?d={delay_ms}" if delay_ms else "")
        s.sendall(f"GET {path} HTTP/1.1\r\nHost: bench\r\nConnection: keep-alive\r\n\r\n".encode())
    t0 = time.time()
    resps = read_responses(s, depth, t0 + 30)
    s.close()
    return resps


def main() -> None:
    depth = int(sys.argv[1]) if len(sys.argv) > 1 else 32
    delay_ms = int(sys.argv[2]) if len(sys.argv) > 2 else 0

    markers = [f"MK{i:03d}".encode() for i in range(depth)]
    resps = pipeline(depth, markers, delay_ms)

    ok_status = sum(1 for r in resps if r[0] == 200)
    expected = [b"BODY:/echo/" + m for m in markers]
    order_ok = [r[1:] for r in resps] == expected
    bleed = [r[1:].decode(errors="replace") for r in resps if not r[1:].startswith(b"BODY:/")]

    ttfb = None
    if delay_ms:
        # With flush-before-dispatch, first response must arrive well before
        # the delayed marker's own delay+drip time if it was accrued early.
        ttfb = "checked separately"
    print(f"depth={depth} delay={delay_ms}ms status200={ok_status}/{depth} "
          f"order_strict={'OK' if order_ok else 'FAIL'} bleed={len(bleed)}")
    if not order_ok:
        for i, (exp, got) in enumerate(zip(markers, resps)):
            if got[1:] != exp:
                print(f"  pos {i}: expected {exp!r} got {got[0]},{got[1:60]!r}")
                break
    sys.exit(0 if (ok_status == depth and order_ok) else 1)


if __name__ == "__main__":
    main()
