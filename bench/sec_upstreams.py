#!/usr/bin/env python3
"""Security-pass upstreams. Runs inside bench-attack (has python3).

  9100 echo       - records raw request bytes to /tmp/echo.log; answers
                    200 with a body that echoes how the request was framed
                    (CL seen, TE seen, pipelined count) -> smuggling oracle
  9101 hang       - accepts, reads, never responds (hang tests)
  9102 rst        - accepts, immediately RSTs
  9103 garbage    - responds with non-HTTP bytes
  9104 bighead    - responds 200 with ~100 KB of headers
  9105 gorgon     - chunked-forever body (~4 MB/s) until killed
  9106 flaky      - 200 for 1.5 s, then refuses connections for 4 s, cycles
  9107 sse        - streams headers then one byte per second (idle-read)
  9108 counter    - JSON {"count": N} per request (amplification oracle)
"""
import json
import socket
import socketserver
import threading
import time

LOG = open("/tmp/echo.log", "w", buffering=1)
COUNTER = {"n": 0}


class RawEcho(socketserver.BaseRequestHandler):
    def handle(self):
        buf = b""
        self.request.settimeout(60)
        while True:
            try:
                chunk = self.request.recv(65536)
            except OSError:
                return
            if not chunk:
                return
            buf += chunk
            LOG.write(f"RECV {len(chunk)}B: {chunk[:400]!r}\n")
            # answer once per complete-ish request: has a head end and
            # either a full CL body or just respond greedily per burst
            if b"\r\n\r\n" in buf:
                head, _, rest = buf.partition(b"\r\n\r\n")
                cl = 0
                te = b""
                for line in head.split(b"\r\n"):
                    low = line.lower()
                    if low.startswith(b"content-length:"):
                        try:
                            cl = int(line.split(b":")[1].strip())
                        except ValueError:
                            cl = -1
                    elif low.startswith(b"transfer-encoding:"):
                        te = line.split(b":")[1].strip()
                body_ready = (cl >= 0 and len(rest) >= cl) or (b"chunked" in te)
                if body_ready or len(rest) > 0:
                    reply = json.dumps({
                        "cl": cl, "te": te.decode(errors="replace"),
                        "body_bytes": len(rest),
                        "head_lines": head[:200].decode(errors="replace").split("\r\n")[0],
                    }).encode()
                    out = (b"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n"
                           b"Content-Length: " + str(len(reply)).encode() + b"\r\n"
                           b"Connection: keep-alive\r\n\r\n" + reply)
                    try:
                        self.request.sendall(out)
                    except OSError:
                        return
                    buf = b""


class ThreadedTCPServer(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


def hang():
    s = socket.socket()
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    s.bind(("0.0.0.0", 9101))
    s.listen(4096)
    conns = []
    while True:
        c, _ = s.accept()
        conns.append(c)  # accept, never respond, never close


def rst():
    s = socket.socket()
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    s.bind(("0.0.0.0", 9102))
    s.listen(4096)
    while True:
        c, _ = s.accept()
        c.setsockopt(socket.SOL_SOCKET, socket.SO_LINGER,
                     b"\x01\x00\x00\x00\x00\x00\x00\x00")  # RST on close
        c.close()


def garbage():
    s = socket.socket()
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    s.bind(("0.0.0.0", 9103))
    s.listen(64)
    while True:
        c, _ = s.accept()
        try:
            c.recv(65536)
            c.sendall(b"\xde\xad\xbe\xef" * 8)
        except OSError:
            pass
        c.close()


def bighead():
    s = socket.socket()
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    s.bind(("0.0.0.0", 9104))
    s.listen(64)
    while True:
        c, _ = s.accept()
        try:
            c.recv(65536)
            heads = b"".join(b"X-Pad-%d: %s\r\n" % (i, b"A" * 100) for i in range(1000))
            body = b"ok"
            c.sendall(b"HTTP/1.1 200 OK\r\n" + heads +
                      b"Content-Length: 2\r\n\r\n" + body)
        except OSError:
            pass
        c.close()


def gorgon():
    s = socket.socket()
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    s.bind(("0.0.0.0", 9105))
    s.listen(64)
    while True:
        c, _ = s.accept()
        try:
            c.recv(65536)
            c.sendall(b"HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n")
            while True:
                c.sendall(b"%x\r\n" % 65536 + b"x" * 65536 + b"\r\n")
                time.sleep(0.016)
        except OSError:
            pass
        try:
            c.close()
        except OSError:
            pass


def flaky():
    while True:
        s = socket.socket()
        s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        s.bind(("0.0.0.0", 9106))
        s.listen(64)
        s.settimeout(1.5)
        t_end = time.monotonic() + 1.5
        try:
            while time.monotonic() < t_end:
                try:
                    c, _ = s.accept()
                except socket.timeout:
                    break
                try:
                    c.recv(65536)
                    c.sendall(b"HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok")
                except OSError:
                    pass
                c.close()
        except OSError:
            pass
        s.close()
        time.sleep(4.0)  # refuses connections for 4 s (health cycle)


def sse():
    s = socket.socket()
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    s.bind(("0.0.0.0", 9107))
    s.listen(64)
    while True:
        c, _ = s.accept()
        try:
            c.recv(65536)
            c.sendall(b"HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\n"
                      b"Transfer-Encoding: chunked\r\n\r\n")
            while True:
                c.sendall(b"1\r\n.\r\n")
                time.sleep(1)
        except OSError:
            pass
        try:
            c.close()
        except OSError:
            pass


class Counter(socketserver.BaseRequestHandler):
    def handle(self):
        try:
            self.request.recv(65536)
            COUNTER["n"] += 1
            body = json.dumps({"count": COUNTER["n"]}).encode()
            self.request.sendall(b"HTTP/1.1 200 OK\r\nConnection: close\r\n"
                                 b"Content-Length: " + str(len(body)).encode() +
                                 b"\r\n\r\n" + body)
        except OSError:
            pass


if __name__ == "__main__":
    servers = []
    for port, handler in [(9100, RawEcho), (9108, Counter)]:
        srv = ThreadedTCPServer(("0.0.0.0", port), handler)
        threading.Thread(target=srv.serve_forever, daemon=True).start()
        servers.append(srv)
    for fn in (hang, rst, garbage, bighead, gorgon, flaky, sse):
        threading.Thread(target=fn, daemon=True).start()
    print("sec upstreams up: 9100 echo, 9101 hang, 9102 rst, 9103 garbage, "
          "9104 bighead, 9105 gorgon, 9106 flaky, 9107 sse, 9108 counter", flush=True)
    while True:
        time.sleep(60)
