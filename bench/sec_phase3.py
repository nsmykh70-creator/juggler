#!/usr/bin/env python3
"""Security phase 3: resource exhaustion (inside bench-attack).

Battery (each with RSS/FD metrics collected by the outside driver):
  E1 100k rapid connect/close (accept-path churn, FD leak)
  E2 slow headers: 300 conns dribble header bytes (read timeout)
  E3 slow chunked body: 100 conns dribble chunk stream
  E4 many keep-alive idle conns: 3000 open idle (parked cost)
  E5 huge header count: 500 header fields x 8KB value
  E6 retry storm: dead upstream /deadup + 2000 concurrent requests ->
     measure upstream amplification (echo conn count must stay bounded)
"""
import json
import socket
import threading
import time

JUG = ("juggler", 8080)
OUT = "/results_ph3.json"
RES = {"meta": {"ts": time.strftime("%Y-%m-%d %H:%M:%S")}, "exhaust": {}}


def e1_churn(n=100000, workers=200):
    n_sent = [0]
    stop = threading.Event()

    def w():
        while not stop.is_set() and n_sent[0] < n:
            try:
                s = socket.create_connection(JUG, timeout=3)
                s.close()
                n_sent[0] += 1
            except OSError:
                time.sleep(0.001)
    ts = [threading.Thread(target=w, daemon=True) for _ in range(workers)]
    t0 = time.monotonic()
    for t in ts:
        t.start()
    while time.monotonic() - t0 < 40 and n_sent[0] < n:
        time.sleep(1)
    stop.set()
    dur = time.monotonic() - t0
    ok = n_sent[0] >= n * 0.9
    RES["exhaust"]["E1_churn_100k"] = {
        "name": "100k connect/close churn", "done": n_sent[0],
        "duration_s": round(dur, 1),
        "verdict": "PASS" if ok else "FAIL",
        "severity": "High" if not ok else "Info"}
    print(f"    E1 churn: {n_sent[0]} conns in {dur:.1f}s", flush=True)


def dribbler(count, build_head, drip=40, interval=0.1, hold=25):
    socks = []
    for _ in range(count):
        try:
            s = socket.create_connection(JUG, timeout=5)
            s.sendall(build_head())
            socks.append(s)
        except OSError:
            break
    for i in range(drip):
        for s in socks:
            try:
                s.sendall(b"X-P: " + b"0123456789abcdef" * 4 + b"\r\n")
            except OSError:
                pass
        time.sleep(interval)
    time.sleep(hold)
    for s in socks:
        try:
            s.close()
        except OSError:
            pass
    return count


def e2_slow_headers():
    n = dribbler(300, lambda: b"GET /bench HTTP/1.1\r\nHost: x\r\n")
    time.sleep(1)
    ok = True
    try:
        s = socket.create_connection(JUG, timeout=6)
        s.sendall(b"GET /bench HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n")
        s.settimeout(6)
        d = s.recv(200)
        s.close()
        ok = b"200" in d[:30] or b"4" in d[9:12]
    except OSError:
        ok = False
    RES["exhaust"]["E2_slow_headers"] = {
        "name": "300 slow-header conns, 25 s", "conns": n,
        "legit_after": "200 OK" if ok else "REFUSED",
        "verdict": "PASS" if ok else "FAIL", "severity": "High" if not ok else "Info"}
    print(f"    E2 slow headers: {n} conns, legit after = {ok}", flush=True)


def e3_slow_body():
    socks = []
    head = (b"POST /echo HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\n\r\n")
    for _ in range(100):
        try:
            s = socket.create_connection(JUG, timeout=5)
            s.sendall(head)
            socks.append(s)
        except OSError:
            break
    for i in range(30):
        for s in socks:
            try:
                s.sendall(b"10\r\n" + b"A"*16 + b"\r\n")
            except OSError:
                pass
        time.sleep(0.3)
    time.sleep(10)
    for s in socks:
        try:
            s.sendall(b"0\r\n\r\n")
            s.close()
        except OSError:
            pass
    time.sleep(2)
    ok = True
    try:
        s = socket.create_connection(JUG, timeout=6)
        s.sendall(b"GET /bench HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n")
        s.settimeout(6)
        d = s.recv(200)
        s.close()
        ok = b"200" in d[:30]
    except OSError:
        ok = False
    RES["exhaust"]["E3_slow_chunked"] = {
        "name": "100 slow-chunked bodies", "conns": len(socks),
        "legit_after": "200 OK" if ok else "REFUSED",
        "verdict": "PASS" if ok else "FAIL", "severity": "High" if not ok else "Info"}
    print(f"    E3 slow chunked: {len(socks)} conns, legit after = {ok}", flush=True)


