#!/usr/bin/env python3
"""Point the benchmark Juggler at the in-network static backend.

The bench backend lives at backend:9000 inside the docker network; the
route is applied through the Control Plane gRPC (published to the host as
127.0.0.1:50051 by bench/docker-compose.bench.yml).
"""
import sys
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from grpc_client import apply_routes  # noqa: E402

success, message = apply_routes({
    "/bench": {"upstream_addrs": ["backend:9000"]},
})
print(f"{'✅' if success else '❌'} {message}")
sys.exit(0 if success else 1)
