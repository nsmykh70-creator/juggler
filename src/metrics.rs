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
        .ok();
    REGISTRY
        .register(Box::new(HTTP_REQUESTS_TOTAL.clone()))
        .ok();
    REGISTRY
        .register(Box::new(HTTP_RESPONSE_SECONDS.clone()))
        .ok();
    REGISTRY
        .register(Box::new(UPSTREAM_REQUESTS_TOTAL.clone()))
        .ok();
    REGISTRY
        .register(Box::new(UPSTREAM_FAILOVER_TOTAL.clone()))
        .ok();
    REGISTRY
        .register(Box::new(KEEPALIVE_CONNECTIONS_TOTAL.clone()))
        .ok();
    REGISTRY
        .register(Box::new(RATE_LIMIT_REJECTED.clone()))
        .ok();
    REGISTRY
        .register(Box::new(ACTIVE_UPSTREAM_CONNECTIONS.clone()))
        .ok();
    REGISTRY
        .register(Box::new(UPSTREAM_POOL_REUSES_TOTAL.clone()))
        .ok();
    REGISTRY
        .register(Box::new(UPSTREAM_STALE_RETRIES_TOTAL.clone()))
        .ok();
    REGISTRY.register(Box::new(UPSTREAM_POOL_SIZE.clone())).ok();
    REGISTRY
        .register(Box::new(WASM_PLUGIN_CALLS_TOTAL.clone()))
        .ok();
    REGISTRY
        .register(Box::new(WASM_PLUGIN_SECONDS.clone()))
        .ok();
    REGISTRY
        .register(Box::new(WASM_MODULES_LOADED.clone()))
        .ok();
    REGISTRY
        .register(Box::new(UPSTREAM_HEALTH_STATE.clone()))
        .ok();
    REGISTRY.register(Box::new(SHARD_ACTIVE_CONNS.clone())).ok();
    REGISTRY
        .register(Box::new(SHARD_FORECAST_CONNS.clone()))
        .ok();
    REGISTRY
        .register(Box::new(UPSTREAM_FORECAST_LOAD.clone()))
        .ok();
}

#[cfg(test)]
mod tests {
    use super::*;
    use prometheus::Encoder;

    /// Every metric family referenced by the provisioned Grafana dashboard
    /// (monitoring/dashboards) and the alert rules (monitoring/alerts.yml)
    /// must actually exist in the exposition. A renamed/removed metric is a
    /// silent monitoring outage: the dashboard renders "No data" and the
    /// alert never fires. This test fails loudly instead.
    #[test]
    fn exposition_contains_every_dashboard_and_alert_metric() {
        register_metrics();
        let encoder = prometheus::TextEncoder::new();
        let mut buf = Vec::new();
        encoder
            .encode(&REGISTRY.gather(), &mut buf)
            .expect("text exposition");
        let text = String::from_utf8(buf).unwrap();

        // Real metric names on the wire: histogram buckets are exposed as
        // `<name>_bucket`, so the dashboard's `http_response_seconds_bucket`
        // maps to the `http_response_seconds` family.
        let families: Vec<&str> = text
            .lines()
            .filter(|l| l.starts_with("# TYPE "))
            .filter_map(|l| l.split_whitespace().nth(2))
            .collect();

        // (family on the wire, name as referenced by dashboard/alerts)
        for required in [
            ("http_requests_total", "http_requests_total"),
            ("http_response_seconds", "http_response_seconds_bucket"),
            ("keepalive_connections_total", "keepalive_connections_total"),
            ("rate_limit_rejected_total", "rate_limit_rejected_total"),
            ("shard_active_conns", "shard_active_conns"),
            ("shard_forecast_conns", "shard_forecast_conns"),
            ("upstream_failover_total", "upstream_failover_total"),
            ("upstream_pool_reuses_total", "upstream_pool_reuses_total"),
            ("upstream_pool_size", "upstream_pool_size"),
            ("upstream_requests_total", "upstream_requests_total"),
            (
                "upstream_stale_retries_total",
                "upstream_stale_retries_total",
            ),
            ("h3_connections_total", "h3_connections_total"),
        ] {
            let (family, referenced_as) = required;
            assert!(
                families.contains(&family),
                "metric `{referenced_as}` referenced by dashboard/alerts is missing from \
                 exposition; update the dashboard/alerts or restore the metric"
            );
        }
    }