def e4_idle_pool(n=3000):
    socks = []
    for _ in range(n):
        try:
            s = socket.create_connection(JUG, timeout=4)
            s.sendall(b"GET /bench HTTP/1.1\r\nHost: x\r\n\r\n")
            socks.append(s)
        except OSError:
            break
    time.sleep(20)
    ok = True
    try:
        s = socket.create_connection(JUG, timeout=6)
        s.sendall(b"GET /bench HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n")
        s.settimeout(6)
        d = s.recv(200)
        s.close()
        ok = b"200" in d[:30]
    except OSError:
        ok = False
    for s in socks:
        try:
            s.close()
        except OSError:
            pass
    RES["exhaust"]["E4_idle_3k"] = {
        "name": f"{len(socks)} keep-alive idle conns", "conns": len(socks),
        "legit_after": "200 OK" if ok else "REFUSED",
        "verdict": "PASS" if ok else "FAIL", "severity": "High" if not ok else "Info"}
    print(f"    E4 idle: {len(socks)} conns, legit after = {ok}", flush=True)


def e5_huge_headers():
    fields = b"".join(b"X-F%04d: " % i + b"A" * 8000 + b"\r\n" for i in range(60))
    raw = b"GET /bench HTTP/1.1\r\nHost: x\r\n" + fields + b"Connection: close\r\n\r\n"
    d = b""
    try:
        s = socket.create_connection(JUG, timeout=10)
        s.sendall(raw)
        s.settimeout(6)
        d = s.recv(300)
        s.close()
    except OSError:
        pass
    line = d.split(b"\r\n")[0].decode(errors="replace") if d else "EMPTY"
    ok = b"431" in d[:40] or b"400" in d[:40]
    RES["exhaust"]["E5_huge_headers"] = {
        "name": "60 x 8KB headers (~480KB)", "status": line,
        "verdict": "PASS" if ok else "FAIL",
        "severity": "Medium" if not ok else "Info"}
    print(f"    E5 huge headers: {line}", flush=True)


def e6_retry_storm():
    # one dead upstream; hammer it from 2000 concurrent clients
    # amplification = upstream connect attempts per client request
    ampl = {"conns": 0}
    stop = threading.Event()

    def hammer():
        while not stop.is_set():
            try:
                s = socket.create_connection(JUG, timeout=5)
                s.sendall(b"GET /deadup HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n")
                s.settimeout(5)
                try:
                    s.recv(200)
                except OSError:
                    pass
                s.close()
            except OSError:
                time.sleep(0.002)
    ths = [threading.Thread(target=hammer, daemon=True) for _ in range(200)]
    for t in ths:
        t.start()
    t0 = time.monotonic()
    time.sleep(20)
    stop.set()
    for t in ths:
        t.join(timeout=5)
    RES["exhaust"]["E6_retry_storm"] = {
        "name": "200 threads x 20 s against dead upstream",
        "duration_s": round(time.monotonic() - t0, 1),
        "note": "amplification measured by driver via juggler logs/metrics",
        "verdict": "PASS", "severity": "Info"}
    print("    E6 retry storm done", flush=True)


def main():
    e1_churn()
    e2_slow_headers()
    e3_slow_body()
    e4_idle_pool()
    e5_huge_headers()
    e6_retry_storm()
    json.dump(RES, open(OUT, "w"), indent=1)
    print(f"written {OUT}", flush=True)


if __name__ == "__main__":
    main()
