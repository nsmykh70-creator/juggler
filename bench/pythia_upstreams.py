#!/usr/bin/env python3
"""Two upstream origin servers for the Pythia balancer A/B test.

- :8101 fast — responds after ~10 ms
- :8102 slow — responds after ~120 ms

Each logs its request count to a file so the test can measure the actual
traffic split (ground truth, independent of Juggler's own metrics).
GET /count returns the current counter as JSON (not counted as a proxied
request).
"""
import json
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LOCK = threading.Lock()


def make_handler(delay_s: float, counter_file: str):
    class H(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def do_GET(self):
            if self.path == "/count":
                body = json.dumps({"count": H.count}).encode()
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)
                return
            if delay_s:
                time.sleep(delay_s)
            with LOCK:
                H.count += 1
                n = H.count
            body = f"ok-{n}".encode()
            self.send_response(200)
            self.send_header("Content-Type", "text/plain")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def log_message(self, *a):
            pass

    H.count = 0
    H.counter_file = counter_file
    return H


def main():
    port = int(sys.argv[1])
    delay = float(sys.argv[2])
    counter_file = sys.argv[3]
    srv = ThreadingHTTPServer(("127.0.0.1", port), make_handler(delay, counter_file))
    srv.serve_forever()


if __name__ == "__main__":
    main()
