# syntax=docker/dockerfile:1
# Multi-stage build. protoc: the repo vendors the win64 binary, Linux builds
# (CI, Docker) install protobuf-compiler from apt instead.

FROM rust:1-slim AS builder
WORKDIR /usr/src/juggler

RUN apt-get update \
    && apt-get install -y --no-install-recommends protobuf-compiler \
    && rm -rf /var/lib/apt/lists/*

# Cache dependencies between builds.
COPY Cargo.toml build.rs ./
COPY proto ./proto
RUN mkdir -p src && echo "fn main() {}" > src/main.rs && cargo build --release && rm -rf src

# Build the real binary.
COPY src ./src
RUN touch src/main.rs && cargo build --release

FROM debian:bookworm-slim
WORKDIR /app
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates \
    && rm -rf /var/lib/apt/lists/*
COPY --from=builder /usr/src/juggler/target/release/juggler /app/juggler

# 8443  = Data Plane (HTTPS reverse proxy)
# 50051 = Control Plane (gRPC, hot config reload)
EXPOSE 8443 50051

# Runtime configuration (all optional):
#   JUGGLER_HTTP_ADDR   - Data Plane listen address (default 127.0.0.1:8443)
#   JUGGLER_GRPC_ADDR   - Control Plane listen address (default 127.0.0.1:50051)
#   JUGGLER_ROUTES_FILE - route persistence file (default routes.json)
ENV JUGGLER_HTTP_ADDR=0.0.0.0:8443
ENV JUGGLER_GRPC_ADDR=0.0.0.0:50051
CMD ["/app/juggler"]
