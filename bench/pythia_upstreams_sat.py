#!/usr/bin/env python3
"""Saturated two-upstream stand for the Pythia balancer A/B (test 2).

- :8101 fast — ~10 ms per response, at most CONC_FAST concurrent
- :8102 slow — ~120 ms per response, at most CONC_SLOW concurrent

A bounded semaphore creates a REAL deep queue on the origin: once the
concurrency limit is hit, extra connections wait (their latency grows
without bound) exactly like a saturated worker pool. Capacity:

  fast ~= CONC_FAST / 0.010 rps      slow ~= CONC_SLOW / 0.120 rps

Each origin logs its served-request count to a file (ground truth split).
GET /count returns the counter as JSON (not counted as a proxied request).

Usage: python bench/pythia_upstreams_sat.py <port> <delay_s> <conc> <counter_file>
"""
import json
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LOCK = threading.Lock()


def make_handler(delay_s: float, conc: int, counter_file: str):
    sem = threading.BoundedSemaphore(conc)

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
            if self.path == "/healthz":
                self.send_response(200)
                self.send_header("Content-Length", "2")
                self.end_headers()
                self.wfile.write(b"ok")
                return
            acquired = sem.acquire(timeout=60)  # bounded wait, then give up
            if not acquired:
                self.send_response(503)
                self.send_header("Content-Length", "0")
                self.end_headers()
                return
            try:
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
            finally:
                sem.release()

        def log_message(self, *a):
            pass

    H.count = 0
    H.counter_file = counter_file
    return H


def main():
    port = int(sys.argv[1])
    delay = float(sys.argv[2])
    conc = int(sys.argv[3])
    counter_file = sys.argv[4]
    srv = ThreadingHTTPServer(("127.0.0.1", port), make_handler(delay, conc, counter_file))
    srv.daemon_threads = True
    srv.serve_forever()


if __name__ == "__main__":
    main()
