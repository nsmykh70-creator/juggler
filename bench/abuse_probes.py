#!/usr/bin/env python3
"""Phase 4 protocol-abuse probes, run inside bench-attack (has python3).
Prints JSON {header_64k, body_8mb, garbage, pipeline_50, slow_read}.
"""
import json
import socket
import time

T = ("juggler", 8080)
out = {}


def probe_header_64k():
    try:
        s = socket.create_connection(T, timeout=15)
        s.sendall(b"GET /bench HTTP/1.1\r\nHost: x\r\n" + b"X-Big: " + b"A" * 65536 + b"\r\n\r\n")
        line = s.recv(200).decode(errors="replace").split("\r\n")[0]
        s.close()
        return line or "empty-response"
    except Exception as e:
        return f"EXC:{type(e).__name__}"


def probe_body_8mb():
    try:
        s = socket.create_connection(T, timeout=25)
        s.sendall(b"POST /bench HTTP/1.1\r\nHost: x\r\nContent-Length: 8388608\r\n\r\n")
        s.sendall(b"x" * 8388608)
        line = s.recv(200).decode(errors="replace").split("\r\n")[0]
        s.close()
        return line or "empty-response"
    except Exception as e:
        return f"EXC:{type(e).__name__}"


def probe_garbage():
    try:
        s = socket.create_connection(T, timeout=10)
        s.sendall(bytes(range(256)) * 40)
        data = s.recv(120)
        s.close()
        return f"{len(data)}b:{data[:60]!r}"
    except Exception as e:
        return f"EXC:{type(e).__name__}"


def probe_pipeline():
    try:
        s = socket.create_connection(T, timeout=10)
        s.sendall(b"GET /bench HTTP/1.1\r\nHost: x\r\n" * 50)
        time.sleep(2)
        d = s.recv(262144)
        s.close()
        return f"responses:{d.count(b'HTTP/1.1')}"
    except Exception as e:
        return f"EXC:{type(e).__name__}"


def probe_slow_read():
    """Ships the request slowly (10 bytes/s) and counts bytes received back
    in a window — proves data-plane streams instead of buffering forever."""
    try:
        s = socket.create_connection(T, timeout=40)
        req = b"GET /bench HTTP/1.1\r\nHost: x\r\nX-Slow: "
        s.sendall(req)
        for _ in range(20):
            s.sendall(b"A" * 10)
            time.sleep(1)
        s.sendall(b"\r\n\r\n")
        s.settimeout(15)
        total = 0
        try:
            while True:
                chunk = s.recv(65536)
                if not chunk:
                    break
                total += len(chunk)
        except socket.timeout:
            pass
        s.close()
        return f"bytes:{total}"
    except Exception as e:
        return f"EXC:{type(e).__name__}"


out["header_64k"] = probe_header_64k()
out["body_8mb"] = probe_body_8mb()
out["garbage"] = probe_garbage()
out["pipeline_50"] = probe_pipeline()
out["slow_read_20s"] = probe_slow_read()
print(json.dumps(out))
