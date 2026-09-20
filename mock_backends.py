#!/usr/bin/env python3
"""Mock backends for testing the Juggler load balancer.

Starts two HTTP backends:
  - port 7001: fast  (responds immediately)
  - port 7002: slow  (adds 1.5s artificial latency)

A response header ``X-Backend`` shows which upstream served each request,
so Least Connections behaviour is directly observable.
"""
import http.server
import json
import sys
import threading
import time

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

FAST_PORT = 7001
SLOW_PORT = 7002
ECHO_PORT = 7003
SLOW_DELAY = 1.5  # seconds


class FastHandler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        body = b"response from FAST backend (7001)\n"
        self.send_response(200)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("X-Backend", "fast-7001")
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass  # keep test output clean


class SlowHandler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        time.sleep(SLOW_DELAY)
        body = b"response from SLOW backend (7002)\n"
        self.send_response(200)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("X-Backend", "slow-7002")
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


class EchoHandler(http.server.BaseHTTPRequestHandler):
    """Reflects the request back: method, selected headers and the raw body.

    Used by the WASM plugin integration tests to observe, end to end,
    what actually arrived at the upstream after the filter pipeline.
    """

    def _echo(self):
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length) if length else b""
        # A couple of interesting headers, lowercase-normalized.
        headers = {
            k.lower(): v
            for k, v in self.headers.items()
            if k.lower() in ("x-wasm-juggler", "x-request-id", "x-forwarded-for", "content-type")
        }
        payload = {
            "method": self.command,
            "path": self.path,
            "headers": headers,
            "body": body.decode("utf-8", "replace"),
        }
        out = (json.dumps(payload, indent=2) + "\n").encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(out)))
        self.send_header("X-Backend", "echo-7003")
        self.end_headers()
        self.wfile.write(out)

    do_GET = do_POST = _echo

    def log_message(self, *args):
        pass


def serve(port, handler):
    httpd = http.server.ThreadingHTTPServer(("127.0.0.1", port), handler)
    print(f"   backend on http://127.0.0.1:{port} ({handler.__name__})")
    httpd.serve_forever()


if __name__ == "__main__":
    print("🏁 Starting mock backends:")
    threading.Thread(target=serve, args=(FAST_PORT, FastHandler), daemon=True).start()
    threading.Thread(target=serve, args=(SLOW_PORT, SlowHandler), daemon=True).start()
    threading.Thread(target=serve, args=(ECHO_PORT, EchoHandler), daemon=True).start()
    try:
        while True:
            time.sleep(3600)
    except KeyboardInterrupt:
        print("\n🛑 Mock backends stopped")
