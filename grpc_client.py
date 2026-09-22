#!/usr/bin/env python3
"""gRPC Control Plane client for the Juggler server.

Compiles proto/control_plane.proto on the fly, then hot-reloads the routing
table of a running server. Routes are validated server-side (must start with
'/', must have at least one upstream).

Usage:
    python grpc_client.py                # apply the default demo routes
    python grpc_client.py routes.json    # apply routes from a JSON file:
                                         # {"routes": {"/api": {"upstream_addrs": ["127.0.0.1:7001"]}}}
"""
import json
import sys
from importlib import import_module

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

SERVER_ADDR = "127.0.0.1:50051"

DEFAULT_ROUTES = {
    "routes": {
        "/api": {
            "upstream_addrs": ["127.0.0.1:7001", "127.0.0.1:7002"],
            "wasm_plugin": None,
        },
    }
}


def compile_proto():
    from grpc_tools import protoc

    print("🔨 Compiling protobuf contract for Python...")
    rc = protoc.main((
        "",
        "-Iproto",
        "--python_out=.",
        "--grpc_python_out=.",
        "proto/control_plane.proto",
    ))
    if rc != 0:
        sys.exit("failed to compile proto/control_plane.proto")
    return import_module("control_plane_pb2"), import_module("control_plane_pb2_grpc")


def apply_routes(routes_dict):
    import grpc

    pb2, pb2_grpc = compile_proto()

    pb_routes = {}
    for path, cfg in routes_dict.items():
        sec = cfg.get("security") or {}
        pb_routes[path] = pb2.RouteConfig(
            upstream_addrs=cfg.get("upstream_addrs", []),
            wasm_plugin=cfg.get("wasm_plugin"),
            security=pb2.SecurityConfig(
                deny_substrings=sec.get("deny_substrings", []),
                response_headers={k: v for k, v in sec.get("response_headers", [])},
                cms_fallback_path=sec.get("cms_fallback_path"),
            ) if sec else None,
        )

    with grpc.insecure_channel(SERVER_ADDR) as channel:
        stub = pb2_grpc.ControlPlaneStub(channel)
        request = pb2.UpdateConfigRequest(routes=pb_routes)
        # Server-side authz: mutating RPCs require Authorization: Bearer
        # <JUGGLER_GRPC_TOKEN> (or the admin token fallback). Send it when
        # configured so existing deployments keep working unmodified.
        import os
        metadata = []
        tok = os.environ.get("JUGGLER_GRPC_TOKEN") or os.environ.get("JUGGLER_ADMIN_TOKEN")
        if tok:
            metadata.append(("authorization", f"Bearer {tok}"))
        response = stub.UpdateConfig(request, timeout=5, metadata=metadata)
        return response.success, response.message


if __name__ == "__main__":
    payload = DEFAULT_ROUTES
    if len(sys.argv) > 1:
        with open(sys.argv[1], "r", encoding="utf-8") as f:
            payload = json.load(f)

    success, message = apply_routes(payload["routes"])
    if success:
        print(f"✅ Server accepted config: {message}")
        for path, cfg in payload["routes"].items():
            print(f"   {path} -> {', '.join(cfg['upstream_addrs'])}")
    else:
        print(f"❌ Server rejected config: {message}")
        sys.exit(1)
