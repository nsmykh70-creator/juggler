#!/usr/bin/env python3
"""Deterministic echo origin for proxy pipeline verification.

GET /MARKER           -> 200, body = "BODY:MARKER" (body mirrors the path)
GET /MARKER?ms=250    -> same, after sleeping `ms` milliseconds (slow origin)
GET /big?ms=...       -> a ~64 KB padded body (body size sanity)

Everything is Content-Length framed; the body always contains the request
path, so response ORDER can be verified byte-exactly under pipelining.
"""
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

PAD = b"P" * 65536


class H(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def do_GET(self):
        path = self.path.split("?", 1)[0]
        # delay parameter
        ms = 0
        if "?" in self.path:
            q = self.path.split("?", 1)[1]
            for kv in q.split("&"):
                if kv.startswith("ms="):
                    try:
                        ms = int(kv[3:])
                    except ValueError:
                        pass
        if ms:
            time.sleep(ms / 1000.0)
        body = b"BODY:" + path.encode()
        with open("bench/echo_origin_seen.log", "a", encoding="utf-8") as f:
            f.write(f"{time.time():.3f} {path}\n")
        if path == "/big":
            body += PAD
        self.send_response(200)
        self.send_header("Content-Type", "text/plain")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        n = int(self.headers.get("Content-Length", 0))
        payload = self.rfile.read(n) if n else b""
        body = b"BODY:" + self.path.encode() + b"|" + payload
        self.send_response(200)
        self.send_header("Content-Type", "text/plain")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *a):
        pass


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8107
    ThreadingHTTPServer(("127.0.0.1", port), H).serve_forever()