    /// Counters must MOVE through the real request path: resolve_route on
    /// the /healthz builtin increments http_requests_total with the actual
    /// path/proto labels. Guard against the classic telemetry failure where
    /// a response is served but never accounted (the H2-static regression).
    #[test]
    fn counters_move_through_resolve_route() {
        register_metrics();
        let encoder = prometheus::TextEncoder::new();
        let read_counter = |labels: (&str, &str, &str)| -> u64 {
            let mut buf = Vec::new();
            encoder
                .encode(&REGISTRY.gather(), &mut buf)
                .expect("text exposition");
            String::from_utf8(buf)
                .unwrap()
                .lines()
                .find(|l| {
                    l.starts_with("http_requests_total{")
                        && l.contains(&format!("path={:?}", labels.0))
                        && l.contains(&format!("status={:?}", labels.1))
                        && l.contains(&format!("proto={:?}", labels.2))
                })
                .and_then(|l| l.rsplit(' ').next())
                .and_then(|v| v.parse::<u64>().ok())
                .unwrap_or(0) // series absent before the first request == 0
        };

        let config = std::sync::Arc::new(arc_swap::ArcSwap::from_pointee(
            crate::ServerConfig::default(),
        ));
        let ip: std::net::IpAddr = "192.0.2.10".parse().unwrap();
        let mut body = Vec::new();
        let before = read_counter(("/healthz", "200", PROTO_H1));
        // The process-wide limiter may already carry credit consumed by
        // other tests; retry a few times and only fail on sustained 429s.
        let mut decision = crate::resolve_route("/healthz", &mut body, ip, PROTO_H1, &config);
        for _ in 0..10 {
            match decision {
                crate::RouteDecision::Builtin(200, _) => break,
                crate::RouteDecision::RateLimited => {
                    std::thread::sleep(std::time::Duration::from_millis(50));
                    decision = crate::resolve_route("/healthz", &mut body, ip, PROTO_H1, &config);
                }
                other => panic!("unexpected decision: {other:?}"),
            }
        }
        assert!(
            matches!(decision, crate::RouteDecision::Builtin(200, _)),
            "limiter starved the test; /healthz must stay accounted"
        );
        let after = read_counter(("/healthz", "200", PROTO_H1));
        assert!(
            after > before,
            "resolve_route must account the request (before={before}, after={after})"
        );
    }

    /// Parse the REAL monitoring artifacts on disk (provisioned dashboard +
    /// alert rules) and verify every metric they reference exists in the
    /// exposition. Catches rename/remove drift in either direction.
    #[test]
    fn monitoring_artifacts_reference_existing_metrics_only() {
        register_metrics();
        let repo = env!("CARGO_MANIFEST_DIR");
        let dash = std::fs::read_to_string(format!("{repo}/monitoring/dashboards/juggler.json"))
            .expect("provisioned dashboard present");
        let dash = format!(
            "{dash}\n{}",
            std::fs::read_to_string(format!(
                "{repo}/monitoring/dashboards/juggler-ab-accept.json"
            ))
            .expect("A/B dashboard present")
        );
        let alerts = std::fs::read_to_string(format!("{repo}/monitoring/alerts.yml"))
            .expect("alert rules present");

        // Extract metric names: identifiers followed by [ (range selector)
        // or { (label matcher). For the dashboard JSON this runs per-expr
        // string so legend/template strings cannot inject false positives.
        let mut referenced = std::collections::BTreeSet::new();
        let collect = |t: &str, out: &mut std::collections::BTreeSet<String>| {
            let bytes = t.as_bytes();
            let mut i = 0;
            while i < bytes.len() {
                if bytes[i].is_ascii_lowercase() {
                    let s = i;
                    while i < bytes.len()
                        && (bytes[i].is_ascii_lowercase()
                            || bytes[i] == b'_'
                            || bytes[i].is_ascii_digit())
                    {
                        i += 1;
                    }
                    if i < bytes.len() && (bytes[i] == b'[' || bytes[i] == b'{') {
                        out.insert(t[s..i].to_string());
                    }
                } else {
                    i += 1;
                }
            }
        };
        let mut idx = 0;
        while let Some(p) = dash[idx..].find("\"expr\": \"") {
            let start = idx + p + 9;
            let end = dash[start..]
                .find('"')
                .map(|e| start + e)
                .unwrap_or(start + 1);
            collect(&dash[start..end], &mut referenced);
            idx = end;
        }
        collect(&alerts, &mut referenced);

        let encoder = prometheus::TextEncoder::new();
        let mut buf = Vec::new();
        encoder
            .encode(&REGISTRY.gather(), &mut buf)
            .expect("text exposition");
        let exposition = String::from_utf8(buf).unwrap();
        let families: Vec<&str> = exposition
            .lines()
            .filter(|l| l.starts_with("# TYPE "))
            .filter_map(|l| l.split_whitespace().nth(2))
            .collect();
        for metric in referenced {
            if metric == "up" {
                continue; // produced by Prometheus itself
            }
            // Accept either the family name itself or a histogram-bucket
            // reference (`http_response_seconds_bucket` -> family
            // `http_response_seconds`). Counter families keep their `_total`
            // suffix on the wire, so no other stripping is valid.
            let ok = families.contains(&metric.as_str())
                || metric
                    .strip_suffix("_bucket")
                    .map(|b| families.contains(&b))
                    .unwrap_or(false);
            assert!(
                ok,
                "monitoring artifact references metric `{metric}` that the server does not expose"
            );
        }
    }
}
