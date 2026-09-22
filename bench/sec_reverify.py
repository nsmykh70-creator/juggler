#!/usr/bin/env python3
"""Post-hardening re-verification probes (inside bench-attack).

Re-runs the four findings from the security pass that the hardening commit
addresses:
  V1 gRPC unauthenticated update_config  -> UNAUTHENTICATED (was: accepted)
  V2 gRPC authenticated update_config    -> success (positive control)
  V3 conflicting duplicate CL            -> rejected (400/502, no 200 echo)
  V4 huge headers                        -> explicit 431 delivered
  V5 lone-CR header value                -> rejected
  V6 malformed chunk size                -> rejected (400/502, no 200 echo)
"""
import json
import socket
import sys
import time

JUG = ("juggler", 8080)
ADMIN = ("juggler", 9090)
GRPC = ("juggler", 50051)
OUT = "/results_reverify.json"

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

RES = {"ts": time.strftime("%Y-%m-%d %H:%M:%S")}


def http(host, port, raw, timeout=8, wait=3.0):
    try:
        s = socket.create_connection((host, port), timeout=timeout)
        s.sendall(raw)
        s.settimeout(wait)
        d = b""
        while True:
            try:
                c = s.recv(65536)
            except socket.timeout:
                break
            if not c:
                break
            d += c
        s.close()
        return d
    except OSError as e:
        return f"EXC:{type(e).__name__}".encode()


def grpc_update(routes: dict, token: str | None):
    """Minimal h2 gRPC client using grpcio if present, else raw HTTP/2."""
    try:
        import grpc  # noqa: PLC0415
        sys.path.insert(0, "/pb")
        import control_plane_pb2 as pb  # noqa: PLC0415
        import control_plane_pb2_grpc  # noqa: PLC0415
        req = pb.UpdateConfigRequest(routes={
            p: pb.RouteConfig(upstream_addrs=[u]) for p, u in routes.items()})
        stub = control_plane_pb2_grpc.ControlPlaneStub(
            grpc.insecure_channel("juggler:50051"))
        md = [("authorization", f"Bearer {token}")] if token else []
        stub.UpdateConfig(req, timeout=6, metadata=md)
        return "OK"
    except Exception as e:  # noqa: BLE001
        name = type(e).__name__
        msg = str(e)
        if "UNAUTHENTICATED" in msg:
            return "UNAUTHENTICATED"
        if "PERMISSION_DENIED" in msg:
            return "PERMISSION_DENIED"
        if "DEADLINE_EXCEEDED" in msg:
            return "TIMEOUT"
        return f"{name}: {msg[:100]}"


def v1_v2():
    print("--- V1/V2 gRPC authz", flush=True)
    probe = {"/grpcprobe9": "172.19.0.12:9199"}
    RES["V1_grpc_unauth"] = {"result": grpc_update(probe, None)}
    RES["V2_grpc_auth"] = {"result": grpc_update(
        {"/bench": "backend:9000"}, "bench")}
    # cleanup probe route if V1 unexpectedly succeeded
    if "OK" in RES["V1_grpc_unauth"]["result"]:
        grpc_update({"/bench": "backend:9000", "/echo": "172.19.0.12:9100",
                     "/hangup": "172.19.0.12:9101", "/rst": "172.19.0.12:9102",
                     "/garbage": "172.19.0.12:9103", "/bighead": "172.19.0.12:9104",
                     "/gorgon": "172.19.0.12:9105", "/counter": "172.19.0.12:9107",
                     "/flaky": "172.19.0.12:9108", "/deadup": "172.19.0.12:9106"},
                    "bench")


def v3_conflicting_cl():
    print("--- V3 conflicting CL", flush=True)
    d = http(*JUG, b"POST /echo HTTP/1.1\r\nHost: x\r\nContent-Length: 5\r\n"
                    b"Content-Length: 3\r\nConnection: close\r\n\r\nabcde")
    st = d.split(b"\r\n")[0].decode(errors="replace") if d else "EMPTY"
    ok = b"200" not in d[:40]
    RES["V3_conflicting_cl"] = {"status": st, "verdict": "PASS" if ok else "FAIL"}


def v4_huge_headers_431():
    print("--- V4 431 on huge headers", flush=True)
    fields = b"".join(b"X-F%04d: " % i + b"A" * 8000 + b"\r\n" for i in range(60))
    d = http(*JUG, b"GET /bench HTTP/1.1\r\nHost: x\r\n" + fields +
             b"Connection: close\r\n\r\n", timeout=10, wait=6)
    st = d.split(b"\r\n")[0].decode(errors="replace") if d else "EMPTY(closed)"
    ok = b"431" in d[:40]
    RES["V4_huge_headers_431"] = {"status": st, "verdict": "PASS" if ok else "WARN"}


def v5_lone_cr():
    print("--- V5 lone CR", flush=True)
    d = http(*JUG, b"GET /echo HTTP/1.1\r\nHost: x\r\nX-Bad: v\rv: 1\r\n"
                    b"Connection: close\r\n\r\n")
    st = d.split(b"\r\n")[0].decode(errors="replace") if d else "EMPTY"
    ok = b"400" in d[:40] or b"502" in d[:40] or d == b""
    RES["V5_lone_cr"] = {"status": st, "verdict": "PASS" if ok else "WARN"}


def v6_malformed_chunk():
    print("--- V6 malformed chunk", flush=True)
    d = http(*JUG, b"POST /echo HTTP/1.1\r\nHost: x\r\n"
                    b"Transfer-Encoding: chunked\r\nConnection: close\r\n\r\n"
                    b"ZZZZ\r\n0\r\n\r\n")
    st = d.split(b"\r\n")[0].decode(errors="replace") if d else "EMPTY"
    ok = b"200" not in d[:40]
    RES["V6_malformed_chunk"] = {"status": st, "verdict": "PASS" if ok else "WARN"}


def main():
    v1_v2()
    v3_conflicting_cl()
    v4_huge_headers_431()
    v5_lone_cr()
    v6_malformed_chunk()
    json.dump(RES, open(OUT, "w"), indent=1)
    print(json.dumps(RES, indent=1), flush=True)


if __name__ == "__main__":
    main()
