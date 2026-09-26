//! Central Prometheus registry: all server metric families, protocol
//! labels, and the exposition encoder shared by the data-plane `/metrics`
//! and the admin-plane `/api/metrics` endpoints.

use lazy_static::lazy_static;
use prometheus::{
    HistogramOpts, HistogramVec, IntCounter, IntCounterVec, IntGauge, IntGaugeVec, Opts, Registry,
};

pub const PROTO_H1: &str = "h1";
pub const PROTO_H2: &str = "h2";
pub const PROTO_H3: &str = "h3";

lazy_static! {
    pub static ref REGISTRY: Registry = Registry::new();
    pub static ref H3_CONNECTIONS_TOTAL: IntCounter = IntCounter::new(
        "h3_connections_total",
        "QUIC connections accepted by the HTTP/3 data plane",
    )
    .unwrap();
    pub static ref HTTP_REQUESTS_TOTAL: IntCounterVec = IntCounterVec::new(
        Opts::new(
            "http_requests_total",
            "Total number of HTTP requests served by the Data Plane",
        ),
        &["path", "status", "proto"],
    )
    .unwrap();
    pub static ref HTTP_RESPONSE_SECONDS: HistogramVec = HistogramVec::new(
        HistogramOpts::new(
            "http_response_seconds",
            "End-to-end latency of requests served by the Data Plane",
        )
        .buckets(vec![
            0.0005, 0.001, 0.0025, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5
        ]),
        &["path"],
    )
    .unwrap();
    pub static ref UPSTREAM_REQUESTS_TOTAL: IntCounterVec = IntCounterVec::new(
        Opts::new(
            "upstream_requests_total",
            "Requests routed to a specific upstream and their outcome",
        ),
        &["upstream", "outcome"],
    )
    .unwrap();
    pub static ref UPSTREAM_FAILOVER_TOTAL: IntCounter = IntCounter::new(
        "upstream_failover_total",
        "Times a failed upstream triggered a retry on another upstream",
    )
    .unwrap();
    pub static ref KEEPALIVE_CONNECTIONS_TOTAL: IntCounter = IntCounter::new(
        "keepalive_connections_total",
        "Client connections that served more than one request",
    )
    .unwrap();
    pub static ref RATE_LIMIT_REJECTED: IntCounterVec = IntCounterVec::new(
        Opts::new(
            "rate_limit_rejected_total",
            "Requests rejected by the rate limiter",
        ),
        &["reason"],
    )
    .unwrap();
    pub static ref ACTIVE_UPSTREAM_CONNECTIONS: IntGauge =
        IntGauge::new("active_upstream_connections", "In-flight proxied requests").unwrap();
    pub static ref WASM_PLUGIN_CALLS_TOTAL: IntCounterVec = IntCounterVec::new(
        Opts::new(
            "wasm_plugin_calls_total",
            "Per-request WASM filter invocations by pipeline stage and outcome",
        ),
        &["stage", "outcome"],
    )
    .unwrap();
    pub static ref WASM_PLUGIN_SECONDS: HistogramVec = HistogramVec::new(
        HistogramOpts::new(
            "wasm_plugin_seconds",
            "Time spent inside WASM filter invocations",
        )
        .buckets(vec![
            0.00005, 0.0001, 0.00025, 0.0005, 0.001, 0.0025, 0.005, 0.01
        ]),
        &["stage"],
    )
    .unwrap();
    pub static ref WASM_MODULES_LOADED: IntGauge = IntGauge::new(
        "wasm_modules_loaded",
        "WASM modules currently in the registry"
    )
    .unwrap();
    pub static ref UPSTREAM_HEALTH_STATE: IntGaugeVec = IntGaugeVec::new(
        Opts::new(
            "upstream_health_state",
            "Passive health state per upstream (1 = the label state is current)",
        ),
        &["upstream", "state"],
    )
    .unwrap();
    pub static ref UPSTREAM_POOL_REUSES_TOTAL: IntCounter = IntCounter::new(
        "upstream_pool_reuses_total",
        "Upstream requests served over a reused keep-alive connection",
    )
    .unwrap();
    pub static ref UPSTREAM_STALE_RETRIES_TOTAL: IntCounter = IntCounter::new(
        "upstream_stale_retries_total",
        "Requests recovered by retrying a fresh connection after a stale pooled socket",
    )
    .unwrap();
    pub static ref UPSTREAM_POOL_SIZE: IntGaugeVec = IntGaugeVec::new(
        Opts::new(
            "upstream_pool_size",
            "Idle keep-alive sockets currently pooled per upstream",
        ),
        &["upstream"],
    )
    .unwrap();
    pub static ref SHARD_ACTIVE_CONNS: IntGaugeVec = IntGaugeVec::new(
        Opts::new(
            "shard_active_conns",
            "Active client connections per shard (0..N cores)",
        ),
        &["shard"],
    )
    .unwrap();
    pub static ref SHARD_FORECAST_CONNS: IntGaugeVec = IntGaugeVec::new(
        Opts::new(
            "shard_forecast_conns",
            "Holt one-step-ahead forecast of shard active connections",
        ),
        &["shard"],
    )
    .unwrap();
    pub static ref UPSTREAM_FORECAST_LOAD: IntGaugeVec = IntGaugeVec::new(
        Opts::new(
            "upstream_forecast_load",
            "Predicted in-flight load per upstream (level + trend)",
        ),
        &["upstream"],
    )
    .unwrap();
}

