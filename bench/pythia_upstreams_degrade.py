#!/usr/bin/env python3
"""A degrading origin for the Pythia balancer test 2c.

Behaves like a real backend under intermittent overload:

- base delay ~8 ms, but the controller loops through phases:
    healthy   -> delay 8 ms, full concurrency
    saturate  -> concurrency capped to 2 for N seconds (deep queue)
    spike     -> +400 ms delay for N seconds (all requests crawl)
    drop      -> accept() then close ~50% of connections (RST)

- /phase returns the current phase (observability), /count the served
  counter (ground truth split). Neither counts as proxied traffic.

Usage:
  python bench/pythia_upstreams_degrade.py <port> <conc> <script_json>
  script_json: [{"phase": "healthy", "s": 20}, {"phase": "saturate", "s": 12}, ...]
  (looped forever)
"""
import json
import socket
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LOCK = threading.Lock()


class Origin:
    def __init__(self, conc: int, script: list):
        self.lock = threading.Lock()
        self.count = 0
        self.base_conc = conc
        self.script = script
        self.phase = "healthy"
        self.extra_delay = 0.0
        self.max_conc = conc
        self.drop_ratio = 0.0
        self._stop = threading.Event()
        threading.Thread(target=self._controller, daemon=True).start()

    def _controller(self):
        while not self._stop.is_set():
            for step in self.script:
                if self._stop.is_set():
                    return
                phase, dur = step["phase"], float(step["s"])
                with self.lock:
                    self.phase = phase
                    if phase == "healthy":
                        self.max_conc, self.extra_delay, self.drop_ratio = (
                            self.base_conc, 0.0, 0.0)
                    elif phase == "saturate":
                        self.max_conc, self.extra_delay, self.drop_ratio = (
                            2, 0.0, 0.0)
                    elif phase == "spike":
                        self.max_conc, self.extra_delay, self.drop_ratio = (
                            self.base_conc, 0.4, 0.0)
                    elif phase == "drop":
                        self.max_conc, self.extra_delay, self.drop_ratio = (
                            self.base_conc, 0.0, 0.5)
                time.sleep(dur)

    def snapshot(self):
        with self.lock:
            return (self.phase, self.max_conc, self.extra_delay, self.drop_ratio)

    def served(self, n=1):
        with LOCK:
            self.count += n
            return self.count


def serve(origin: Origin):
    class H(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"
        active = 0
        sem = None  # installed by main() before serving

        def do_GET(self):
            if self.path == "/count":
                body = json.dumps({"count": origin.count}).encode()
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)
                return
            if self.path == "/phase":
                body = json.dumps({"phase": origin.snapshot()[0]}).encode()
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)
                return
            phase, max_conc, extra, drop = origin.snapshot()
            if drop and (threading.get_ident() % 100) < drop * 100:
                # Half-close mid-conversation: the proxy must see the reset.
                self.close_connection = True
                try:
                    self.connection.shutdown(socket.SHUT_RDWR)
                except OSError:
                    pass
                origin.served()
                return
            acquired = H.sem.acquire(timeout=60)
            if not acquired:
                self.send_response(503)
                self.send_header("Content-Length", "0")
                self.end_headers()
                return
            try:
                time.sleep(0.008 + extra)
                n = origin.served()
                body = f"ok-{n}".encode()
                self.send_response(200)
                self.send_header("Content-Type", "text/plain")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)
            finally:
                H.sem.release()

        def log_message(self, *a):
            pass

    return H


class DynamicGate:
    """Semaphore whose capacity follows the current phase.

    A BoundedSemaphore cannot shrink, and leastconn's in-flight counter
    cannot see requests already parked in the origin's queue — this gate
    is what makes the gap between 'in flight upstream' and 'actually
    serving' real.
    """

    def __init__(self, origin: Origin):
        self.origin = origin
        self.active = 0
        self.cv = threading.Condition()

    def acquire(self, timeout=None):
        _, max_conc, _, _ = self.origin.snapshot()
        deadline = None if timeout is None else time.time() + timeout
        with self.cv:
            while self.active >= max_conc:
                if deadline is not None and time.time() >= deadline:
                    return False
                self.cv.wait(timeout=0.05)
            self.active += 1
            return True

    def release(self):
        with self.cv:
            self.active -= 1
            self.cv.notify()


def main():
    port = int(sys.argv[1])
    conc = int(sys.argv[2])
    script = json.load(open(sys.argv[3], encoding="utf-8"))
    origin = Origin(conc, script)
    handler = serve(origin)
    handler.sem = DynamicGate(origin)
    srv = ThreadingHTTPServer(("127.0.0.1", port), handler)
    srv.daemon_threads = True
    srv.serve_forever()


if __name__ == "__main__":
    main()