pub fn register_metrics() {
    // Touch every labelled metric once so all series exist with zero values:
    // Prometheus must be able to scrape a complete metric family even before
    // the first request arrives.
    HTTP_REQUESTS_TOTAL
        .with_label_values(&["init", "0", "init"])
        .inc_by(0);
    HTTP_RESPONSE_SECONDS
        .with_label_values(&["init"])
        .observe(0.0);
    UPSTREAM_REQUESTS_TOTAL
        .with_label_values(&["init", "ok"])
        .inc_by(0);
    UPSTREAM_FAILOVER_TOTAL.inc_by(0);
    KEEPALIVE_CONNECTIONS_TOTAL.inc_by(0);
    RATE_LIMIT_REJECTED.with_label_values(&["init"]).inc_by(0);
    WASM_PLUGIN_CALLS_TOTAL
        .with_label_values(&["init", "ok"])
        .inc_by(0);
    WASM_PLUGIN_SECONDS
        .with_label_values(&["init"])
        .observe(0.0);
    WASM_MODULES_LOADED.set(0);
    ACTIVE_UPSTREAM_CONNECTIONS.set(0);
    UPSTREAM_HEALTH_STATE
        .with_label_values(&["init", "healthy"])
        .set(0);
    UPSTREAM_POOL_REUSES_TOTAL.inc_by(0);
    UPSTREAM_STALE_RETRIES_TOTAL.inc_by(0);
    UPSTREAM_POOL_SIZE.with_label_values(&["init"]).set(0);
    SHARD_ACTIVE_CONNS.with_label_values(&["init"]).set(0);
    SHARD_FORECAST_CONNS.with_label_values(&["init"]).set(0);
    UPSTREAM_FORECAST_LOAD.with_label_values(&["init"]).set(0);

    REGISTRY
        .register(Box::new(H3_CONNECTIONS_TOTAL.clone()))
        .unwrap();
    REGISTRY
        .register(Box::new(HTTP_REQUESTS_TOTAL.clone()))
        .unwrap();
    REGISTRY
        .register(Box::new(HTTP_RESPONSE_SECONDS.clone()))
        .unwrap();
    REGISTRY
        .register(Box::new(UPSTREAM_REQUESTS_TOTAL.clone()))
        .unwrap();
    REGISTRY
        .register(Box::new(UPSTREAM_FAILOVER_TOTAL.clone()))
        .unwrap();
    REGISTRY
        .register(Box::new(KEEPALIVE_CONNECTIONS_TOTAL.clone()))
        .unwrap();
    REGISTRY
        .register(Box::new(RATE_LIMIT_REJECTED.clone()))
        .unwrap();
    REGISTRY
        .register(Box::new(ACTIVE_UPSTREAM_CONNECTIONS.clone()))
        .unwrap();
    REGISTRY
        .register(Box::new(UPSTREAM_POOL_REUSES_TOTAL.clone()))
        .unwrap();
    REGISTRY
        .register(Box::new(UPSTREAM_STALE_RETRIES_TOTAL.clone()))
        .unwrap();
    REGISTRY
        .register(Box::new(UPSTREAM_POOL_SIZE.clone()))
        .unwrap();
    REGISTRY
        .register(Box::new(WASM_PLUGIN_CALLS_TOTAL.clone()))
        .unwrap();
    REGISTRY
        .register(Box::new(WASM_PLUGIN_SECONDS.clone()))
        .unwrap();
    REGISTRY
        .register(Box::new(WASM_MODULES_LOADED.clone()))
        .unwrap();
    REGISTRY
        .register(Box::new(UPSTREAM_HEALTH_STATE.clone()))
        .unwrap();
    REGISTRY
        .register(Box::new(SHARD_ACTIVE_CONNS.clone()))
        .unwrap();
    REGISTRY
        .register(Box::new(SHARD_FORECAST_CONNS.clone()))
        .unwrap();
    REGISTRY
        .register(Box::new(UPSTREAM_FORECAST_LOAD.clone()))
        .unwrap();
}

