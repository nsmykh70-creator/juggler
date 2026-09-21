//! Juggler — Thread-per-Core HTTPS reverse proxy with a gRPC Control Plane.
//!
//! Like its namesake, Juggler keeps many things in the air at once:
//! thousands of connections, hot-reloaded routes, and a pool of upstreams —
//! without dropping a single ball.
//!
//! Architecture:
//!   - Data Plane (port 8443): HTTPS terminator + reverse proxy. HTTP/2 is
//!     negotiated via ALPN (`h2` alongside `http/1.1`); HTTP/2 requests are
//!     multiplexed and re-encoded to HTTP/1.1 towards the upstreams. Features
//!     on both protocols: Least-Connections balancing with random tie-break,
//!     upstream failover, Token-Bucket per-IP rate limiting, keep-alive,
//!     `X-Forwarded-For` / `X-Request-Id` injection, `/metrics` and `/healthz`.
//!   - Control Plane (port 50051): gRPC service for hot-reloading the routing
//!     table without dropping a single connection (ArcSwap pointer swap).
//!
//! Runtime overrides (environment variables):
//!   JUGGLER_HTTP_ADDR   (default "127.0.0.1:8443")
//!   JUGGLER_GRPC_ADDR   (default "127.0.0.1:50051")
//!   JUGGLER_ROUTES_FILE (default "routes.json"; created empty if missing)

use std::collections::HashMap;
use std::net::{IpAddr, SocketAddr};
use std::sync::atomic::{AtomicBool, AtomicI64, AtomicU64, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex, OnceLock};
use std::time::{Duration, Instant};

use anyhow::Result;
use arc_swap::ArcSwap;
use bytes::{BufMut, Bytes, BytesMut};
use core_affinity::CoreId;
use h2::server::SendResponse;
use h2::RecvStream;
use http::HeaderMap;
use lazy_static::lazy_static;
use prometheus::{
    Encoder, HistogramOpts, HistogramVec, IntCounter, IntCounterVec, IntGauge, IntGaugeVec, Opts,
    Registry, TextEncoder,
};
use serde::{Deserialize, Serialize};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::TcpStream;
use tokio_rustls::TlsAcceptor;

use rustls_pki_types::{CertificateDer, PrivateKeyDer};

use tonic::{Request, Response, Status, transport::Server};
pub use pb::control_plane_server::{ControlPlane, ControlPlaneServer};
pub use pb::{RouteConfig as PbRouteConfig, UpdateConfigRequest, UpdateConfigResponse};

/// Generated protobuf/gRPC code (compiled from proto/control_plane.proto).
pub mod pb {
    tonic::include_proto!("juggler.control");
}

// Global allocator: mimalloc handles the per-request alloc/free churn of a
// proxy far better than glibc malloc and returns freed pages to the OS,
// so memory under 10k-connection storms stays proportional to live data.
#[global_allocator]
static GLOBAL_ALLOC: mimalloc::MiMalloc = mimalloc::MiMalloc;

#[cfg(target_family = "unix")]
extern "C" {
    /// mimalloc: release all free memory back to the OS where possible.
    fn mi_collect();
}

const PROTO_H1: &str = "h1";
const PROTO_H2: &str = "h2";

// ============================================================================
// Prometheus metrics
// ============================================================================

lazy_static! {
    pub static ref REGISTRY: Registry = Registry::new();
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
        .buckets(vec![0.0005, 0.001, 0.0025, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5]),
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
        .buckets(vec![0.00005, 0.0001, 0.00025, 0.0005, 0.001, 0.0025, 0.005, 0.01]),
        &["stage"],
    )
    .unwrap();
    pub static ref WASM_MODULES_LOADED: IntGauge =
        IntGauge::new("wasm_modules_loaded", "WASM modules currently in the registry").unwrap();
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

fn register_metrics() {
    // Touch every labelled metric once so all series exist with zero values:
    // Prometheus must be able to scrape a complete metric family even before
    // the first request arrives.
    HTTP_REQUESTS_TOTAL
        .with_label_values(&["init", "0", "init"])
        .inc_by(0);
    HTTP_RESPONSE_SECONDS.with_label_values(&["init"]).observe(0.0);
    UPSTREAM_REQUESTS_TOTAL
        .with_label_values(&["init", "ok"])
        .inc_by(0);
    UPSTREAM_FAILOVER_TOTAL.inc_by(0);
    KEEPALIVE_CONNECTIONS_TOTAL.inc_by(0);
    RATE_LIMIT_REJECTED.with_label_values(&["init"]).inc_by(0);
    WASM_PLUGIN_CALLS_TOTAL
        .with_label_values(&["init", "ok"])
        .inc_by(0);
    WASM_PLUGIN_SECONDS.with_label_values(&["init"]).observe(0.0);
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

    REGISTRY.register(Box::new(HTTP_REQUESTS_TOTAL.clone())).unwrap();
    REGISTRY.register(Box::new(HTTP_RESPONSE_SECONDS.clone())).unwrap();
    REGISTRY.register(Box::new(UPSTREAM_REQUESTS_TOTAL.clone())).unwrap();
    REGISTRY.register(Box::new(UPSTREAM_FAILOVER_TOTAL.clone())).unwrap();
    REGISTRY.register(Box::new(KEEPALIVE_CONNECTIONS_TOTAL.clone())).unwrap();
    REGISTRY.register(Box::new(RATE_LIMIT_REJECTED.clone())).unwrap();
    REGISTRY.register(Box::new(ACTIVE_UPSTREAM_CONNECTIONS.clone())).unwrap();
    REGISTRY.register(Box::new(UPSTREAM_POOL_REUSES_TOTAL.clone())).unwrap();
    REGISTRY.register(Box::new(UPSTREAM_STALE_RETRIES_TOTAL.clone())).unwrap();
    REGISTRY.register(Box::new(UPSTREAM_POOL_SIZE.clone())).unwrap();
    REGISTRY.register(Box::new(WASM_PLUGIN_CALLS_TOTAL.clone())).unwrap();
    REGISTRY.register(Box::new(WASM_PLUGIN_SECONDS.clone())).unwrap();
    REGISTRY.register(Box::new(WASM_MODULES_LOADED.clone())).unwrap();
    REGISTRY.register(Box::new(UPSTREAM_HEALTH_STATE.clone())).unwrap();
    REGISTRY.register(Box::new(SHARD_ACTIVE_CONNS.clone())).unwrap();
    REGISTRY.register(Box::new(SHARD_FORECAST_CONNS.clone())).unwrap();
    REGISTRY.register(Box::new(UPSTREAM_FORECAST_LOAD.clone())).unwrap();
}

// ============================================================================
// Shard telemetry: per-shard load tracking with an EMA + Holt-forecast
// ============================================================================
//
// The admin UI and /metrics need an answer to two questions: which shard is
// busiest right now, and where is load heading (trend)? Each shard thread
// reports two atomics (active conns, accepted conns); a sampler thread folds
// the samples into an EMA (smoothing) plus a Holt linear trend (forecast of
// the next sample). Pure functions are unit-tested.

/// One shard's live counters, updated by the shard thread itself.
#[derive(Default)]
struct ShardCounters {
    active_conns: AtomicUsize,
    accepted_total: AtomicUsize,
}

static SHARD_COUNTERS: OnceLock<Vec<ShardCounters>> = OnceLock::new();

/// Register N shard counter slots. Must be called before shard threads spawn.
fn init_shard_counters(n: usize) {
    let _ = SHARD_COUNTERS.set((0..n).map(|_| ShardCounters::default()).collect());
}

fn shard_counters() -> &'static [ShardCounters] {
    SHARD_COUNTERS.get().map(|v| v.as_slice()).unwrap_or(&[])
}

/// EMA smoothing factor and Holt trend factor.
const EMA_ALPHA: f64 = 0.3;
const TREND_BETA: f64 = 0.1;

/// Holt's linear trend over an EMA-smoothed series: level + trend, i.e. a
/// one-step-ahead forecast of the next sample. (level, trend) start at 0.
fn holt_forecast(prev_level: f64, prev_trend: f64, sample: f64) -> (f64, f64) {
    let level = EMA_ALPHA * sample + (1.0 - EMA_ALPHA) * (prev_level + prev_trend);
    let trend = TREND_BETA * (level - prev_level) + (1.0 - TREND_BETA) * prev_trend;
    (level, trend)
}

/// A single sample of one shard's load: current active connections plus the
/// Holt forecast of the next sample (>= current: load growing, <: falling).
#[derive(Debug, Clone, Serialize)]
struct ShardStat {
    shard: usize,
    active_conns: u64,
    accepted_total: u64,
    /// Smoothed level (EMA) of active connections.
    level: f64,
    /// Holt trend: positive = load growing, negative = draining.
    trend: f64,
    /// level + trend: predicted load one sample ahead.
    forecast: f64,
}

/// Shared (level, trend) per shard, folded by the sampler thread.
fn shard_holt_state() -> &'static Mutex<Vec<(f64, f64)>> {
    static STATE: OnceLock<Mutex<Vec<(f64, f64)>>> = OnceLock::new();
    STATE.get_or_init(|| Mutex::new(Vec::new()))
}

/// Fold the latest raw samples into the shared telemetry state.
fn update_shard_stats(samples: &[u64]) {
    // Forecast core v2: lock-free fixed-point cells (hot-path readers take
    // no locks — the balancer reads the same cells directly).
    let fc = shard_fc();
    let max = samples.iter().copied().max().unwrap_or(1).max(1) as f64;
    for (i, &s) in samples.iter().enumerate() {
        if let Some(cell) = fc.cell(i) {
            cell.fold(s as f64, max);
        }
    }
    // Legacy f64 Holt state: kept for the admin sparklines until the
    // dashboard reads the v2 core directly (see history_snapshot users).
    let mut st = shard_holt_state().lock().unwrap();
    st.resize(samples.len(), (0.0, 0.0));
    for (i, &s) in samples.iter().enumerate() {
        let (l, t) = st[i];
        st[i] = holt_forecast(l, t, s as f64);
    }
    // Stage latency totals: fold per-core accumulators once per sample.
    let totals = fold_stage_totals();
    *stage_totals_slot().lock().unwrap() = Some((Instant::now(), totals));
}

/// Dashboard sparkline history: one ring buffer of past Holt samples per
/// shard. Capacity 120 points x 2 s sampling = 4 minutes of live forecast
/// history, enough to see balancer behaviour without unbounded memory.
#[derive(Clone, Copy)]
struct HistPoint {
    level: f64,
    forecast: f64,
}

const FORECAST_HISTORY_CAP: usize = 120;

fn forecast_history() -> &'static Mutex<Vec<std::collections::VecDeque<HistPoint>>> {
    static H: OnceLock<Mutex<Vec<std::collections::VecDeque<HistPoint>>>> = OnceLock::new();
    H.get_or_init(|| Mutex::new(Vec::new()))
}

/// Append one sample per shard to the history ring buffers (called by the
/// telemetry sampler every SHARD_SAMPLE_INTERVAL).
fn push_history_snapshot(shards: &[ShardStat]) {
    let mut h = forecast_history().lock().unwrap();
    h.resize(shards.len(), std::collections::VecDeque::new());
    for (q, s) in h.iter_mut().zip(shards) {
        if q.len() == FORECAST_HISTORY_CAP {
            q.pop_front();
        }
        q.push_back(HistPoint {
            level: s.level,
            forecast: s.forecast,
        });
    }
}

/// Clone of the history for JSON serialization.
fn history_snapshot() -> Vec<std::collections::VecDeque<HistPoint>> {
    forecast_history().lock().unwrap().clone()
}

// ============================================================================
// Forecast core v2: lock-free, cache-line-padded, fixed-point
// ============================================================================
//
// Design goals (modelled after the load-tracking hot paths of nginx's
// shared zone and Envoy's per-cluster circuit-breaker gauges):
//   * Writers (sampler / shard threads) and readers (balancer on every
//     request) must never share a mutex: a locked HashMap here shows up as
//     cross-core cache-line ping-pong at 10k+ rps.
//   * One cache line per shard/upstream (64 B padding) so concurrently
//     updated cells never share a line (no false sharing).
//   * Fixed-point i64 math (value << 16): Holt recurrences are pure
//     add/multiply — no float registers, no denormals, deterministic.
//   * Adaptive smoothing: alpha/beta widen when the series is volatile
//     (burst) and tighten when stable, so forecasts react fast to ramps
//     without flapping on steady load.
//   * Per-shard/upstream observation latency: per-core atomics accumulated
//     lock-free on the request path, folded by the sampler (no mutexes on
//     the hot path at all).

const FC_SHIFT: u32 = 16;
const FC_ONE: i64 = 1 << FC_SHIFT;


#[repr(align(64))]
struct FcCell {
    level: AtomicI64,
    trend: AtomicI64,
    alpha: AtomicI64,
    beta: AtomicI64,
    /// Last raw sample folded in (for the admin panel).
    last_sample: AtomicI64,
}

impl FcCell {
    const fn zeroed() -> Self {
        Self {
            level: AtomicI64::new(0),
            trend: AtomicI64::new(0),
            alpha: AtomicI64::new(0),
            beta: AtomicI64::new(0),
            last_sample: AtomicI64::new(0),
        }
    }

    /// One Holt step in fixed point. `sample` is in plain units.
    /// alpha/beta are stored already shifted; adaptive law:
    ///   a += k*(|err|/scale - a)
    /// i.e. an EMA of the relative forecast error drives the smoothing
    /// factor (FC_ADAPT_K = 1/8 step response).
    fn fold(&self, sample: f64, sample_scale: f64) {
        let s = (sample * FC_ONE as f64) as i64;
        let scale = (sample_scale * FC_ONE as f64).max(FC_ONE as f64) as i64;
        let prev_l = self.level.load(Ordering::Relaxed);
        let prev_t = self.trend.load(Ordering::Relaxed);
        let a = if self.alpha.load(Ordering::Relaxed) == 0 {
            FC_ALPHA0
        } else {
            self.alpha.load(Ordering::Relaxed)
        };
        let b = if self.beta.load(Ordering::Relaxed) == 0 {
            FC_BETA0
        } else {
            self.beta.load(Ordering::Relaxed)
        };
        // i128 intermediates with Q16 rescale after each multiply-sum:
        // level = a*s + (1-a)*pred, trend = b*(level-prev) + (1-b)*prev_t.
        let pred = prev_l as i128 + prev_t as i128;
        let level = ((a as i128 * s as i128 + (FC_ONE - a) as i128 * pred) >> FC_SHIFT) as i64;
        let trend = ((b as i128 * (level as i128 - prev_l as i128)
            + (FC_ONE - b) as i128 * prev_t as i128)
            >> FC_SHIFT) as i64;
        // Relative error of the previous forecast -> adapt alpha/beta:
        // err = |pred - s| / scale (Q16), EMA step k=1/8. Saturating math
        // keeps pathological inputs inside range instead of wrapping.
        let err = (((prev_l as i128 + prev_t as i128 - s as i128).abs() * FC_ONE as i128)
            / scale as i128) as i64;
        let a2 = a.saturating_add(
            (((FC_ADAPT_K as i128) * (err as i128 - a as i128)) >> 3) as i64,
        );
        let b2 = b.saturating_add(
            (((FC_ADAPT_K as i128) * (err as i128 - b as i128)) >> 3) as i64,
        );
        self.level.store(level, Ordering::Relaxed);
        self.trend.store(trend, Ordering::Relaxed);
        self.alpha
            .store(a2.clamp(FC_ALPHA_MIN, FC_ALPHA_MAX), Ordering::Relaxed);
        self.beta
            .store(b2.clamp(FC_BETA_MIN, FC_BETA_MAX), Ordering::Relaxed);
        self.last_sample.store(s, Ordering::Relaxed);
    }

    /// One-step-ahead forecast, plain units.
    fn forecast(&self) -> f64 {
        let v = self.level.load(Ordering::Relaxed) + self.trend.load(Ordering::Relaxed);
        v as f64 / FC_ONE as f64
    }

    fn level_f(&self) -> f64 {
        self.level.load(Ordering::Relaxed) as f64 / FC_ONE as f64
    }

    fn trend_f(&self) -> f64 {
        self.trend.load(Ordering::Relaxed) as f64 / FC_ONE as f64
    }

    fn sample_f(&self) -> f64 {
        self.last_sample.load(Ordering::Relaxed) as f64 / FC_ONE as f64
    }
}

// Fixed-point constants: alpha0/beta0 mirror the f64 EMA_ALPHA/TREND_BETA.
const FC_ALPHA0: i64 = (0.30 * FC_ONE as f64) as i64;
const FC_BETA0: i64 = (0.10 * FC_ONE as f64) as i64;
const FC_ALPHA_MIN: i64 = (0.05 * FC_ONE as f64) as i64;
const FC_ALPHA_MAX: i64 = (0.90 * FC_ONE as f64) as i64;
const FC_BETA_MIN: i64 = (0.01 * FC_ONE as f64) as i64;
const FC_BETA_MAX: i64 = (0.50 * FC_ONE as f64) as i64;
/// Adaptive step: k = 1/8, stored already shifted (>> 3 applies it).
const FC_ADAPT_K: i64 = FC_ONE / 8;

/// The per-shard forecast table. Padded cells guarantee one cache line per
/// shard — 12 shards fill 12 lines, no false sharing.
struct ForecastCore {
    cells: Vec<FcCell>,
}

impl ForecastCore {
    fn new(n: usize) -> Self {
        Self {
            cells: (0..n).map(|_| FcCell::zeroed()).collect(),
        }
    }

    fn cell(&self, i: usize) -> Option<&FcCell> {
        self.cells.get(i)
    }
}

static SHARD_FC: OnceLock<ForecastCore> = OnceLock::new();

fn shard_fc() -> &'static ForecastCore {
    SHARD_FC.get_or_init(|| ForecastCore::new(shard_counters().len().max(num_cores_hint())))
}

/// Shard count is known before the counters are registered (main() knows
/// the core count first); fall back to the registered length otherwise.
fn num_cores_hint() -> usize {
    NUM_SHARDS.load(Ordering::Relaxed).max(shard_counters().len()).max(1)
}

/// Set once in main() before init_shard_counters so the forecast core is
/// sized correctly on first use.
static NUM_SHARDS: AtomicUsize = AtomicUsize::new(0);

// ---- Per-stage request latency tracking (lock-free, per-core accumulate) ----

const STAGE_PARSE: usize = 0;
const STAGE_ROUTE: usize = 1;
const STAGE_UPSTREAM: usize = 2;
const STAGE_RESPOND: usize = 3;
const STAGE_COUNT: usize = 4;
const STAGE_NAMES: [&str; STAGE_COUNT] = ["read+parse", "route", "upstream", "respond"];
const LAT_RING_CAP: usize = 64; // powers of two

/// One per-core accumulator for stage latencies. shard threads write
/// (add) without any lock; the sampler folds the deltas and resets.
#[repr(align(64))]
struct StageAccum {
    /// Packed (count, total_ns) per stage to keep one line per core.
    count: [AtomicU64; STAGE_COUNT],
    total_ns: [AtomicU64; STAGE_COUNT],
    /// Latency high-water ring: 64 slots per stage, ns granularity,
    /// used to estimate tail (p99-like) without histograms.
    ring: [AtomicU64; STAGE_COUNT * LAT_RING_CAP],
    idx: AtomicUsize,
}

impl StageAccum {
    const fn new() -> Self {
        const Z: AtomicU64 = AtomicU64::new(0);
        Self {
            count: [Z; STAGE_COUNT],
            total_ns: [Z; STAGE_COUNT],
            ring: [Z; STAGE_COUNT * LAT_RING_CAP],
            idx: AtomicUsize::new(0),
        }
    }

    #[inline]
    fn record(&self, stage: usize, ns: u64) {
        self.count[stage].fetch_add(1, Ordering::Relaxed);
        self.total_ns[stage].fetch_add(ns, Ordering::Relaxed);
        let i = self.idx.fetch_add(1, Ordering::Relaxed);
        self.ring[(stage * LAT_RING_CAP) + (i % LAT_RING_CAP)]
            .store(ns, Ordering::Relaxed);
    }
}

/// Per-shard accumulators (one per shard thread; shard i touches slot i).
static STAGE_ACCUMS: OnceLock<Vec<StageAccum>> = OnceLock::new();

fn stage_accums() -> &'static [StageAccum] {
    STAGE_ACCUMS.get_or_init(|| {
        (0..shard_counters().len().max(1))
            .map(|_| StageAccum::new())
            .collect()
    })
}

/// Record a stage latency for the current shard. Called on the hot path —
/// three relaxed atomics, no locks.
fn record_stage(shard: usize, stage: usize, started: Instant) {
    let Some(acc) = stage_accums().get(shard) else {
        return;
    };
    acc.record(stage, started.elapsed().as_nanos() as u64);
}

/// Fold all per-core accumulators into global stage totals (sampler call).
struct StageTotals {
    count: [u64; STAGE_COUNT],
    avg_us: [f64; STAGE_COUNT],
    p99_ms: [f64; STAGE_COUNT],
}

fn fold_stage_totals() -> StageTotals {
    let mut t = StageTotals {
        count: [0; STAGE_COUNT],
        avg_us: [0.0; STAGE_COUNT],
        p99_ms: [0.0; STAGE_COUNT],
    };
    for acc in stage_accums().iter() {
        for s in 0..STAGE_COUNT {
            t.count[s] += acc.count[s].swap(0, Ordering::Relaxed);
            t.avg_us[s] += acc.total_ns[s].swap(0, Ordering::Relaxed) as f64 / 1000.0;
        }
    }
    for s in 0..STAGE_COUNT {
        if t.count[s] > 0 {
            t.avg_us[s] /= t.count[s] as f64;
        }
    }
    // Tail estimate: max ring slot per stage (p99-like upper bound).
    for acc in stage_accums().iter() {
        for s in 0..STAGE_COUNT {
            for i in 0..LAT_RING_CAP {
                let v = acc.ring[s * LAT_RING_CAP + i].swap(0, Ordering::Relaxed);
                if (v as f64 / 1e6) > t.p99_ms[s] {
                    t.p99_ms[s] = v as f64 / 1e6;
                }
            }
        }
    }
    t
}

/// Latest folded stage totals for /api/stats (updated by the sampler).
fn stage_totals_slot() -> &'static Mutex<Option<(Instant, StageTotals)>> {
    static S: OnceLock<Mutex<Option<(Instant, StageTotals)>>> = OnceLock::new();
    S.get_or_init(|| Mutex::new(None))
}

/// Snapshot for the admin UI / metrics exporter.
fn shard_stats_snapshot() -> Vec<ShardStat> {
    // v2 forecast core is authoritative: readers (dashboard, balancer
    // telemetry) see the same lock-free cells the balancer scores with.
    let fc = shard_fc();
    shard_counters()
        .iter()
        .enumerate()
        .map(|(i, c)| {
            let (level, trend, forecast) = fc
                .cell(i)
                .map(|cell| (cell.level_f(), cell.trend_f(), cell.forecast()))
                .unwrap_or((0.0, 0.0, 0.0));
            ShardStat {
                shard: i,
                active_conns: c.active_conns.load(Ordering::Relaxed) as u64,
                accepted_total: c.accepted_total.load(Ordering::Relaxed) as u64,
                level,
                trend,
                forecast,
            }
        })
        .collect()
}

// ============================================================================
// Rate limiting (Token Bucket, per client IP)
// ============================================================================

pub struct TokenBucket {
    pub tokens: f64,
    pub last_refill: Instant,
    pub max_tokens: f64,
    pub refill_rate: f64, // tokens per second
}

impl TokenBucket {
    pub fn new(max_tokens: f64, refill_rate: f64) -> Self {
        Self {
            tokens: max_tokens,
            last_refill: Instant::now(),
            max_tokens,
            refill_rate,
        }
    }

    /// Refill according to elapsed time, then try to take one token.
    /// `retune` re-applies live admin-panel limits to pre-existing buckets:
    /// without it a bucket created under old settings would keep the old
    /// capacity/refill forever (observed as stuck 429s after retuning).
    pub fn take(&mut self, retune: Option<(f64, f64)>) -> bool {
        if let Some((m, r)) = retune {
            if (m - self.max_tokens).abs() > f64::EPSILON
                || (r - self.refill_rate).abs() > f64::EPSILON
            {
                self.max_tokens = m;
                self.refill_rate = r;
                // Rebalance into the new capacity: clamp the stored balance
                // into [0, m] so both grow (0 -> m) and shrink (100k -> 10)
                // apply to existing buckets immediately.
            }
        }
        let now = Instant::now();
        let elapsed = now.duration_since(self.last_refill).as_secs_f64();
        self.last_refill = now;
        self.tokens = (self.tokens.min(self.max_tokens) + elapsed * self.refill_rate)
            .min(self.max_tokens);
        if self.tokens >= 1.0 {
            self.tokens -= 1.0;
            true
        } else {
            false
        }
    }
}

pub struct IpRateLimiter {
    buckets: Mutex<HashMap<IpAddr, TokenBucket>>,
    /// Fixed (tokens, refill) override — None reads the live runtime knobs.
    custom: Option<(f64, f64)>,
    max_ips: usize,
}

impl IpRateLimiter {
    /// Fixed-policy constructor (unit tests): the given token/refill values
    /// are used as-is.
    pub fn new(max_tokens: f64, refill_rate: f64, max_ips: usize) -> Self {
        Self {
            buckets: Mutex::new(HashMap::new()),
            custom: Some((max_tokens, refill_rate)),
            max_ips,
        }
    }

    /// Live-policy constructor (production): token/refill values come from
    /// the runtime settings on every check, so admin-panel retuning applies
    /// without a restart.
    pub fn live(max_ips: usize) -> Self {
        Self {
            buckets: Mutex::new(HashMap::new()),
            custom: None,
            max_ips,
        }
    }

    fn limits(&self) -> (f64, f64) {
        self.custom.unwrap_or((
            settings().rate_limit_tokens.get() as f64,
            settings().rate_limit_refill.get() as f64,
        ))
    }

    pub fn check_limit(&self, ip: IpAddr) -> bool {
        let mut buckets = self.buckets.lock().unwrap();
        if !buckets.contains_key(&ip) {
            if buckets.len() >= self.max_ips {
                // Evict a fully-refilled (idle) bucket; otherwise the least
                // recently used one, to keep the map bounded.
                let victim = buckets
                    .iter()
                    .find(|(_, b)| b.tokens >= b.max_tokens)
                    .map(|(k, _)| *k)
                    .or_else(|| {
                        buckets
                            .iter()
                            .min_by_key(|(_, b)| b.last_refill)
                            .map(|(k, _)| *k)
                    });
                if let Some(v) = victim {
                    buckets.remove(&v);
                }
            }
            let (max_tokens, refill) = self.limits();
        buckets.insert(ip, TokenBucket::new(max_tokens, refill));
        }
        // Live retune: None for fixed-policy (test) limiters, Some for the
        // global limiter so admin-panel changes reach existing buckets.
        let retune = self.custom.map(|_| self.limits());
        buckets.get_mut(&ip).unwrap().take(retune)
    }
}

lazy_static! {
    pub static ref RATE_LIMITER: IpRateLimiter = {
        // Live policy: env vars seed the runtime settings (see RuntimeSettings),
        // and admin-panel retuning applies to this limiter immediately.
        IpRateLimiter::live(65_536)
    };
}

// ============================================================================
// Configuration (hot-reloaded via Control Plane, persisted to disk)
// ============================================================================

/// Per-route security policy. Populated from `htaccess_import.py` (a
/// WordPress-style `.htaccess`) or directly through the Control Plane.
/// This is Juggler's answer to Apache's per-directory security: the same
/// deny/rewrite/header rules, but validated, hot-reloadable and enforced
/// in one auditable place instead of scattered .htaccess files.
#[derive(Clone, Serialize, Deserialize, Debug, Default, PartialEq)]
pub struct SecurityConfig {
    /// Substrings (case-insensitive) that must never be served: if any
    /// matches the request path, the proxy answers 403 without contacting
    /// the upstream. Typical translation of
    /// `<FilesMatch "^(\.|wp-config|\.htaccess)"> Require all denied`.
    #[serde(default)]
    pub deny_substrings: Vec<String>,
    /// Response headers injected into every response served by this route
    /// (translation of `Header always set X-Frame-Options "DENY"`).
    #[serde(default)]
    pub response_headers: Vec<(String, String)>,
    /// Front-controller fallback (translation of
    /// `RewriteRule ^(.*)$ index.php [L]`): when the request path matches
    /// this prefix route but no more-specific route exists, rewrite the
    /// path to this value before proxying. This is what WordPress/
    /// Laravel/Drupal .htaccess files do for pretty URLs.
    #[serde(default)]
    pub cms_fallback_path: Option<String>,
}

#[derive(Clone, Serialize, Deserialize, Debug, Default, PartialEq)]
pub struct RouteConfig {
    pub upstream_addrs: Vec<String>,
    pub wasm_plugin: Option<String>,
    #[serde(default)]
    pub security: SecurityConfig,
    /// Serve files from this directory instead of proxying. Exclusive with
    /// `upstream_addrs`: nginx's `root`/`alias` equivalent. Paths are resolved
    /// inside the directory (canonicalized + prefix check), so traversal
    /// (`/../`, encoded dots) cannot escape it. A directory request maps to
    /// `index.html` inside it.
    #[serde(default)]
    pub static_dir: Option<String>,
}

#[derive(Clone, Serialize, Deserialize, Debug, Default, PartialEq)]
pub struct ServerConfig {
    pub routes: HashMap<String, RouteConfig>,
}

/// Persist the config so a restarted server keeps its routes. Best-effort:
/// a failure to write must never fail the hot reload itself.
fn persist_config(cfg: &ServerConfig, path: &str) {
    match serde_json::to_string_pretty(cfg) {
        Ok(json) => {
            if let Err(e) = std::fs::write(path, json) {
                eprintln!("⚠️ failed to persist routes to {path}: {e}");
            }
        }
        Err(e) => eprintln!("⚠️ failed to serialize routes: {e}"),
    }
}

/// Load routes persisted by a previous run. Missing file is not an error.
fn load_persisted_config(path: &str) -> Option<ServerConfig> {
    let data = std::fs::read_to_string(path).ok()?;
    match serde_json::from_str(&data) {
        Ok(cfg) => {
            println!("📥 Loaded persisted routes from {path}");
            Some(cfg)
        }
        Err(e) => {
            eprintln!("⚠️ ignoring corrupted routes file {path}: {e}");
            None
        }
    }
}

// ============================================================================
// Load balancing (Least Connections, random tie-break)
// ============================================================================

pub struct LeastConnectionsBalancer {
    /// addr -> active in-flight request counter
    pub connections: ArcSwap<HashMap<String, Arc<AtomicUsize>>>,
    /// addr -> Holt (level, trend) of in-flight load (predictive mode).
    /// v2: indexed FcCell array (lock-free, cache-line padded), rebuilt in
    /// update_registry; addr -> cell index mapping lives in the ArcSwap
    /// registry snapshot itself.
    forecast: std::sync::Mutex<Vec<String>>,
    /// Fixed-point forecast cells, parallel to `forecast` names.
    forecast_cells: std::sync::Mutex<Vec<FcCell>>,
}

impl LeastConnectionsBalancer {
    pub fn new() -> Self {
        Self {
            connections: ArcSwap::from_pointee(HashMap::new()),
            forecast: std::sync::Mutex::new(Vec::new()),
            forecast_cells: std::sync::Mutex::new(Vec::new()),
        }
    }

    /// Rebuild the registry for a new config, preserving live counters of
    /// upstreams that survive the reload.
    pub fn update_registry(&self, routes: &HashMap<String, RouteConfig>) {
        let current = self.connections.load_full();
        let mut updated: HashMap<String, Arc<AtomicUsize>> = HashMap::new();
        for route in routes.values() {
            for addr in &route.upstream_addrs {
                let entry = current
                    .get(addr)
                    .cloned()
                    .unwrap_or_else(|| Arc::new(AtomicUsize::new(0)));
                updated.insert(addr.clone(), entry);
            }
        }
        self.connections.store(Arc::new(updated));
        // Rebuild the forecast name table + cells, preserving prior state
        // of surviving upstreams (same layout as the counters above).
        let mut names: Vec<String> = self
            .connections
            .load()
            .keys()
            .cloned()
            .collect();
        names.sort();
        let mut old = self.forecast.lock().unwrap();
        let mut old_cells = self.forecast_cells.lock().unwrap();
        let mut cells = Vec::with_capacity(names.len());
        for name in &names {
            let prior = old
                .iter()
                .position(|n| n == name)
                .and_then(|i| old_cells.get(i))
                .map(|c| FcCell {
                    level: AtomicI64::new(c.level.load(Ordering::Relaxed)),
                    trend: AtomicI64::new(c.trend.load(Ordering::Relaxed)),
                    alpha: AtomicI64::new(c.alpha.load(Ordering::Relaxed)),
                    beta: AtomicI64::new(c.beta.load(Ordering::Relaxed)),
                    last_sample: AtomicI64::new(c.last_sample.load(Ordering::Relaxed)),
                })
                .unwrap_or_else(FcCell::zeroed);
            cells.push(prior);
        }
        *old = names;
        *old_cells = cells;
    }

    pub fn select_upstream(&self, addrs: &[String]) -> Option<(String, Arc<AtomicUsize>)> {
        let registry = self.connections.load();
        let mut min_conns = usize::MAX;
        let mut candidates: Vec<&String> = Vec::new();
        for addr in addrs {
            if let Some(counter) = registry.get(addr) {
                let current = counter.load(Ordering::Relaxed);
                if current < min_conns {
                    min_conns = current;
                    candidates.clear();
                    candidates.push(addr);
                } else if current == min_conns {
                    candidates.push(addr);
                }
            }
        }
        let picked = match candidates.len() {
            0 => return None,
            1 => candidates[0],
            _ => {
                // Random tie-break avoids thundering herd on the first upstream.
                use rand::seq::SliceRandom;
                candidates.choose(&mut rand::thread_rng()).copied().unwrap()
            }
        };
        let addr = picked.clone();
        Some((addr, registry.get(picked).unwrap().clone()))
    }

    // ---- Predictive mode (JUGGLER_BALANCER=predictive) ----
    //
    // Holt (level, trend) per upstream, updated by the telemetry sampler.
    // Selection minimises the forecast (level + trend): an upstream whose
    // in-flight load is falling is preferred over one that currently ties
    // but is trending up — requests flow toward where the queue WILL be
    // shortest, not where it is right now.

    fn update_forecast(&self, addr: &str, sample: usize) {
        let names = self.forecast.lock().unwrap();
        let Some(idx) = names.iter().position(|n| n == addr) else {
            return;
        };
        drop(names);
        let mut cells = self.forecast_cells.lock().unwrap();
        let max = cells
            .iter()
            .map(|c| c.sample_f())
            .fold(1.0_f64, f64::max)
            .max(sample as f64);
        if let Some(slot) = cells.get_mut(idx) {
            slot.fold(sample as f64, max);
        }
    }

    pub fn forecast_of(&self, addr: &str) -> (f64, f64) {
        let names = self.forecast.lock().unwrap();
        let Some(idx) = names.iter().position(|n| n == addr) else {
            return (0.0, 0.0);
        };
        drop(names);
        self.forecast_cells
            .lock()
            .unwrap()
            .get(idx)
            .map(|c| (c.level_f(), c.trend_f()))
            .unwrap_or((0.0, 0.0))
    }

    /// Predictive selection: argmin(level + trend) with the same
    /// least-connections fallback when no forecast exists yet. Hot path:
    /// one short-lived lock only to copy the (level, trend) table out of
    /// the cell array, then pure atomic reads — no per-request map lookups
    /// with String hashing.
    pub fn select_upstream_predictive(&self, addrs: &[String]) -> Option<(String, Arc<AtomicUsize>)> {
        let registry = self.connections.load();
        let names = self.forecast.lock().unwrap();
        let scores: Vec<(f64, f64)> = self
            .forecast_cells
            .lock()
            .unwrap()
            .iter()
            .map(|c| (c.level_f(), c.trend_f()))
            .collect();
        let mut best: Option<(f64, &String, &Arc<AtomicUsize>)> = None;
        let mut tied: Vec<&String> = Vec::new();
        for addr in addrs {
            if let Some(counter) = registry.get(addr) {
                let current = counter.load(Ordering::Relaxed) as f64;
                // A cell that has never been folded keeps alpha == 0 — that
                // is the "no forecast data" marker: fall back to the live
                // counter, exactly like the old HashMap-based code did for
                // an absent key.
                let score = match names.iter().position(|n| n == addr) {
                    Some(i) => match scores.get(i) {
                        Some(&(l, t)) if l != 0.0 || t != 0.0 => l + t,
                        _ => current,
                    },
                    None => current,
                };
                match &best {
                    // Strictly worse candidate: skip.
                    Some((b, _, _)) if score > *b + f64::EPSILON => {}
                    // Statistical tie (float scores rarely match exactly):
                    // keep both and let the random tie-break spread load —
                    // a fixed winner would starve the other peer (e.g. a
                    // fresh upstream would never see traffic to learn from).
                    Some((b, _, _)) if (score - *b).abs() <= 1e-6 => {
                        tied.push(addr);
                    }
                    _ => {
                        best = Some((score, addr, counter));
                        tied.clear();
                        tied.push(addr);
                    }
                }
            }
        }
        let picked = if tied.len() > 1 {
            use rand::seq::SliceRandom;
            *tied.choose(&mut rand::thread_rng()).unwrap()
        } else {
            best.as_ref().map(|(_, a, _)| *a)?
        };
        Some((
            picked.to_string(),
            registry.get(picked).unwrap().clone(),
        ))
    }

    /// Predictive selection weighted by the signature oracle's predicted
    /// cost: score = live_counter_or_forecast + cost_units. A request
    /// predicted to occupy an upstream for 1.5 s adds a bigger virtual
    /// burden than a 3 ms probe, so heavy requests prefer the upstream
    /// whose queue will actually stay short (community "Oracle" design,
    /// userspace edition). Cost units are relative to the mean observed
    /// cost across the signature table (mean ≈ 1 unit), so the penalty
    /// scales with real traffic mix and needs no magic constants.
    pub fn select_upstream_predictive_weighted(
        &self,
        addrs: &[String],
        predicted_cost_ns: u64,
    ) -> Option<(String, Arc<AtomicUsize>)> {
        let registry = self.connections.load();
        let names = self.forecast.lock().unwrap();
        let scores: Vec<(f64, f64)> = self
            .forecast_cells
            .lock()
            .unwrap()
            .iter()
            .map(|c| (c.level_f(), c.trend_f()))
            .collect();
        let (mean_cost, known) = {
            let mut sum = 0u64;
            let mut n = 0usize;
            for s in sig_table().iter() {
                let v = s.ema_ns.load(Ordering::Relaxed);
                if v > 0 {
                    sum += v;
                    n += 1;
                }
            }
            if n > 0 { (sum as f64 / n as f64, true) } else { (0.0, false) }
        };
        let cost_units = if known && mean_cost > 0.0 {
            predicted_cost_ns as f64 / mean_cost
        } else {
            1.0
        };
        let mut best: Option<(f64, &String, &Arc<AtomicUsize>)> = None;
        let mut tied: Vec<&String> = Vec::new();
        for addr in addrs {
            if let Some(counter) = registry.get(addr) {
                let current = counter.load(Ordering::Relaxed) as f64;
                let base = match names.iter().position(|n| n == addr) {
                    Some(i) => match scores.get(i) {
                        Some(&(l, t)) if l != 0.0 || t != 0.0 => l + t,
                        _ => current,
                    },
                    None => current,
                };
                let score = base + cost_units;
                match &best {
                    Some((b, _, _)) if score > *b + f64::EPSILON => {}
                    Some((b, _, _)) if (score - *b).abs() <= 1e-6 => {
                        tied.push(addr);
                    }
                    _ => {
                        best = Some((score, addr, counter));
                        tied.clear();
                        tied.push(addr);
                    }
                }
            }
        }
        let picked = if tied.len() > 1 {
            use rand::seq::SliceRandom;
            *tied.choose(&mut rand::thread_rng()).unwrap()
        } else {
            best.as_ref().map(|(_, a, _)| *a)?
        };
        Some((
            picked.to_string(),
            registry.get(picked).unwrap().clone(),
        ))
    }
}

impl Default for LeastConnectionsBalancer {
    fn default() -> Self {
        Self::new()
    }
}

lazy_static! {
    pub static ref BALANCER: LeastConnectionsBalancer = LeastConnectionsBalancer::new();
}

/// Load-balancer mode: "leastconn" (default) or "predictive" (Holt
/// forecast of in-flight load per upstream).
fn balancer_mode() -> &'static str {
    static MODE: OnceLock<String> = OnceLock::new();
    MODE.get_or_init(|| {
        let m = std::env::var("JUGGLER_BALANCER")
            .unwrap_or_else(|_| "leastconn".into())
            .to_lowercase();
        if m == "predictive" {
            println!("⚖️  Balancer: predictive (Holt forecast of upstream load)");
        }
        m
    })
    .as_str()
}

// ============================================================================
// Signature oracle: per-request-pattern cost prediction (EMA)
// ============================================================================
//
// Predictive Oracle — userspace edition. The idea (community research):
// predict the COST of a request before dispatching it, from a signature
// hash of (method + path pattern + presence of a body). A request that
// usually takes 3 ms must not tie-break the same way as one that takes
// 1.5 s, otherwise slow signatures herd onto one upstream.
//
// Implementation: a sharded lock-free table (fixed 256 slots, power of two,
// cache-line padded) mapping a cheap u64 signature hash -> EMA of upstream
// processing time. Sits next to the Holt forecast cells: the balancer uses
// predicted cost as a WEIGHT on the live counter of each upstream candidate.

/// Fixed table size: 256 slots x 64B = 16 KiB, one cache line each.
const SIG_TABLE_SIZE: usize = 256;

#[repr(align(64))]
struct SigSlot {
    /// EMA of upstream processing time, nanoseconds (0 = no data yet).
    ema_ns: AtomicU64,
}

static SIG_TABLE: OnceLock<Vec<SigSlot>> = OnceLock::new();

/// 0.125 smoothing: an EMA keeps recent cost changes (backend slowdowns
/// must be seen within ~8 samples of the same signature).
const SIG_EMA_SHIFT: u32 = 3;

fn sig_table() -> &'static [SigSlot] {
    SIG_TABLE.get_or_init(|| (0..SIG_TABLE_SIZE).map(|_| SigSlot { ema_ns: AtomicU64::new(0) }).collect())
}

/// Compute the u64 signature hash for a request: FNV-1a over method, the
/// path pattern (numeric segments bucketed to :id so /users/42 and
/// /users/7 share a signature) and a body-size class.
pub fn request_signature_hash(method: &[u8], path: &str, body_len: usize) -> u64 {
    let mut h: u64 = 0xcbf29ce484222325;
    let mut byte = |b: u8| {
        h ^= b as u64;
        h = h.wrapping_mul(0x100000001b3);
    };
    for &b in method {
        byte(b | 0x20); // case-fold methods
    }
    byte(b' ');
    // Path pattern: bucket numeric segments ("42" -> ":id") so dynamic
    // routes share one signature instead of exploding the table. A segment
    // is the whole run between slashes: "v1" stays literal (digits after a
    // letter are part of the name), "42" alone collapses to :id.
    let mut seg = Vec::with_capacity(16);
    let mut flush_seg = |byte: &mut dyn FnMut(u8), seg: &mut Vec<u8>| {
        if seg.iter().all(|&d| d.is_ascii_digit()) {
            byte(b':');
            byte(b'i');
            byte(b'd');
        } else {
            for &d in seg.iter() {
                byte(d);
            }
        }
        seg.clear();
    };
    for &c in path.as_bytes() {
        if c == b'/' {
            if !seg.is_empty() {
                flush_seg(&mut byte, &mut seg);
            }
            byte(b'/');
        } else {
            seg.push(c);
        }
    }
    if !seg.is_empty() {
        flush_seg(&mut byte, &mut seg);
    }
    // Body size class: 0 / <1k / <64k / big — upload cost differs wildly.
    let class = if body_len == 0 {
        0u8
    } else if body_len < 1024 {
        1
    } else if body_len < 64 * 1024 {
        2
    } else {
        3
    };
    byte(b' ');  
    byte(class + b'0');
    h
}

/// Record the observed upstream processing time for a signature (EMA).
fn sig_record(sig: u64, elapsed_ns: u64) {
    let slot = &sig_table()[(sig as usize) % SIG_TABLE_SIZE];
    slot.ema_ns.fetch_update(Ordering::Relaxed, Ordering::Relaxed, |old| {
        Some(if old == 0 { elapsed_ns } else { (old * 7 + elapsed_ns) >> SIG_EMA_SHIFT })
    }).ok();
}

/// Predicted cost of a signature in ns; default 45 µs when unseen.
fn sig_predict(sig: u64) -> u64 {
    let slot = &sig_table()[(sig as usize) % SIG_TABLE_SIZE];
    let v = slot.ema_ns.load(Ordering::Relaxed);
    if v == 0 { 45_000 } else { v }
}

/// Global backpressure flag (see mailbox watermarks below).
static ACCEPT_BACKPRESSURE: AtomicBool = AtomicBool::new(false);

/// Pending hand-off connection budget across ALL mailboxes. When it is
/// exhausted the acceptors stop accepting: new clients wait in the kernel
/// listen backlog (deep, kernel-owned) instead of consuming unbounded
/// userspace memory. Watermarks: stop at full budget, resume below the
/// low watermark — hysteresis prevents thrash.
static PENDING_CONNS: AtomicUsize = AtomicUsize::new(0);
const PENDING_CONNS_MAX: usize = 4096;
const PENDING_CONNS_LOW: usize = 1024;

/// Raised/lowered by the dispatcher after each push; polled by acceptors.
fn update_backpressure_state(now_pending: usize) {
    if now_pending >= PENDING_CONNS_MAX {
        if !ACCEPT_BACKPRESSURE.swap(true, Ordering::AcqRel) {
            eprintln!("🛑 Backpressure ON: {now_pending} connections queued in hand-off, accept paused");
        }
    } else if now_pending < PENDING_CONNS_LOW {
        if ACCEPT_BACKPRESSURE.swap(false, Ordering::AcqRel) {
            eprintln!("✅ Backpressure OFF: {now_pending} pending, accept resumed");
        }
    }
}

// ============================================================================
// Runtime settings (fine tuning, editable from the admin panel)
// ============================================================================
//
// Every knob here is a live atomic: the admin panel writes new values and
// the request path reads them per request — no restart, no reload. Defaults
// come from env vars (deployment posture) and are persisted to
// `settings.json` so the admin's tuning survives restarts.

/// One tuneable knob: atomic value + its bounds for validation.
struct Knob {
    value: AtomicU64,
    min: u64,
    max: u64,
}

impl Knob {
    const fn new(v: u64, min: u64, max: u64) -> Self {
        Self { value: AtomicU64::new(v), min, max }
    }
    fn get(&self) -> u64 {
        self.value.load(Ordering::Relaxed)
    }
    /// Clamp + store; returns the applied value.
    fn set(&self, v: u64) -> u64 {
        let v = v.clamp(self.min, self.max);
        self.value.store(v, Ordering::Relaxed);
        v
    }
}

/// Which algorithm picks the upstream for each request.
pub const BALANCER_LEASTCONN: u64 = 0;
pub const BALANCER_PREDICTIVE: u64 = 1;

struct RuntimeSettings {
    /// BALANCER_LEASTCONN (0) or BALANCER_PREDICTIVE (1).
    balancer: Knob,
    /// Token-bucket burst size per client IP (0 disables rate limiting).
    rate_limit_tokens: Knob,
    /// Token-bucket refill rate, tokens per second.
    rate_limit_refill: Knob,
    /// Idle keep-alive sockets kept per upstream address.
    pool_cap_per_upstream: Knob,
    /// Requests served over one client keep-alive connection before
    /// the server closes it (DoS guard).
    max_keepalive_requests: Knob,
    /// Idle sockets closed by the pruner after this many seconds.
    pool_idle_timeout_secs: Knob,
    /// Adaptive prewarm floor ("keep the pool slightly warm").
    prewarm_floor: Knob,
    /// Consecutive upstream failures before the circuit opens.
    health_max_failures: Knob,
    /// Half-open probe cooldown after a circuit opens, seconds.
    health_cooldown_secs: Knob,
    /// Enable adaptive pool warm-up driven by the high-water mark.
    adaptive_prewarm: AtomicBool,
}

static SETTINGS: OnceLock<RuntimeSettings> = OnceLock::new();

fn env_u64(name: &str, default: u64) -> u64 {
    std::env::var(name)
        .ok()
        .and_then(|v| v.parse().ok())
        .unwrap_or(default)
}

impl RuntimeSettings {
    fn from_env() -> Self {
        Self {
            balancer: Knob::new(
                u64::from(balancer_mode() == "predictive"),
                0,
                1,
            ),
            rate_limit_tokens: Knob::new(env_u64("JUGGLER_RATE_LIMIT_TOKENS", 10), 0, 1_000_000),
            rate_limit_refill: Knob::new(env_u64("JUGGLER_RATE_LIMIT_REFILL", 5), 0, 1_000_000),
            pool_cap_per_upstream: Knob::new(env_u64("JUGGLER_UPSTREAM_POOL_PER_UPSTREAM", 512), 4, 16_384),
            max_keepalive_requests: Knob::new(env_u64("JUGGLER_MAX_KEEPALIVE_REQUESTS", 1000), 1, 100_000_000),
            pool_idle_timeout_secs: Knob::new(60, 5, 3600),
            prewarm_floor: Knob::new(env_u64("JUGGLER_PREWARM_FLOOR", 32), 0, 4096),
            health_max_failures: Knob::new(env_u64("JUGGLER_HEALTH_MAX_FAILURES", 3), 1, 1000),
            health_cooldown_secs: Knob::new(env_u64("JUGGLER_HEALTH_COOLDOWN_SECS", 5), 1, 3600),
            adaptive_prewarm: AtomicBool::new(true),
        }
    }

    fn knob(&self, name: &str) -> Option<&Knob> {
        match name {
            "balancer" => Some(&self.balancer),
            "rate_limit_tokens" => Some(&self.rate_limit_tokens),
            "rate_limit_refill" => Some(&self.rate_limit_refill),
            "pool_cap_per_upstream" => Some(&self.pool_cap_per_upstream),
            "max_keepalive_requests" => Some(&self.max_keepalive_requests),
            "pool_idle_timeout_secs" => Some(&self.pool_idle_timeout_secs),
            "prewarm_floor" => Some(&self.prewarm_floor),
            "health_max_failures" => Some(&self.health_max_failures),
            "health_cooldown_secs" => Some(&self.health_cooldown_secs),
            _ => None,
        }
    }

    fn iter(&self) -> Vec<(&'static str, &Knob)> {
        vec![
            ("balancer", &self.balancer),
            ("rate_limit_tokens", &self.rate_limit_tokens),
            ("rate_limit_refill", &self.rate_limit_refill),
            ("pool_cap_per_upstream", &self.pool_cap_per_upstream),
            ("max_keepalive_requests", &self.max_keepalive_requests),
            ("pool_idle_timeout_secs", &self.pool_idle_timeout_secs),
            ("prewarm_floor", &self.prewarm_floor),
            ("health_max_failures", &self.health_max_failures),
            ("health_cooldown_secs", &self.health_cooldown_secs),
        ]
    }
}

fn settings() -> &'static RuntimeSettings {
    SETTINGS.get_or_init(RuntimeSettings::from_env)
}

const SETTINGS_FILE: &str = "settings.json";

#[derive(serde::Serialize, serde::Deserialize, Default)]
struct SettingsFile {
    #[serde(default)]
    balancer: Option<u64>,
    #[serde(default)]
    rate_limit_tokens: Option<u64>,
    #[serde(default)]
    rate_limit_refill: Option<u64>,
    #[serde(default)]
    pool_cap_per_upstream: Option<u64>,
    #[serde(default)]
    max_keepalive_requests: Option<u64>,
    #[serde(default)]
    pool_idle_timeout_secs: Option<u64>,
    #[serde(default)]
    prewarm_floor: Option<u64>,
    #[serde(default)]
    health_max_failures: Option<u64>,
    #[serde(default)]
    health_cooldown_secs: Option<u64>,
    #[serde(default)]
    adaptive_prewarm: Option<bool>,
}

fn persist_settings() {
    // Unit tests apply settings through the same code path; persisting from
    // a test binary would pollute the real settings.json that the server
    // process later loads.
    #[cfg(test)]
    return;
    let s = settings();
    let file = SettingsFile {
        balancer: Some(s.balancer.get()),
        rate_limit_tokens: Some(s.rate_limit_tokens.get()),
        rate_limit_refill: Some(s.rate_limit_refill.get()),
        pool_cap_per_upstream: Some(s.pool_cap_per_upstream.get()),
        max_keepalive_requests: Some(s.max_keepalive_requests.get()),
        pool_idle_timeout_secs: Some(s.pool_idle_timeout_secs.get()),
        prewarm_floor: Some(s.prewarm_floor.get()),
        health_max_failures: Some(s.health_max_failures.get()),
        health_cooldown_secs: Some(s.health_cooldown_secs.get()),
        adaptive_prewarm: Some(s.adaptive_prewarm.load(Ordering::Relaxed)),
    };
    match serde_json::to_string_pretty(&file) {
        Ok(json) => {
            if let Err(e) = std::fs::write(SETTINGS_FILE, json) {
                eprintln!("⚠️ failed to persist settings: {e}");
            }
        }
        Err(e) => eprintln!("⚠️ failed to serialize settings: {e}"),
    }
}

fn load_settings_file() {
    let Ok(data) = std::fs::read_to_string(SETTINGS_FILE) else {
        return; // first run: keep env defaults
    };
    let Ok(file) = serde_json::from_str::<SettingsFile>(&data) else {
        eprintln!("⚠️ ignoring corrupted settings.json");
        return;
    };
    let s = settings();
    if let Some(v) = file.balancer {
        s.balancer.set(v);
    }
    if let Some(v) = file.rate_limit_tokens {
        s.rate_limit_tokens.set(v);
    }
    if let Some(v) = file.rate_limit_refill {
        s.rate_limit_refill.set(v);
    }
    if let Some(v) = file.pool_cap_per_upstream {
        s.pool_cap_per_upstream.set(v);
    }
    if let Some(v) = file.max_keepalive_requests {
        s.max_keepalive_requests.set(v);
    }
    if let Some(v) = file.pool_idle_timeout_secs {
        s.pool_idle_timeout_secs.set(v);
    }
    if let Some(v) = file.prewarm_floor {
        s.prewarm_floor.set(v);
    }
    if let Some(v) = file.health_max_failures {
        s.health_max_failures.set(v);
    }
    if let Some(v) = file.health_cooldown_secs {
        s.health_cooldown_secs.set(v);
    }
    if let Some(v) = file.adaptive_prewarm {
        s.adaptive_prewarm.store(v, Ordering::Relaxed);
    }
    println!("🎛️  Loaded runtime settings from {SETTINGS_FILE}");
}

/// Apply a partial settings update from the admin API. Unknown names are
/// rejected; every value is clamped into its knob's bounds. Returns the
/// number of applied settings.
fn apply_settings_update(
    updates: &std::collections::HashMap<String, serde_json::Value>,
) -> anyhow::Result<usize> {
    let s = settings();
    let mut applied = 0usize;
    for (name, raw) in updates {
        match name.as_str() {
            "adaptive_prewarm" => {
                let v = raw.as_bool().ok_or_else(|| anyhow::anyhow!("adaptive_prewarm must be bool"))?;
                s.adaptive_prewarm.store(v, Ordering::Relaxed);
            }
            "balancer" => {
                let v = match raw {
                    serde_json::Value::Number(n) => n.as_u64().unwrap_or(0),
                    serde_json::Value::String(str_val) => match str_val.as_str() {
                        "leastconn" => BALANCER_LEASTCONN,
                        "predictive" => BALANCER_PREDICTIVE,
                        other => anyhow::bail!("unknown balancer: {other}"),
                    },
                    _ => anyhow::bail!("balancer must be number or string"),
                };
                s.balancer.set(v);
            }
            _ => {
                let knob = s
                    .knob(name)
                    .ok_or_else(|| anyhow::anyhow!("unknown setting: {name}"))?;
                let v = raw
                    .as_u64()
                    .or_else(|| raw.as_str().and_then(|str_val| str_val.parse().ok()))
                    .ok_or_else(|| anyhow::anyhow!("{name} must be a number"))?;
                knob.set(v);
            }
        }
        applied += 1;
    }
    persist_settings();
    Ok(applied)
}

fn settings_json() -> String {
    let s = settings();
    let entries: Vec<serde_json::Value> = s
        .iter()
        .into_iter()
        .map(|(name, k)| {
            serde_json::json!({
                "name": name,
                "value": k.get(),
                "min": k.min,
                "max": k.max,
            })
        })
        .collect();
    serde_json::json!({
        "settings": entries,
        "adaptive_prewarm": s.adaptive_prewarm.load(Ordering::Relaxed),
    })
    .to_string()
}

// ============================================================================
// Upstream health (passive checks, circuit-breaker style)
// ============================================================================
//
// Passive health tracking: dispatch results feed the tracker, and unhealthy
// upstreams are skipped during balancing. A marked upstream stops receiving
// traffic until its recovery cooldown expires, then exactly one request is
// let through (half-open): success closes the circuit, failure re-opens it.
// Without this, failover would dutifully dial a dead backend on every
// request, paying the connect timeout each time.

/// Configurable knobs (JUGGLER_* env), resolved once at startup.
#[derive(Clone)]
pub struct HealthPolicy {
    pub max_failures: usize,
    pub cooldown: Duration,
}

fn health_policy() -> HealthPolicy {
    // Live snapshot of the runtime knobs: the admin panel can retune the
    // circuit breaker on the fly; record()/state_of() read it per request.
    // (The env vars remain the boot-time defaults.)
    HealthPolicy {
        max_failures: settings().health_max_failures.get() as usize,
        cooldown: Duration::from_secs(settings().health_cooldown_secs.get()),
    }
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum HealthState {
    /// Normal operation.
    Healthy,
    /// Failures crossed the threshold; no traffic until the cooldown ends.
    Unhealthy,
    /// Cooldown expired: exactly one probe request may pass.
    HalfOpen,
}

struct UpstreamHealth {
    consecutive_failures: usize,
    state: HealthState,
    /// Instant of the last transition into `Unhealthy` (drives recovery).
    marked_at: Option<Instant>,
    /// Half-open bookkeeping: `true` from the moment `accepts()` grants
    /// the single probe slot until the probe request is recorded. If the
    /// probe attempt itself fails to reach the wire (e.g. the request was
    /// served by another upstream first), the slot is released after
    /// PROBE_GRANT_TTL so the circuit can never wedge half-open forever.
    probe_granted: Option<Instant>,
}

impl UpstreamHealth {
    fn new() -> Self {
        Self {
            consecutive_failures: 0,
            state: HealthState::Healthy,
            marked_at: None,
            probe_granted: None,
        }
    }
}

pub struct HealthTracker {
    inner: Mutex<HashMap<String, UpstreamHealth>>,
    /// Fixed policy override — None reads the live runtime settings
    /// (admin-panel retuning applies immediately).
    custom_policy: Option<HealthPolicy>,
}

impl HealthTracker {
    fn new() -> Self {
        Self {
            inner: Mutex::new(HashMap::new()),
            custom_policy: None, // live settings
        }
    }

    /// Fixed-policy constructor (unit tests): thresholds are frozen.
    fn with_policy(policy: HealthPolicy) -> Self {
        Self {
            inner: Mutex::new(HashMap::new()),
            custom_policy: Some(policy),
        }
    }

    fn policy(&self) -> HealthPolicy {
        self.custom_policy
            .clone()
            .unwrap_or_else(health_policy)
    }

    /// Forget stale entries: upstreams removed by the latest hot reload
    /// disappear from the map, surviving ones keep their history.
    pub fn retain(&self, addrs: impl Iterator<Item = String>) {
        let keep: std::collections::HashSet<String> = addrs.collect();
        self.inner.lock().unwrap().retain(|k, _| keep.contains(k));
    }

    fn entry_for<'a>(
        map: &'a mut HashMap<String, UpstreamHealth>,
        addr: &str,
    ) -> &'a mut UpstreamHealth {
        map.entry(addr.to_string()).or_insert_with(UpstreamHealth::new)
    }

    /// Current (lazily evaluated) health state of one upstream.
    fn state_of(&self, addr: &str) -> HealthState {
        let policy = self.policy();
        let mut map = self.inner.lock().unwrap();
        let entry = Self::entry_for(&mut map, addr);
        if entry.state == HealthState::Unhealthy && entry.marked_at.is_some_and(|t| t.elapsed() >= policy.cooldown)
        {
            // Cooldown expired: switch to half-open and allow one probe.
            entry.state = HealthState::HalfOpen;
            entry.probe_granted = None;
            UPSTREAM_HEALTH_STATE
                .with_label_values(&[addr, "unhealthy"])
                .set(0);
            UPSTREAM_HEALTH_STATE
                .with_label_values(&[addr, "half-open"])
                .set(1);
        }
        entry.state
    }

    /// Record the outcome of one dispatch attempt.
    fn record(&self, addr: &str, success: bool) {
        let policy = self.policy();
        let mut map = self.inner.lock().unwrap();
        let entry = Self::entry_for(&mut map, addr);
        match (success, entry.state) {
            (true, HealthState::HalfOpen) => {
                // Probe succeeded: close the circuit.
                *entry = UpstreamHealth::new();
                UPSTREAM_HEALTH_STATE.with_label_values(&[addr, "half-open"]).set(0);
                UPSTREAM_HEALTH_STATE.with_label_values(&[addr, "healthy"]).set(1);
            }
            (true, _) => {
                if entry.state == HealthState::Unhealthy {
                    UPSTREAM_HEALTH_STATE.with_label_values(&[addr, "unhealthy"]).set(0);
                    UPSTREAM_HEALTH_STATE.with_label_values(&[addr, "healthy"]).set(1);
                }
                entry.consecutive_failures = 0;
                entry.state = HealthState::Healthy;
            }
            (false, HealthState::HalfOpen) => {
                // Probe failed: re-open immediately.
                entry.consecutive_failures = policy.max_failures;
                entry.state = HealthState::Unhealthy;
                entry.marked_at = Some(Instant::now());
                UPSTREAM_HEALTH_STATE.with_label_values(&[addr, "half-open"]).set(0);
                UPSTREAM_HEALTH_STATE.with_label_values(&[addr, "unhealthy"]).set(1);
            }
            (false, _) => {
                entry.consecutive_failures += 1;
                if entry.consecutive_failures >= policy.max_failures
                    && entry.state != HealthState::Unhealthy
                {
                    entry.state = HealthState::Unhealthy;
                    entry.marked_at = Some(Instant::now());
                    eprintln!(
                        "🚑 upstream {addr} marked unhealthy after {} consecutive failures",
                        entry.consecutive_failures
                    );
                    UPSTREAM_HEALTH_STATE.with_label_values(&[addr, "unhealthy"]).set(1);
                }
            }
        }
    }

    /// True if the upstream may receive a request right now. In the
    /// half-open state exactly one caller gets `true` (the probe), everyone
    /// else waits for the probe to settle the circuit. A granted slot
    /// expires after `PROBE_GRANT_TTL`: if the probe request never actually
    /// reached this upstream (least-connections picked another peer), the
    /// slot frees itself instead of wedging the circuit half-open forever.
    fn accepts(&self, addr: &str) -> bool {
        let state = self.state_of(addr);
        if state != HealthState::HalfOpen {
            return state == HealthState::Healthy;
        }
        let mut map = self.inner.lock().unwrap();
        let entry = Self::entry_for(&mut map, addr);
        if let Some(granted_at) = entry.probe_granted {
            if granted_at.elapsed() < PROBE_GRANT_TTL {
                return false; // the single probe slot is already taken
            }
        }
        entry.probe_granted = Some(Instant::now());
        true
    }

    /// True while `addr` is half-open with an unexpired probe grant. The
    /// dispatcher puts such upstreams first, so the granted probe request
    /// deterministically reaches the circuit under test instead of being
    /// lost to a random least-connections tie-break.
    pub fn is_probe_target(&self, addr: &str) -> bool {
        self.state_of(addr) == HealthState::HalfOpen
    }
}

lazy_static! {
    pub static ref HEALTH: HealthTracker = HealthTracker::new();
}

// ============================================================================
// WASM plugin pipeline (wasmtime)
// ============================================================================
//
// A plugin is a .wasm module exporting (the Juggler plugin ABI):
//   on_request()                                         (optional; headers)
//   transform_body(ptr: i32, len: i32) -> i32            (optional; -1 = keep)
//   memory: memory
// and importing host functions from the "juggler" module:
//   alloc(size: i32) -> i32                              (zeroed scratch)
//   add_header(key_ptr: i32, key_len: i32, val_ptr: i32, val_len: i32)
//   path() -> i32        (copies the zero-terminated path to scratch,
//                            returns its length; -1 on failure)
//
// Host -> plugin contract: before every call the host writes the request
// path at PLUG_PATH_PTR into plugin linear memory (and the request body at
// ptr 0 for transform_body), so filters can make path-scoped decisions.
// Headers the plugin registers via juggler.add_header are copied out of
// plugin memory immediately; `transform_body` returns a pointer to a
// buffer holding a little-endian (len: u32) header followed by the
// rewritten bytes.
//
// Sandbox hardening: every call runs in a fresh `Store` with fuel metering
// (wasmtime::Config::consume_fuel) and a capped memory reservation, so a
// runaway or malicious plugin costs bounded CPU and memory and can only
// talk to the host through the three functions above.

const PLUG_BODY_PTR: u32 = 0; // host -> plugin: request body
const PLUG_PATH_PTR: u32 = 1_048_576; // host -> plugin: request path (1 MiB)
const PLUG_SCRATCH_PTR: i32 = 1_052_672; // plugin -> host: add_header strings
const PLUGIN_MEM_LIMIT: usize = 2 * 1024 * 1024; // per-instance memory cap
const PLUGIN_FUEL: u64 = 100_000; // per-call compute budget
const PLUG_SCRATCH_MAX: usize = 4096; // largest alloc() a plugin may request
const MAX_PLUGIN_BODY: usize = 1024 * 1024; // 1 MiB rewrite limit

struct PluginCtx {
    path: String,
    headers: Vec<(String, String)>,
}

/// Shared wasmtime engine: compiled code is cached per module and the
/// Cranelift backend is warmed up once for the whole process.
fn wasm_engine() -> &'static wasmtime::Engine {
    static ENGINE: std::sync::OnceLock<wasmtime::Engine> = std::sync::OnceLock::new();
    ENGINE.get_or_init(|| {
        let mut cfg = wasmtime::Config::new();
        // Deterministic per-call CPU budget: long/infinite loops trap.
        cfg.consume_fuel(true);
        // Hard memory cap for every plugin instance (sandbox).
        cfg.memory_reservation(PLUGIN_MEM_LIMIT as u64);
        cfg.memory_guard_size(0);
        cfg.memory_may_move(false);
        cfg.wasm_multi_value(false);
        cfg.wasm_bulk_memory(true);
        wasmtime::Engine::new(&cfg).expect("failed to create wasmtime engine")
    })
}

/// path -> compiled module. Populated at startup and refreshed (additively)
/// on every hot reload, so Data Plane tasks only ever clone an `Arc`.
static WASM_REGISTRY: std::sync::OnceLock<
    std::sync::Mutex<HashMap<String, Arc<wasmtime::Module>>>,
> = std::sync::OnceLock::new();

fn wasm_registry_lock() -> std::sync::MutexGuard<'static, HashMap<String, Arc<wasmtime::Module>>> {
    WASM_REGISTRY
        .get_or_init(|| std::sync::Mutex::new(HashMap::new()))
        .lock()
        .unwrap()
}

/// Compile every plugin referenced by `routes` that is not cached yet.
/// Missing or broken modules never fail the reload (fail-open), they just
/// log a warning and the route runs without its filter.
fn load_plugin_modules(routes: &HashMap<String, RouteConfig>) {
    let mut registry = wasm_registry_lock();
    for cfg in routes.values() {
        let Some(path) = &cfg.wasm_plugin else { continue };
        if registry.contains_key(path) {
            continue;
        }
        match std::fs::read(path) {
            Ok(bytes) => match wasmtime::Module::from_binary(wasm_engine(), &bytes) {
                Ok(module) => {
                    println!("🧩 WASM plugin loaded: {path}");
                    registry.insert(path.clone(), Arc::new(module));
                }
                Err(e) => eprintln!("⚠️ WASM plugin {path} failed to compile: {e:#}"),
            },
            Err(e) => eprintln!("⚠️ WASM plugin {path} unreadable: {e}"),
        }
    }
}

/// Resolve the compiled module for a route's plugin path (None = fail-open).
fn plugin_for_route(path: &Option<String>) -> Option<Arc<wasmtime::Module>> {
    let path = path.as_ref()?;
    wasm_registry_lock().get(path).cloned()
}

// ---- host functions exposed to every plugin ----------------------------

/// The plugin asks for scratch memory and the host answers with a pointer
/// into its linear memory (after the read-only path window).
fn host_alloc(mut caller: wasmtime::Caller<'_, PluginCtx>, size: i32) -> i32 {
    if size <= 0 || size as usize > PLUG_SCRATCH_MAX {
        return -1;
    }
    let ptr = PLUG_SCRATCH_PTR;
    if let Some(mem) = caller.get_export("memory").and_then(|e| e.into_memory()) {
        let zeroed = vec![0u8; size as usize];
        if mem.write(&mut caller, ptr as usize, &zeroed).is_err() {
            return -1;
        }
    }
    ptr
}

/// The plugin offers (key, value) as bytes in its linear memory; the host
/// copies them out immediately (so the plugin can reuse the scratch space).
fn host_add_header(
    mut caller: wasmtime::Caller<'_, PluginCtx>,
    key_ptr: i32,
    key_len: i32,
    val_ptr: i32,
    val_len: i32,
) -> i32 {
    if key_len <= 0 || val_len < 0 {
        return -1;
    }
    if key_len as usize > 512 || val_len as usize > 4096 {
        return -1;
    }
    let Some(mem) = caller.get_export("memory").and_then(|e| e.into_memory()) else {
        return -1;
    };
    let mut key = vec![0u8; key_len as usize];
    let mut val = vec![0u8; val_len as usize];
    if mem.read(&mut caller, key_ptr as usize, &mut key).is_err()
        || mem.read(&mut caller, val_ptr as usize, &mut val).is_err()
    {
        return -1;
    }
    let (Ok(key), Ok(val)) = (String::from_utf8(key), String::from_utf8(val)) else {
        return -1;
    };
    caller.data_mut().headers.push((key, val));
    0
}

/// The plugin asks the host to write its zero-terminated path into scratch
/// memory and answers with the path length (path starts at PLUG_SCRATCH_PTR).
fn host_path(
    mut caller: wasmtime::Caller<'_, PluginCtx>,
) -> i32 {
    let path = caller.data().path.clone();
    if path.len() > PLUG_SCRATCH_MAX {
        return -1;
    }
    if let Some(mem) = caller.get_export("memory").and_then(|e| e.into_memory()) {
        let mut buf = path.into_bytes();
        buf.push(0); // zero-terminated for convenience
        if mem.write(&mut caller, PLUG_SCRATCH_PTR as usize, &buf).is_err() {
            return -1;
        }
        return (buf.len() - 1) as i32;
    }
    -1
}

// ---- one plugin invocation ---------------------------------------------

/// Run one module against (headers, body). Returns Err on trap, fuel
/// exhaustion or a malformed ABI result - the caller decides fail-open vs
/// fail-closed. `headers` is appended to in place, `body` may be replaced.
fn invoke_plugin(
    module: &wasmtime::Module,
    path: &str,
    headers: &mut Vec<(String, String)>,
    body: &mut Vec<u8>,
) -> Result<(), String> {
    if body.len() > MAX_PLUGIN_BODY {
        return Err("body too large for plugin rewrite".to_string());
    }
    let engine = wasm_engine();
    let mut store = wasmtime::Store::new(engine, PluginCtx { path: path.to_string(), headers: Vec::new() });
    store.set_fuel(PLUGIN_FUEL).map_err(|e| e.to_string())?;
    let mut linker = wasmtime::Linker::<PluginCtx>::new(engine);
    linker.func_wrap("juggler", "alloc", host_alloc).map_err(|e| e.to_string())?;
    linker.func_wrap("juggler", "add_header", host_add_header).map_err(|e| e.to_string())?;
    linker.func_wrap("juggler", "path", host_path).map_err(|e| e.to_string())?;
    let instance = linker.instantiate(&mut store, module).map_err(|e| format!("instantiate: {e:#}"))?;

    // Publish the request path into the plugin's linear memory.
    // get_memory / get_typed_func want `AsContextMut`: pass the store.
    let memory = instance
        .get_memory(&mut store, "memory")
        .ok_or_else(|| "plugin has no `memory` export".to_string())?;

    // Grow plugin memory so the fixed layout fits: body at 0, path window
    // at 1 MiB, scratch behind it. Failure to grow (module declares a hard
    // maximum) is a clean per-request error, not a crash.
    let needed = PLUG_SCRATCH_PTR as usize + PLUG_SCRATCH_MAX;
    if memory.data_size(&store) < needed {
        let pages = (needed - memory.data_size(&store)).div_ceil(65536) as u64;
        memory
            .grow(&mut store, pages)
            .map_err(|e| format!("plugin memory grow failed: {e}"))?;
    }

    // Publish the request path into the plugin's linear memory.
    memory
        .write(&mut store, PLUG_PATH_PTR as usize, path.as_bytes())
        .map_err(|e| format!("path publish failed: {e}"))?;

    // Phase 1: on_request() (optional) - header logic. The plugin calls the
    // juggler.add_header host function while it runs; the host copies every
    // (key, value) out immediately.
    if let Ok(on_request) = instance.get_typed_func::<(), ()>(&mut store, "on_request") {
        on_request
            .call(&mut store, ())
            .map_err(|e| format!("on_request trapped: {e}"))?;
    }

    // Phase 2: transform_body(ptr, len) -> i32 (optional); absence or
    // negative result means "body unchanged".
    if let Ok(tf) = instance.get_typed_func::<(i32, i32), i32>(&mut store, "transform_body") {
        // The body starts at 0; the plugin may rewrite it in place and
        // either return the same pointer or an `alloc`-ed buffer whose
        // first 4 bytes are the little-endian new length.
        memory
            .write(&mut store, PLUG_BODY_PTR as usize, body)
            .map_err(|e| format!("body upload failed: {e}"))?;
        let out = tf
            .call(&mut store, (PLUG_BODY_PTR as i32, body.len() as i32))
            .map_err(|e| format!("transform_body trapped: {e}"))?;
        if out >= 0 {
            // Little-endian (len: u32) header, then the rewritten bytes.
            let mut hdr = [0u8; 4];
            memory
                .read(&store, out as usize, &mut hdr)
                .map_err(|e| format!("result header read failed: {e}"))?;
            let new_len = u32::from_le_bytes(hdr) as usize;
            if new_len > MAX_PLUGIN_BODY || out as usize + 4 + new_len > memory.data_size(&store) {
                return Err("plugin returned an invalid body pointer".to_string());
            }
            let mut new_body = vec![0u8; new_len];
            memory
                .read(&store, out as usize + 4, &mut new_body)
                .map_err(|e| format!("result body read failed: {e}"))?;
            *body = new_body;
        }
    }

    // Hand over the headers the plugin added via add_header().
    let added = std::mem::take(&mut store.data_mut().headers);
    headers.extend(added);
    Ok(())
}

/// Run the per-request filter pipeline: one optional module per route.
/// The body is mutated in place; headers the plugin added via `add_header`
/// are appended to the forwarded request. Returns Err only when the filter
/// *trapped* - callers fail closed with 502 (better a clean error than a
/// silently unfiltered request).
fn run_plugin_filters(
    module: &Option<Arc<wasmtime::Module>>,
    path: &str,
    body: &mut Vec<u8>,
) -> Result<Vec<(String, String)>, String> {
    let Some(module) = module else {
        return Ok(Vec::new());
    };
    let mut headers: Vec<(String, String)> = Vec::new();
    let started = std::time::Instant::now();
    let result = invoke_plugin(module, path, &mut headers, body);
    let outcome = match &result {
        Ok(()) => "ok",
        Err(_) => "error",
    };
    WASM_PLUGIN_CALLS_TOTAL
        .with_label_values(&["filter", outcome])
        .inc();
    WASM_PLUGIN_SECONDS
        .with_label_values(&["filter"])
        .observe(started.elapsed().as_secs_f64());
    result.map(|_| headers)
}

// ============================================================================
// gRPC Control Plane service
// ============================================================================

#[derive(Debug, Default, Clone)]
pub struct MyControlPlane {
    shared_config: Arc<ArcSwap<ServerConfig>>,
    routes_file: String,
}

impl MyControlPlane {
    pub fn new(shared_config: Arc<ArcSwap<ServerConfig>>, routes_file: String) -> Self {
        Self { shared_config, routes_file }
    }
}

#[tonic::async_trait]
impl ControlPlane for MyControlPlane {
    async fn update_config(
        &self,
        request: Request<UpdateConfigRequest>,
    ) -> Result<Response<UpdateConfigResponse>, Status> {
        let req = request.into_inner();
        let mut new_routes = HashMap::new();
        for (path, grpc_route) in req.routes {
            if !path.starts_with('/') {
                return Err(Status::invalid_argument(format!(
                    "route path must start with '/': {path}"
                )));
            }
            if grpc_route.upstream_addrs.is_empty() {
                return Err(Status::invalid_argument(format!(
                    "route {path} must define at least one upstream address"
                )));
            }
            let security = grpc_route.security.unwrap_or_default();
            let response_headers: Vec<(String, String)> = security
                .response_headers
                .into_iter()
                .collect();
            new_routes.insert(
                path,
                RouteConfig {
                    upstream_addrs: grpc_route.upstream_addrs,
                    wasm_plugin: grpc_route.wasm_plugin,
                    security: SecurityConfig {
                        deny_substrings: security.deny_substrings,
                        response_headers,
                        cms_fallback_path: security.cms_fallback_path,
                    },
                    static_dir: None,
                },
            );
        }

        BALANCER.update_registry(&new_routes);
        HEALTH
            .retain(new_routes.values().flat_map(|r| r.upstream_addrs.iter().cloned()));
        // Pick up any new site directories under the document root without
        // a restart: static routes merge over the Control Plane routes.
        let merged = merge_static_routes(&default_static_root(), new_routes);
        let new_config = ServerConfig { routes: merged };
        // Socket pools of removed upstreams must not linger (their sockets
        // would otherwise keep half-open connections to dead peers).
        for removed in BALANCER.connections.load().keys() {
            if !new_config
                .routes
                .values()
                .any(|r| r.upstream_addrs.contains(removed))
            {
                UPSTREAM_POOL.close_all(Some(removed));
            }
        }
        // Pre-compile any newly referenced WASM modules so the Data Plane
        // never pays Cranelift cost inside the request path (and unknown
        // plugins are logged here, fail-open, instead of per request).
        load_plugin_modules(&new_config.routes);
        WASM_MODULES_LOADED.set(wasm_registry_lock().len() as i64);
        persist_config(&new_config, &self.routes_file);
        self.shared_config.store(Arc::new(new_config));
        // Re-warm pools for any newly added upstreams so the first real
        // requests reuse sockets instead of creating them mid-burst.
        prewarm_upstream_pool(&self.shared_config, PREWARM_CONNECTIONS).await;

        println!("🔄 Config hot-reloaded via gRPC Control Plane");
        Ok(Response::new(UpdateConfigResponse {
            success: true,
            message: "Config hot-reloaded atomically".to_string(),
        }))
    }
}

// ============================================================================
// TLS: self-signed certificate, ALPN negotiates h2 + http/1.1
// ============================================================================

fn create_tls_config() -> rustls::ServerConfig {
    // Priority: JUGGLER_TLS_DIR with cert.pem + key.pem (real certificates,
    // e.g. Let's Encrypt / corporate CA) > persistent self-signed pair.
    if let Ok(dir) = std::env::var("JUGGLER_TLS_DIR") {
        match load_pem_pair(&dir) {
            Ok((certs, key)) => {
                println!("🔒 TLS: loaded certificate from {} (cert.pem + key.pem)", dir);
                return build_tls_config(certs, key);
            }
            Err(e) => {
                println!(
                    "⚠️ TLS: JUGGLER_TLS_DIR={} set but unusable ({}); falling back to self-signed",
                    dir, e
                );
            }
        }
    }

    // Persistent self-signed pair: reused across restarts so browsers that
    // once accepted the exception keep working.
    let (certs, key) = match load_pem_pair(".") {
        Ok(pair) => {
            println!("🔒 TLS: reusing existing self-signed cert.pem + key.pem");
            pair
        }
        Err(_) => {
            println!("🔒 TLS: generating new self-signed certificate (localhost, 127.0.0.1)");
            let cert = rcgen::generate_simple_self_signed(vec![
                "localhost".into(),
                "127.0.0.1".into(),
            ])
            .expect("failed to generate self-signed certificate");
            let cert_pem = cert.cert.pem();
            let key_pem = cert.key_pair.serialize_pem();
            let _ = std::fs::write("cert.pem", &cert_pem); // best effort; in-memory fallback below
            let _ = std::fs::write("key.pem", &key_pem);
            (
                vec![CertificateDer::from(cert.cert.der().to_vec())],
                PrivateKeyDer::Pkcs8(cert.key_pair.serialize_der().into()),
            )
        }
    };
    build_tls_config(certs, key)
}

fn build_tls_config(certs: Vec<CertificateDer<'static>>, key: PrivateKeyDer<'static>) -> rustls::ServerConfig {
    let mut cfg = rustls::ServerConfig::builder()
        .with_no_client_auth()
        .with_single_cert(certs, key)
        .expect("failed to initialize TLS config");
    // ALPN: offer HTTP/2 first, HTTP/1.1 as fallback. The chosen protocol is
    // visible via TlsStream::get_ref().1.alpn_protocol() after the handshake.
    cfg.alpn_protocols = vec![b"h2".to_vec(), b"http/1.1".to_vec()];
    cfg
}

fn load_pem_pair(dir: &str) -> Result<(Vec<CertificateDer<'static>>, PrivateKeyDer<'static>), String> {
    let cert_path = format!("{dir}/cert.pem");
    let key_path = format!("{dir}/key.pem");
    let cert_pem = std::fs::read(&cert_path)
    .map_err(|e| format!("{}: {}", cert_path, e))?;
    let key_pem = std::fs::read(&key_path).map_err(|e| format!("{}: {}", key_path, e))?;
    let mut reader = std::io::BufReader::new(&cert_pem[..]);
    let mut certs = Vec::new();
    for item in std::iter::from_fn(|| rustls_pemfile::certs(&mut reader).next()) {
        certs.push(item.map_err(|e| format!("bad cert pem: {}", e))?);
    }
    if certs.is_empty() {
        return Err(format!("{}: no CERTIFICATE blocks", cert_path));
    }
    let mut key_reader = std::io::BufReader::new(&key_pem[..]);
    let key = rustls_pemfile::private_key(&mut key_reader)
        .map_err(|e| format!("bad key pem: {}", e))?
        .ok_or_else(|| format!("{}: no private key found", key_path))?;
    Ok((certs, key))
}

// ============================================================================
// HTTP/1.x request/response helpers (kept side-effect free for unit testing)
// ============================================================================

/// Raw HTTP request as read from the wire (headers + body).
/// Zero-copy: text fields are byte ranges into the ORIGINAL read buffer
/// the request was parsed from (`method`, `path`, `version`, each header).
/// The ranges stay valid as long as the caller keeps that buffer intact —
/// handle_connection does exactly that (it consumes the buffer only after
/// the request is fully dispatched). This removes ~12–14 per-request
/// String allocations a `(String, Vec<(String,String)>)` layout paid.
#[derive(Debug, Clone)]
pub struct RawRequest {
    pub method: (u32, u32),
    /// Path with the query string already stripped (range into the buffer).
    pub path: (u32, u32),
    pub version: (u32, u32),
    /// (name_start, name_end, value_start, value_end) into the buffer.
    pub headers: Vec<(u32, u32, u32, u32)>,
    pub body_offset: usize,
    /// Parsed once here instead of a linear String scan per use site.
    pub content_length_parsed: usize,
    /// Keep-alive decision resolved at parse time (version + Connection).
    pub keep_alive_parsed: bool,
}

impl RawRequest {
    pub fn method<'a>(&self, buf: &'a [u8]) -> &'a str {
        std::str::from_utf8(&buf[self.method.0 as usize..self.method.1 as usize]).unwrap_or("")
    }

    pub fn path<'a>(&self, buf: &'a [u8]) -> &'a str {
        std::str::from_utf8(&buf[self.path.0 as usize..self.path.1 as usize]).unwrap_or("")
    }

    pub fn version<'a>(&self, buf: &'a [u8]) -> &'a str {
        std::str::from_utf8(&buf[self.version.0 as usize..self.version.1 as usize]).unwrap_or("")
    }

    pub fn content_length(&self) -> usize {
        self.content_length_parsed
    }

    pub fn wants_keep_alive(&self) -> bool {
        self.keep_alive_parsed
    }

    /// Case-insensitive header lookup without allocating.
    pub fn header<'a>(&self, buf: &'a [u8], name: &[u8]) -> Option<&'a str> {
        self.headers.iter().find_map(|&(ns, ne, vs, ve)| {
            let n = &buf[ns as usize..ne as usize];
            n.eq_ignore_ascii_case(name)
                .then(|| std::str::from_utf8(&buf[vs as usize..ve as usize]).unwrap_or(""))
        })
    }
}

/// Parse a complete HTTP/1.x request. Returns `None` if the data does not
/// look like a request (connection will be closed). Zero-copy: all fields
/// are byte ranges into `buf`.
pub fn parse_request(buf: &[u8]) -> Option<RawRequest> {
    // Find the head terminator with an unrolled 4-byte scan ( memcmp of a
    // rolling u32 beats 4 overlapping windows in practice).
    let header_end = find_head_end(buf)?;
    let head = std::str::from_utf8(&buf[..header_end]).ok()?;
    let mut lines = head.split("\r\n");

    // ---- request line: method SP target SP version (byte ranges) ----
    let request_line = lines.next()?;
    let rl = request_line.as_bytes();
    let mut sp1 = 0;
    while sp1 < rl.len() && rl[sp1] != b' ' {
        sp1 += 1;
    }
    if sp1 == 0 || sp1 == rl.len() {
        return None;
    }
    let mut sp2 = sp1 + 1;
    while sp2 < rl.len() && rl[sp2] != b' ' {
        sp2 += 1;
    }
    if sp2 == rl.len() {
        return None;
    }
    let method = (0u32, sp1 as u32);
    // Strip query string for routing: /api?x=1 -> /api (range end before '?')
    let target = (sp1 + 1) as u32;
    let mut target_end = sp2 as u32;
    if let Some(q) = rl[sp1 + 1..sp2].iter().position(|&b| b == b'?') {
        target_end = (sp1 + 1 + q) as u32;
    }
    if target == target_end {
        return None;
    }
    let path = (target, target_end);
    let version = (sp2 + 1) as u32;
    let version_end = rl.len() as u32;
    let is_http10 = &rl[(version as usize)..(version_end as usize)] == b"HTTP/1.0";

    // ---- headers: one pass, ranges only; resolve CL + Connection here ----
    let mut headers = Vec::new();
    let mut content_length_parsed = 0usize;
    let mut connection_close = false;
    let mut connection_keepalive10 = false;
    for line in lines {
        let Some(colon) = line.find(':') else {
            continue;
        };
        let (k, v) = line.split_at(colon);
        let v = v[1..].trim();
        let k = k.trim_end();
        if k.eq_ignore_ascii_case("content-length") {
            // Inline parse: no String, no trim-alloc.
            content_length_parsed = v.trim().parse().unwrap_or(0);
        } else if k.eq_ignore_ascii_case("connection") {
            if v.eq_ignore_ascii_case("close") {
                connection_close = true;
            } else if v.eq_ignore_ascii_case("keep-alive") {
                connection_keepalive10 = true;
            }
        }
        headers.push((
            (line.as_ptr() as usize - head.as_ptr() as usize) as u32,
            (line.as_ptr() as usize - head.as_ptr() as usize + k.len()) as u32,
            (v.as_ptr() as usize - head.as_ptr() as usize) as u32,
            (v.as_ptr() as usize - head.as_ptr() as usize + v.len()) as u32,
        ));
    }
    let keep_alive_parsed = if is_http10 {
        connection_keepalive10
    } else {
        !connection_close
    };

    Some(RawRequest {
        method,
        path,
        version: (version, version_end),
        headers,
        body_offset: header_end + 4,
        content_length_parsed,
        keep_alive_parsed,
    })
}

/// Head terminator scan: rolling compare of 4 bytes at every position.
/// (A hand-rolled loop with a 1-byte prefilter on '\n' avoids the windows()
/// iterator overhead of a full 4-byte compare per offset.)
#[inline]
fn find_head_end(buf: &[u8]) -> Option<usize> {
    if buf.len() < 4 {
        return None;
    }
    let mut i = 0usize;
    while i + 4 <= buf.len() {
        // Prefilter: '\n' can only be at i+3 for "\r\n\r\n" starting at i.
        if buf[i + 3] == b'\n' && buf[i] == b'\r' && buf[i + 1] == b'\n' && buf[i + 2] == b'\r' {
            return Some(i);
        }
        i += 1;
    }
    None
}

/// Render a complete HTTP/1.1 response with an explicit Connection header
/// and an HTML content type (for the `/` landing page).
pub fn http_html_response(status_line: &str, body: &str, keep_alive: bool) -> Vec<u8> {
    let mut out = BytesMut::new();
    out.put_slice(
        format!(
            "HTTP/1.1 {}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: {}\r\nConnection: {}\r\n\r\n",
            status_line,
            body.len(),
            if keep_alive { "keep-alive" } else { "close" }
        )
        .as_bytes(),
    );
    out.put_slice(body.as_bytes());
    out.to_vec()
}

/// Build the Location URL for a redirect. The Host header carries the port
/// the CLIENT connected to — i.e. the plain redirect port — so it must be
/// stripped; the destination port always comes from the TLS listener config
/// (clean URL on 443, appended port otherwise). Mirrors nginx `$host`
/// semantics. Pure function — unit-tested.
fn redirect_location(host: &str, target: &str, tls_port: u16) -> String {
    let bare_host = host.split(':').next().unwrap_or("");
    if bare_host.is_empty() {
        return format!("https://127.0.0.1:{tls_port}{target}");
    }
    if tls_port == 443 {
        format!("https://{bare_host}{target}")
    } else {
        format!("https://{bare_host}:{tls_port}{target}")
    }
}

/// Handle one connection on the JUGGLER_REDIRECT_HTTP listener: parse the
/// request line + Host header, answer 301 Location: https://host[:tls_port]/path?query,
/// then close. Deliberately minimal — it never touches routing, rate
/// limiting or upstreams.
pub async fn handle_redirect_connection(
    mut stream: tokio::net::TcpStream,
    tls_port: u16,
) -> std::io::Result<()> {
    let mut buf = vec![0u8; 2048];
    // A request head is typically < 2 KiB; a single read is enough. Timeout
    // guards against slowloris on the redirect port.
    let deadline = tokio::time::Duration::from_secs(10);
    let n = tokio::time::timeout(deadline, stream.read(&mut buf))
        .await
        .unwrap_or(Ok(0))?;
    if n == 0 {
        return Ok(());
    }
    let data = &buf[..n];
    // Request line: METHOD SP TARGET SP VERSION
    let head_end = data.windows(4).position(|w| w == b"\r\n\r\n").map(|p| p + 4).unwrap_or(n);
    let head = std::str::from_utf8(&data[..head_end]).unwrap_or("");
    let mut lines = head.split("\r\n");
    let request_line = lines.next().unwrap_or("");
    let mut parts = request_line.split_whitespace();
    let _method = parts.next().unwrap_or("GET");
    let target = parts.next().unwrap_or("/");
    let mut host = String::new();
    for line in lines {
        let Some((name, value)) = line.split_once(':') else {
            continue;
        };
        if name.trim().eq_ignore_ascii_case("host") {
            host = value.trim().to_string();
            break;
        }
    }
    let location = redirect_location(&host, target, tls_port);
    let body_text = "Moved Permanently";
    let response = format!(
        "HTTP/1.1 301 Moved Permanently\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: {}\r\nLocation: {}\r\nConnection: close\r\n\r\n{}",
        body_text.len(),
        location,
        body_text
    );
    stream.write_all(response.as_bytes()).await?;
    stream.flush().await?;
    Ok(())
}

// ============================================================================
// Admin web UI + JSON API (served on the plain-HTTP admin listener)
// ============================================================================

/// Modern single-page admin shell. Live sections (KPIs, shards, pipeline
/// stages, routes) are refreshed in place by client-side polling of
/// /api/stats — the page itself never reloads. The Settings card is
/// deliberately static: it renders once and re-renders only after an
/// explicit Apply, so values can't reset while the user is typing.
fn admin_ui_html() -> &'static str {
    r##"<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Juggler Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --bg:#0b0e14;--panel:#12161f;--panel2:#171c28;--border:#232a3a;--border2:#2e3850;
  --text:#e8ecf4;--muted:#8b94a7;--dim:#5c6579;
  --green:#4ade80;--blue:#60a5fa;--amber:#fbbf24;--red:#f87171;--violet:#a78bfa;
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:radial-gradient(1200px 500px at 70% -10%,#141a2a 0%,var(--bg) 60%);
  color:var(--text);font:14px/1.55 "Segoe UI",system-ui,-apple-system,sans-serif;
  min-height:100vh;padding:24px clamp(12px,4vw,40px) 40px}
a{color:var(--blue);text-decoration:none}a:hover{text-decoration:underline}
code{background:#0d1119;border:1px solid var(--border);border-radius:5px;padding:1px 6px;font:12px ui-monospace,Consolas,monospace;color:#c3cbdb}
/* ---------- header ---------- */
header{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:20px}
.logo{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;font-size:22px;
  background:linear-gradient(135deg,#1d2740,#10141f);border:1px solid var(--border2);box-shadow:0 4px 18px #0008}
h1{font-size:20px;font-weight:650;letter-spacing:.2px}
h1 span{color:var(--muted);font-weight:400;font-size:13px;margin-left:8px}
.badge{margin-left:auto;display:flex;align-items:center;gap:7px;background:var(--panel);border:1px solid var(--border);
  border-radius:999px;padding:5px 13px;font-size:12.5px;color:var(--muted)}
.dot{width:8px;height:8px;border-radius:50%;background:var(--green);box-shadow:0 0 0 0 #4ade8066;animation:pulse 2s infinite}
.dot.paused{background:var(--amber);animation:none}
@keyframes pulse{0%{box-shadow:0 0 0 0 #4ade8055}70%{box-shadow:0 0 0 7px #4ade8000}100%{box-shadow:0 0 0 0 #4ade8000}}
/* ---------- KPI cards ---------- */
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}
.kpi{background:linear-gradient(180deg,var(--panel2),var(--panel));border:1px solid var(--border);border-radius:14px;
  padding:14px 16px;position:relative;overflow:hidden}
.kpi::after{content:"";position:absolute;inset:0 0 auto 0;height:2px;background:linear-gradient(90deg,transparent,var(--green),transparent);opacity:.35}
.kpi b{display:block;font-size:24px;font-weight:650;font-variant-numeric:tabular-nums;color:var(--green)}
.kpi span{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px}
/* ---------- cards grid ---------- */
.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px}
.card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:16px 18px;min-width:0}
.card h2{font-size:13.5px;font-weight:600;color:var(--blue);text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;
  display:flex;align-items:center;gap:8px}
.card h2 .hint{margin-left:auto;font-size:11.5px;font-weight:400;color:var(--dim);text-transform:none;letter-spacing:0}
.c6{grid-column:span 12}.c7{grid-column:span 12}
@media(min-width:1050px){.c6{grid-column:span 6}.c7{grid-column:span 12}}
.c12{grid-column:span 12}
/* ---------- tables ---------- */
table{border-collapse:collapse;width:100%;font-size:13.5px}
th{color:var(--muted);font-weight:600;text-align:left;padding:6px 10px;border-bottom:1px solid var(--border2);font-size:12px;text-transform:uppercase;letter-spacing:.6px}
td{padding:6px 10px;border-bottom:1px solid var(--border)}
tr:last-child td{border-bottom:none}
tbody tr:hover{background:#ffffff06}
.num{font-variant-numeric:tabular-nums}
.chip{display:inline-block;padding:1px 9px;border-radius:999px;font-size:12px;font-weight:600}
.chip.ok{background:#4ade8018;color:var(--green)}
.chip.warn{background:#fbbf2418;color:var(--amber)}
.chip.dim{background:#8b94a715;color:var(--muted)}
/* ---------- sparkline ---------- */
svg.spark{display:block}
.legend{color:var(--dim);font-size:12px;margin-top:10px}
.legend i{display:inline-block;width:16px;height:3px;border-radius:2px;vertical-align:middle;margin:0 5px 2px 0}
/* ---------- stage bars ---------- */
.stage-row{display:grid;grid-template-columns:110px 1fr 150px;gap:12px;align-items:center;padding:7px 0;border-bottom:1px solid var(--border)}
.stage-row:last-child{border-bottom:none}
.stage-name{font-size:12.5px;color:var(--muted)}
.stage-bar{height:9px;background:#0d1119;border-radius:6px;overflow:hidden;border:1px solid var(--border)}
.stage-fill{height:100%;border-radius:6px;background:linear-gradient(90deg,#2563eb33,var(--blue));transition:width .5s ease}
.stage-val{font-size:12.5px;color:var(--text);text-align:right;font-variant-numeric:tabular-nums}
/* ---------- settings ---------- */
.set-note{font-size:12px;color:var(--dim);margin-bottom:12px}
.set-row{display:grid;grid-template-columns:minmax(150px,230px) 1fr 90px 62px 70px;gap:12px;align-items:center;padding:9px 0;border-bottom:1px solid var(--border)}
.set-row:last-child{border-bottom:none}
.set-name{font-size:13px}
.set-name small{display:block;color:var(--dim);font-size:11px}
.set-range{color:var(--dim);font-size:11px;text-align:right}
input[type=range]{-webkit-appearance:none;width:100%;height:5px;border-radius:4px;background:linear-gradient(90deg,var(--blue) var(--fill,50%),#232a3a var(--fill,50%));outline:none}
input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;width:15px;height:15px;border-radius:50%;background:var(--blue);cursor:pointer;border:2px solid #0b0e14;box-shadow:0 0 0 2px #60a5fa44}
input[type=range]::-moz-range-thumb{width:13px;height:13px;border-radius:50%;background:var(--blue);cursor:pointer;border:2px solid #0b0e14}
input[type=number]{width:74px;background:#0d1119;color:var(--text);border:1px solid var(--border2);border-radius:7px;padding:4px 8px;font:13px ui-monospace,Consolas,monospace}
input[type=number]:focus{outline:none;border-color:var(--blue)}
button.apply{background:#4ade8018;color:var(--green);border:1px solid #4ade8044;border-radius:8px;padding:5px 12px;cursor:pointer;font-weight:600;font-size:12.5px;transition:.15s}
button.apply:hover{background:#4ade8030;border-color:var(--green)}
/* toggle switch */
.switch{position:relative;display:inline-block;width:40px;height:22px;vertical-align:middle}
.switch input{opacity:0;width:0;height:0}
.slider{position:absolute;inset:0;background:#232a3a;border-radius:999px;transition:.2s;cursor:pointer}
.slider::before{content:"";position:absolute;width:16px;height:16px;left:3px;top:3px;background:#8b94a7;border-radius:50%;transition:.2s}
.switch input:checked + .slider{background:#4ade8033}
.switch input:checked + .slider::before{transform:translateX(18px);background:var(--green)}
.seg{display:inline-flex;border:1px solid var(--border2);border-radius:9px;overflow:hidden}
.seg button{background:transparent;color:var(--muted);border:0;padding:5px 13px;cursor:pointer;font-size:12.5px;font-weight:600}
.seg button.on{background:#60a5fa22;color:var(--blue)}
#set-status{font-size:12.5px;color:var(--green);margin-left:10px}
/* ---------- footer ---------- */
footer{margin-top:22px;color:var(--dim);font-size:12.5px;display:flex;gap:16px;flex-wrap:wrap}
</style></head><body>
<header>
  <div class="logo">🤹</div>
  <h1>Juggler Admin <span id="ver"></span></h1>
  <div class="badge"><span class="dot" id="dot"></span><span id="live-label">live · 1.5s</span></div>
</header>

<div class="kpis">
  <div class="kpi"><b id="k-shards">–</b><span>shards</span></div>
  <div class="kpi"><b id="k-conns">–</b><span>active conns</span></div>
  <div class="kpi"><b id="k-rps">–</b><span>req/s</span></div>
  <div class="kpi"><b id="k-accepted">–</b><span>accepted</span></div>
  <div class="kpi"><b id="k-routes">–</b><span>routes</span></div>
  <div class="kpi"><b id="k-uptime">–</b><span>uptime</span></div>
</div>

<div class="grid">
  <section class="card c7">
    <h2>Shards — load &amp; forecast <span class="hint" id="spark-hint">window 4 min · per-core</span></h2>
    <table><thead><tr><th>#</th><th>active</th><th>accepted</th><th>level</th><th>trend</th><th>forecast</th><th>load → forecast</th></tr></thead>
    <tbody id="shard-rows"><tr><td colspan="7" style="color:var(--dim)">loading…</td></tr></tbody></table>
    <div class="legend"><i style="background:var(--blue)"></i>level (smoothed load)&nbsp;&nbsp;<i style="background:var(--green)"></i>Holt forecast (leads on ramp-up)</div>
  </section>

  <section class="card c6">
    <h2>Pipeline stages <span class="hint">since last sample · 2 s</span></h2>
    <div id="stage-box"><div class="set-note">waiting for first sample…</div></div>
  </section>

  <section class="card c6">
    <h2>Routes &amp; pools</h2>
    <table><tbody id="route-rows"><tr><td style="color:var(--dim)">loading…</td></tr></tbody></table>
  </section>

  <section class="card c12">
    <h2>Fine tuning <span class="hint">static — applies on demand, never auto-refreshed</span></h2>
    <div class="set-note">Sliders and number fields are linked. Values apply only when you press <b>Apply</b> — the panel never re-renders this card on its own.</div>
    <div id="settings-box"><div class="set-note">loading…</div></div>
    <span id="set-status"></span>
  </section>
</div>

<footer>
  <span>JSON: <code>/api/stats</code> · <code>/api/routes</code> · <code>/api/settings</code></span>
  <span>Auth: <code>Authorization: Bearer $JUGGLER_ADMIN_TOKEN</code></span>
</footer>

<script>
"use strict";
const $ = id => document.getElementById(id);
let lastAccepted = null, lastT = null;

/* ---------- sparkline (client-side SVG) ---------- */
function spark(level, fc, w=190, h=36){
  const n = Math.max(level.length, fc.length);
  if(n < 2) return '<svg class="spark" width="'+w+'" height="'+h+'"></svg>';
  const max = Math.max(1, ...level, ...fc);
  const pts = a => {
    const step = (w-4)/(n-1);
    return a.map((v,i)=>((2+i*step).toFixed(1))+','+((h-3-(v/max)*(h-6)).toFixed(1))).join(' ');
  };
  return '<svg class="spark" width="'+w+'" height="'+h+'">'
    +'<polyline fill="none" stroke="#60a5fa" stroke-width="1.6" points="'+pts(level)+'"/>'
    +'<polyline fill="none" stroke="#4ade80" stroke-width="1.6" stroke-dasharray="4 3" points="'+pts(fc)+'"/></svg>';
}
function fmtUptime(s){
  const d=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60);
  return (d?d+'d ':'')+(h?h+'h ':'')+m+'m';
}
/* ---------- live sections (refreshed in place) ---------- */
async function poll(){
  try{
    const r = await fetch('/api/stats');
    const d = await r.json();
    $('ver').textContent = 'v'+d.version;
    $('k-shards').textContent = d.shards.length;
    $('k-routes').textContent = d.routes.length;
    $('k-uptime').textContent = fmtUptime(d.uptime_secs);
    const active = d.shards.reduce((a,s)=>a+s.active_conns,0);
    const accepted = d.shards.reduce((a,s)=>a+s.accepted_total,0);
    $('k-conns').textContent = active;
    $('k-accepted').textContent = accepted.toLocaleString('en-US');
    if(lastAccepted !== null && lastT !== null){
      const dt = (performance.now()-lastT)/1000;
      $('k-rps').textContent = dt>0 ? Math.max(0,(accepted-lastAccepted)/dt).toFixed(0) : '–';
    }
    lastAccepted = accepted; lastT = performance.now();

    // shards
    $('spark-hint').textContent = 'window ' + Math.round(d.history_cap*d.history_step_secs/60) + ' min · ' + d.history_cap + ' pts · ' + d.history_step_secs + 's';
    $('shard-rows').innerHTML = d.shards.map((s,i)=>{
      const h = (d.history||[])[i]||{level:[],forecast:[]};
      const cls = s.trend>0.5?'warn':'ok';
      const trendTxt = (s.trend>0?'+':'') + s.trend.toFixed(2);
      return '<tr><td class="num">'+s.shard+'</td><td class="num">'+s.active_conns+'</td>'
        +'<td class="num">'+s.accepted_total.toLocaleString('en-US')+'</td>'
        +'<td class="num">'+s.level.toFixed(1)+'</td>'
        +'<td><span class="chip '+cls+'">'+trendTxt+'</span></td>'
        +'<td class="num">'+s.forecast.toFixed(1)+'</td>'
        +'<td>'+spark(h.level,h.forecast)+'</td></tr>';
    }).join('');

    // pipeline stages
    if(d.stages && d.stages.length){
      const maxAvg = Math.max(1e-9, ...d.stages.map(s=>s.avg_us));
      $('stage-box').innerHTML = d.stages.map(s=>{
        const w = Math.max(2, s.avg_us/maxAvg*100).toFixed(1);
        return '<div class="stage-row"><div class="stage-name">'+s.stage+'</div>'
          +'<div class="stage-bar"><div class="stage-fill" style="width:'+w+'%"></div></div>'
          +'<div class="stage-val">avg '+s.avg_us.toFixed(1)+' µs · max '+s.p99_ms.toFixed(2)+' ms</div></div>';
      }).join('');
    }

    // routes
    $('route-rows').innerHTML = d.routes.map(r=>{
      const ups = (r.pool_idle||[]).map(p=>p.addr+' <span style="color:var(--dim)">('+p.idle+')</span>').join('<br>');
      const wasm = r.wasm_plugin ? '<span class="chip ok">wasm</span>' : '<span class="chip dim">—</span>';
      const sec = r.security||{};
      return '<tr><td><code>'+r.path+'</code></td><td>'+ups+'</td><td>'+wasm+'</td>'
        +'<td style="color:var(--dim)">deny '+sec.deny_count+' · hdrs '+sec.response_headers_count+'</td></tr>';
    }).join('') || '<tr><td style="color:var(--dim)">no routes</td></tr>';

    $('dot').classList.remove('paused');
    $('live-label').textContent = 'live · 1.5s';
  }catch(e){
    $('dot').classList.add('paused');
    $('live-label').textContent = 'reconnecting…';
  }
}
setInterval(()=>{ if(!document.hidden) poll(); }, 1500);
poll();

/* ---------- settings (STATIC: rendered once, re-rendered only after Apply) ---------- */
const SETTINGS_HINTS = {
  balancer:'0 leastconn · 1 predictive',
  rate_limit_tokens:'per-IP burst capacity',
  rate_limit_refill:'tokens per second',
  pool_cap_per_upstream:'keep-alive socket cap',
  max_keepalive_requests:'requests per client conn',
  pool_idle_timeout_secs:'idle socket lifetime',
  prewarm_floor:'min warm sockets',
  health_max_failures:'failures before DEAD',
  health_cooldown_secs:'DEAD → half-open delay',
};
async function loadSettings(){
  const r = await fetch('/api/settings');
  const d = await r.json();
  let h = '';
  for(const s of d.settings){
    if(s.name === 'balancer'){
      h += '<div class="set-row"><div class="set-name"><code>'+s.name+'</code><small>'+(SETTINGS_HINTS[s.name]||'')+'</small></div>'
        +'<div class="seg"><button id="seg-bal-0" class="'+(s.value===0?'on':'')+'" onclick="applySetting(\'balancer\',0)">leastconn</button>'
        +'<button id="seg-bal-1" class="'+(s.value===1?'on':'')+'" onclick="applySetting(\'balancer\',1)">predictive</button></div>'
        +'<div></div><div></div><div></div></div>';
      continue;
    }
    const fill = ((s.value-s.min)/Math.max(1,(s.max-s.min))*100).toFixed(1);
    h += '<div class="set-row"><div class="set-name"><code>'+s.name+'</code><small>'+(SETTINGS_HINTS[s.name]||'')+'</small></div>'
      +'<input type="range" min="'+s.min+'" max="'+s.max+'" step="1" value="'+s.value+'" id="rng-'+s.name+'" oninput="syncNum(\''+s.name+'\',this.value,this)">'
      +'<div class="set-range">'+s.min+' … '+s.max+'</div>'
      +'<input type="number" min="'+s.min+'" max="'+s.max+'" value="'+s.value+'" id="num-'+s.name+'" oninput="syncRng(\''+s.name+'\',this.value,this)" onchange="applySetting(\''+s.name+'\')">'
      +'<button class="apply" onclick="applySetting(\''+s.name+'\')">Apply</button></div>';
  }
  h += '<div class="set-row"><div class="set-name"><code>adaptive_prewarm</code><small>keep pools warm at high-water mark</small></div>'
    +'<label class="switch"><input type="checkbox" id="num-adaptive_prewarm" '+(d.adaptive_prewarm?'checked':'')
    +' onchange="applySetting(\'adaptive_prewarm\')"><span class="slider"></span></label>'
    +'<div></div><div></div><div></div></div>';
  $('settings-box').innerHTML = h;
}
function syncNum(name,v,el){ $('num-'+name).value = v; paint(el||$('rng-'+name)); }
function syncRng(name,v,el){ const r=$('rng-'+name); if(r){ r.value=v; paint(r);} }
function paint(el){ if(el) el.style.setProperty('--fill', ((el.value-el.min)/Math.max(1,el.max-el.min)*100)+'%'); }
async function applySetting(name, value){
  if(value === undefined){
    const el = $('num-'+name);
    value = el.type === 'checkbox' ? el.checked : parseInt(el.value);
  }
  const r = await fetch('/api/settings',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({[name]:value})});
  const d = await r.json().catch(()=>({}));
  const st = $('set-status');
  st.textContent = r.ok ? '✓ '+name+' applied' : '✗ '+(d&&d.error||'error');
  st.style.color = r.ok ? 'var(--green)' : 'var(--red)';
  setTimeout(()=>{ st.textContent=''; }, 2500);
  if(r.ok && name!=='adaptive_prewarm') loadSettings(); // explicit re-render only after successful apply
  else if(r.ok){ const c=$('num-adaptive_prewarm'); if(c) c.checked = !!value; }
}
loadSettings();
</script></body></html>"##
}

/// Build the full admin JSON snapshot (also reused by /api/stats).
fn admin_stats_json() -> String {
    let shards = shard_stats_snapshot();
    let cfg = ADMIN_CONFIG
        .get_or_init(|| Arc::new(ArcSwap::from_pointee(ServerConfig::default())));
    let routes: Vec<serde_json::Value> = cfg
        .load()
        .routes
        .iter()
        .map(|(path, r)| {
            serde_json::json!({
                "path": path,
                "upstreams": r.upstream_addrs,
                "wasm_plugin": r.wasm_plugin,
                "security": {
                    "deny_count": r.security.deny_substrings.len(),
                    "response_headers_count": r.security.response_headers.len(),
                    "cms_fallback_path": r.security.cms_fallback_path,
                },
                "pool_idle": r.upstream_addrs.iter().map(|a| serde_json::json!({"addr": a, "idle": UPSTREAM_POOL.pooled_len(a)})).collect::<Vec<_>>(),
            })
        })
        .collect();
    let mut pool_sizes = Vec::new();
    for addr in cfg.load().routes.values().flat_map(|r| r.upstream_addrs.iter()) {
        pool_sizes.push(serde_json::json!({"addr": addr, "idle": UPSTREAM_POOL.pooled_len(addr)}));
    }
    // Sparkline history: parallel level/forecast arrays per shard (oldest first).
    let history: Vec<serde_json::Value> = history_snapshot()
        .iter()
        .map(|q| {
            serde_json::json!({
                "level": q.iter().map(|p| p.level).collect::<Vec<_>>(),
                "forecast": q.iter().map(|p| p.forecast).collect::<Vec<_>>(),
            })
        })
        .collect();
    // Stage latency telemetry: last folded sampler totals (updated every
    // SHARD_SAMPLE_INTERVAL) — shows exactly which stage eats the time.
    let stages: Vec<serde_json::Value> = {
        let slot = stage_totals_slot().lock().unwrap();
        match slot.as_ref() {
            Some((_, t)) => (0..STAGE_COUNT)
                .map(|s| {
                    serde_json::json!({
                        "stage": STAGE_NAMES[s],
                        "count": t.count[s],
                        "avg_us": (t.avg_us[s] * 100.0).round() / 100.0,
                        "p99_ms": (t.p99_ms[s] * 100.0).round() / 100.0,
                    })
                })
                .collect(),
            None => Vec::new(),
        }
    };
    let body = serde_json::json!({
        "version": env!("CARGO_PKG_VERSION"),
        "uptime_secs": ADMIN_START
            .get_or_init(std::time::Instant::now)
            .elapsed()
            .as_secs(),
        "shards": shards,
        "routes": routes,
        "pool": pool_sizes,
        "history": history,
        "history_step_secs": SHARD_SAMPLE_INTERVAL.as_secs(),
        "history_cap": FORECAST_HISTORY_CAP,
        "stages": stages,
    });
    serde_json::to_string_pretty(&body).unwrap_or_else(|_| "{}".into())
}

/// Serve one admin connection: minimal HTTP/1.1, bearer-token auth on /api.
async fn serve_admin_connection(mut stream: tokio::net::TcpStream) -> std::io::Result<()> {
    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    let mut buf = vec![0u8; 8192];
    let mut n = tokio::time::timeout(std::time::Duration::from_secs(10), stream.read(&mut buf))
        .await
        .unwrap_or(Ok(0))?;
    if n == 0 {
        return Ok(());
    }
    // Read to (head + Content-Length) so a POST whose body arrives in a
    // separate TCP segment is parsed correctly.
    loop {
        let data = &buf[..n];
        let Some(hp) = data.windows(4).position(|w| w == b"\r\n\r\n") else {
            break;
        };
        let head_end = hp + 4;
        let cl: usize = String::from_utf8_lossy(&data[..hp])
            .lines()
            .find_map(|l| {
                let (k, v) = l.split_once(':')?;
                k.trim().eq_ignore_ascii_case("content-length")
                    .then(|| v.trim().parse().ok())
                    .flatten()
            })
            .unwrap_or(0);
        if n >= head_end + cl || n == buf.len() {
            break;
        }
        buf.resize((head_end + cl).max(buf.len()), 0);
        let got = tokio::time::timeout(
            std::time::Duration::from_secs(10),
            stream.read(&mut buf[n..]),
        )
        .await
        .unwrap_or(Ok(0))?;
        if got == 0 {
            break;
        }
        n += got;
    }
    let data = &buf[..n];
    let head_end = data
        .windows(4)
        .position(|w| w == b"\r\n\r\n")
        .map(|p| p + 4)
        .unwrap_or(n);
    let head = std::str::from_utf8(&data[..head_end]).unwrap_or("");
    let mut lines = head.split("\r\n");
    let request_line = lines.next().unwrap_or("");
    let mut parts = request_line.split_whitespace();
    let method = parts.next().unwrap_or("");
    let path = parts.next().unwrap_or("/");
    let path_only = path.split('?').next().unwrap_or(path);

    let token = std::env::var("JUGGLER_ADMIN_TOKEN").ok();
    let auth_ok = match &token {
        None => true, // auth disabled by default (local management)
        Some(expected) => {
            let bearer = format!("Bearer {expected}");
            data.windows(bearer.len())
                .any(|w| w.eq_ignore_ascii_case(bearer.as_bytes()))
    }
    };

    let (status, ctype, body): (u16, &str, String) = match (path_only, auth_ok) {
        (_, false) => (
            401,
            "text/plain; charset=utf-8",
            "unauthorized: set Authorization: Bearer <JUGGLER_ADMIN_TOKEN>".into(),
        ),
        ("/api/stats", true) => (200, "application/json", admin_stats_json()),
        ("/api/routes", true) if method == "POST" => {
            // Body after the head: accept full routes JSON (same schema as
            // routes.json / gRPC UpdateConfig) — validated before store.
            let body_bytes = &data[head_end..];
            match serde_json::from_slice::<ServerConfig>(body_bytes) {
                Ok(new_cfg) => {
                    if new_cfg.routes.is_empty() {
                        (400, "text/plain; charset=utf-8", "empty routes".into())
                    } else {
                        let ok = apply_admin_config(new_cfg).await;
                        match ok {
                            Ok(msg) => (200, "application/json", msg),
                            Err(e) => (400, "text/plain; charset=utf-8", format!("invalid: {e:#}")),
                        }
                    }
                }
                Err(e) => (400, "text/plain; charset=utf-8", format!("bad JSON: {e}")),
            }
        }
        ("/api/routes", true) => {
            // GET: current routes as JSON.
            let cfg = ADMIN_CONFIG
                .get_or_init(|| Arc::new(ArcSwap::from_pointee(ServerConfig::default())));
            let json = serde_json::to_string_pretty(cfg.load().as_ref()).unwrap_or_default();
            (200, "application/json", json)
        }
        ("/api/settings", true) if method == "POST" => {
            // Partial update: {"name": value, ...}; values are clamped to
            // knob bounds, unknown names rejected. Persisted to settings.json.
            let body_bytes = &data[head_end..];
            match serde_json::from_slice::<std::collections::HashMap<String, serde_json::Value>>(
                body_bytes,
            ) {
                Ok(updates) => match apply_settings_update(&updates) {
                    Ok(n) => (
                        200,
                        "application/json",
                        format!("{{\"success\":true,\"applied\":{n}}}"),
                    ),
                    Err(e) => (400, "text/plain; charset=utf-8", format!("invalid: {e:#}")),
                },
                Err(e) => (400, "text/plain; charset=utf-8", format!("bad JSON: {e}")),
            }
        }
        ("/api/settings", true) => (200, "application/json", settings_json()),
        ("/", true) => {
            // SPA shell: live sections poll /api/stats client-side; the
            // settings card is static (no auto-refresh to clobber inputs).
            (200, "text/html; charset=utf-8", admin_ui_html().to_string())
        }
        _ => (404, "text/plain; charset=utf-8", "not found".into()),
    };

    let response = format!(
        "HTTP/1.1 {} {}\r\nContent-Type: {}\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
        status,
        if status == 200 { "OK" } else if status == 401 { "Unauthorized" } else if status == 400 { "Bad Request" } else { "Not Found" },
        ctype,
        body.len(),
        body
    );
    stream.write_all(response.as_bytes()).await?;
    stream.flush().await?;
    Ok(())
}

/// Atomically swap the shared config from the admin API (same validation
/// path as the gRPC Control Plane: persist + registry + health + prewarm).
async fn apply_admin_config(new_cfg: ServerConfig) -> anyhow::Result<String> {
    for (path, r) in &new_cfg.routes {
        if !path.starts_with('/') {
            anyhow::bail!("route path must start with '/': {path}");
        }
        if r.upstream_addrs.is_empty() && r.static_dir.is_none() {
            anyhow::bail!("route {path} must define at least one upstream");
        }
    }
    // Static docroot routes must survive admin hot-reload exactly like they
    // survive gRPC Control Plane updates: merge over the posted routes.
    let merged_routes = merge_static_routes(&default_static_root(), new_cfg.routes);
    let new_cfg = ServerConfig { routes: merged_routes };
    let cfg = ADMIN_CONFIG
        .get_or_init(|| Arc::new(ArcSwap::from_pointee(ServerConfig::default())));
    BALANCER.update_registry(&new_cfg.routes);
    HEALTH
        .retain(new_cfg.routes.values().flat_map(|r| r.upstream_addrs.iter().cloned()));
    let routes_file = ADMIN_ROUTES_FILE
        .get_or_init(|| std::env::var("JUGGLER_ROUTES_FILE").unwrap_or_else(|_| "routes.json".into()))
        .clone();
    persist_config(&new_cfg, &routes_file);
    cfg.store(Arc::new(new_cfg));
    prewarm_upstream_pool(cfg, PREWARM_CONNECTIONS).await;
    Ok("{\"success\":true,\"message\":\"config hot-reloaded\"}".to_string())
}

static ADMIN_CONFIG: OnceLock<Arc<ArcSwap<ServerConfig>>> = OnceLock::new();
static ADMIN_ROUTES_FILE: OnceLock<String> = OnceLock::new();
static ADMIN_START: std::sync::OnceLock<std::time::Instant> = OnceLock::new();

/// Spawn the admin listener on a dedicated thread (detached from shards:
/// management must stay responsive even if data-plane cores are saturated).
fn spawn_admin_server(cfg: Arc<ArcSwap<ServerConfig>>, routes_file: String) {
    let addr = std::env::var("JUGGLER_ADMIN_ADDR").unwrap_or_else(|_| "127.0.0.1:9090".into());
    let _ = ADMIN_CONFIG.set(cfg);
    let _ = ADMIN_ROUTES_FILE.set(routes_file);
    let _ = ADMIN_START.set(std::time::Instant::now());
    std::thread::Builder::new()
        .name("admin-ui".into())
        .spawn(move || {
            let rt = tokio::runtime::Builder::new_current_thread()
                .enable_all()
                .build()
                .expect("admin runtime");
            rt.block_on(async move {
                let listener = match tokio::net::TcpListener::bind(&addr).await {
                    Ok(l) => l,
                    Err(e) => {
                        eprintln!("⚠️ admin UI disabled: cannot bind {addr}: {e}");
                        return;
                    }
                };
                println!("🖥️  Admin UI listening on http://{addr}");
                loop {
                    match listener.accept().await {
                        Ok((stream, _peer)) => {
                            let _ = stream.set_nodelay(true);
                            tokio::spawn(serve_admin_connection(stream));
                        }
                        Err(e) => eprintln!("❌ admin accept error: {e}"),
                    }
                }
            });
        })
        .expect("failed to spawn admin thread");
}

/// Render a complete HTTP/1.1 response with an explicit Connection header.
pub fn http_response(status_line: &str, body: &str, keep_alive: bool) -> Vec<u8> {
    let mut out = BytesMut::new();
    out.put_slice(
        format!(
            "HTTP/1.1 {}\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: {}\r\nConnection: {}\r\n\r\n",
            status_line,
            body.len(),
            if keep_alive { "keep-alive" } else { "close" }
        )
        .as_bytes(),
    );
    out.put_slice(body.as_bytes());
    out.to_vec()
}

/// Rewrite the `Connection` header of a raw upstream response to keep or
/// close the client connection. If the response carries no Connection
/// header and the connection must close, one is appended to the head.
pub fn patch_connection_header(raw: &[u8], keep_alive: bool) -> Vec<u8> {
    let mut out = raw.to_vec();
    let replacement: &[u8] = if keep_alive {
        b"Connection: keep-alive\r\n"
    } else {
        b"Connection: close\r\n"
    };

    let mut start = None;
    let mut end = None;
    for i in 0..out.len() {
        if i + 11 <= out.len() && out[i..i + 11].eq_ignore_ascii_case(b"Connection:") {
            start = Some(i);
            // header value ends at \r\n
            if let Some(rel) = out[i..].windows(2).position(|w| w == b"\r\n") {
                end = Some(i + rel + 2);
            }
            break;
        }
    }

    if let (Some(s), Some(e)) = (start, end) {
        out.splice(s..e, replacement.iter().copied());
    } else if !keep_alive {
        // Append `Connection: close` before the end of the header block so
        // the client does not expect more responses on this connection.
        if let Some(pos) = out.windows(4).position(|w| w == b"\r\n\r\n") {
            out.splice(pos..pos, b"Connection: close\r\n".iter().copied());
        }
    }
    out
}

/// Prepare a raw upstream HTTP/1.1 response for delivery to an HTTP/1.1
/// client. Zero-copy variant: rewrites the Connection header in place in
/// the owned response buffer (replacing "close" with "keep-alive" needs
/// +6 bytes — handled by splicing; appending "Connection: close" splices
/// into the head) and normalizes HTTP/1.0 → HTTP/1.1 in place. Falls back
/// to the copying path when the buffer is not a valid HTTP response head.
pub fn normalize_response_in_place(mut raw: Vec<u8>, keep_alive: bool) -> Vec<u8> {
    if !raw.starts_with(b"HTTP/") {
        return patch_connection_header(&raw, keep_alive);
    }
    // Find head terminator and the existing Connection header (line-aligned).
    let head_end = raw
        .windows(4)
        .position(|w| w == b"\r\n\r\n")
        .unwrap_or(raw.len());
    let mut conn = None;
    let mut pos = 0;
    while pos < head_end {
        let Some(rel) = raw[pos..head_end]
            .windows(11)
            .position(|w| w.eq_ignore_ascii_case(b"Connection:"))
        else {
            break;
        };
        let start = pos + rel;
        if start == 0 || raw[start - 1] == b'\n' {
            conn = Some(start);
            break;
        }
        pos = start + 11;
    }
    match (keep_alive, conn) {
        (true, Some(start)) => {
            // Replace the whole header line with "Connection: keep-alive\r\n".
            let end = start
                + raw[start..head_end]
                    .windows(2)
                    .position(|w| w == b"\r\n")
                    .map(|p| p + 2)
                    .unwrap_or(0);
            let repl = b"Connection: keep-alive\r\n";
            raw.splice(start..end, repl.iter().copied());
        }
        (false, Some(start)) => {
            let end = start
                + raw[start..head_end]
                    .windows(2)
                    .position(|w| w == b"\r\n")
                    .map(|p| p + 2)
                    .unwrap_or(0);
            let repl = b"Connection: close\r\n";
            raw.splice(start..end, repl.iter().copied());
        }
        (false, None) => {
            // Append "Connection: close\r\n" right before the head terminator.
            if let Some(p) = raw.windows(4).position(|w| w == b"\r\n\r\n") {
                raw.splice(p + 2..p + 2, b"Connection: close\r\n".iter().copied());
            }
        }
        (true, None) => {}
    }
    // Version normalization in place (same length).
    if raw.starts_with(b"HTTP/1.0 ") {
        raw[7] = b'1';
    }
    raw
}

/// Copying compatibility wrapper (kept for tests and non-owned buffers).
pub fn normalize_response_for_client(raw: &[u8], keep_alive: bool) -> Vec<u8> {
    let mut out = patch_connection_header(raw, keep_alive);
    if out.starts_with(b"HTTP/1.0 ") {
        out[7] = b'1';
    }
    out
}

/// Append security response headers (from the route policy) into a raw
/// response head. `Header always set` semantics: if the upstream already
/// sent a header with the same name, its value is replaced.
pub fn with_security_headers(mut raw: Vec<u8>, headers: &[(String, String)]) -> Vec<u8> {
    if headers.is_empty() || !raw.starts_with(b"HTTP/") {
        return raw;
    }
    let mut head_end = match raw.windows(4).position(|w| w == b"\r\n\r\n") {
        // p is the start of the head-terminating blank line's CRLF; the
        // final header's own CRLF lives just before it and must stay inside
        // the replacement window.
        Some(p) => p + 2,
        None => return raw,
    };
    // Replace semantics: strip existing occurrences of the same header name.
    for (k, _) in headers {
        let needle = format!("{k}:").to_ascii_lowercase().into_bytes();
        let mut pos = 0;
        while pos < head_end {
            let Some(rel) = raw[pos..head_end]
                .windows(needle.len())
                .position(|w| w.eq_ignore_ascii_case(&needle))
            else {
                break;
            };
            let start = pos + rel;
            // Only a line start counts (avoid matching mid-value).
            let line_start = start == 0 || &raw[start - 1..start] == b"\n";
            let end = start
                + raw[start..head_end]
                    .windows(2)
                    .position(|w| w == b"\r\n")
                    .map(|p| p + 2)
                    .unwrap_or(0);
            if line_start && end > start {
                raw.splice(start..end, []);
                head_end -= end - start;
            } else {
                pos = start + needle.len();
            }
        }
    }
    let mut inject = Vec::new();
    for (k, v) in headers {
        inject.extend_from_slice(format!("{k}: {v}\r\n").as_bytes());
    }
    raw.splice(head_end..head_end, inject);
    raw
}

/// Title-case a lowercase HTTP header name: "content-type" -> "Content-Type".
fn title_case_header(name: &str) -> String {
    name.split('-')
        .map(|part| {
            let mut c = part.chars();
            match c.next() {
                Some(first) => first.to_uppercase().collect::<String>() + c.as_str(),
                None => String::new(),
            }
        })
        .collect::<Vec<_>>()
        .join("-")
}

// ============================================================================
// Upstream response reading (HTTP/1.1, Content-Length + chunked aware)
// ============================================================================

const MAX_UPSTREAM_RESPONSE: usize = 64 * 1024 * 1024;
const UPSTREAM_READ_TIMEOUT: Duration = Duration::from_secs(30);
const UPSTREAM_CONNECT_TIMEOUT: Duration = Duration::from_secs(3);
/// Idle keep-alive sockets older than this are discarded on pruning.
// (idle socket lifetime is the live knob settings().pool_idle_timeout_secs)
/// How often the pruning task walks the pool.
const UPSTREAM_POOL_PRUNE_INTERVAL: Duration = Duration::from_secs(10);
/// Idle sockets opened per upstream at startup / hot-reload (pre-warm).
const PREWARM_CONNECTIONS: usize = 256;

/// How often the telemetry sampler folds shard counters into the Holt state.
const SHARD_SAMPLE_INTERVAL: std::time::Duration = std::time::Duration::from_secs(2);

/// Adaptive prewarm target: keep the pool slightly warm instead of cold.
/// Target = half of the highest concurrent upstream-connection count ever
/// seen (high-water mark), clamped to [32, pool cap]. Called periodically;
/// re-warms only when the target changed.
fn adaptive_prewarm_tick(config: &Arc<ArcSwap<ServerConfig>>) {
    static HWM: AtomicUsize = AtomicUsize::new(0);
    static LAST_TARGET: AtomicUsize = AtomicUsize::new(0);
    if !settings().adaptive_prewarm.load(Ordering::Relaxed) {
        return; // disabled from the admin panel: pools stay as-is
    }
    let active = ACTIVE_UPSTREAM_CONNECTIONS.get() as usize;
    if active > HWM.load(Ordering::Relaxed) {
        HWM.store(active, Ordering::Relaxed);
    }
    let target = (HWM.load(Ordering::Relaxed) / 2)
        .max(settings().prewarm_floor.get() as usize)
        .min(upstream_pool_cap());
    if target != LAST_TARGET.swap(target, Ordering::Relaxed) {
        let cfg = config.clone();
        tokio::spawn(async move {
            prewarm_upstream_pool(&cfg, target).await;
            println!("🔥 Adaptive prewarm target: {target}");
        });
    }
}
/// Lifetime of a granted half-open probe slot. Covers a full request
/// attempt (connect + forward); if the probe request did not actually
/// reach this upstream within the window, the slot self-releases so the
/// circuit cannot stay half-open forever.
const PROBE_GRANT_TTL: Duration = Duration::from_secs(4);

/// Given the buffered bytes of an HTTP/1.1 response, determine the total
/// number of bytes belonging to it (head + body). Returns `None` when the
/// end cannot be determined yet (no Content-Length, no chunked framing):
/// in that case the response ends at the upstream EOF.
pub fn detect_response_end(buf: &[u8]) -> Option<usize> {
    let header_end = buf.windows(4).position(|w| w == b"\r\n\r\n")?;
    let head = std::str::from_utf8(&buf[..header_end]).ok()?;

    let mut content_length: Option<usize> = None;
    let mut chunked = false;
    for line in head.split("\r\n").skip(1) {
        if let Some((k, v)) = line.split_once(':') {
            let k = k.trim().to_ascii_lowercase();
            let v = v.trim();
            if k == "content-length" {
                content_length = v.parse().ok();
            } else if k == "transfer-encoding" && v.to_ascii_lowercase().contains("chunked") {
                chunked = true;
            }
        }
    }

    if chunked {
        // Walk the chunk framing: <hex-size>[;ext]\r\n<data>\r\n ... 0\r\n\r\n
        let mut pos = header_end + 4;
        loop {
            // chunk-size line
            let line_end = buf[pos..].windows(2).position(|w| w == b"\r\n")? + pos;
            let size_str = std::str::from_utf8(&buf[pos..line_end]).ok()?;
            let size = usize::from_str_radix(size_str.split(';').next()?.trim(), 16).ok()?;
            pos = line_end + 2;
            if size == 0 {
                // last chunk: consume the optional trailer section (CRLF-terminated lines)
                loop {
                    if buf[pos..].starts_with(b"\r\n") {
                        return Some(pos + 2);
                    }
                    let e = buf[pos..].windows(2).position(|w| w == b"\r\n")? + pos;
                    pos = e + 2; // skip trailer line
                }
            }
            pos += size + 2; // data + trailing CRLF
        }
    }

    // The end only counts once the buffer actually holds that many bytes;
    // otherwise the caller must keep reading.
    content_length
        .map(|cl| header_end + 4 + cl)
        .filter(|&end| end <= buf.len())
}

/// Parse the head of a raw HTTP/1.1 response into (status, headers, body
/// start offset). Returns `None` for non-HTTP garbage.
pub fn parse_h1_response_head(raw: &[u8]) -> Option<(u16, Vec<(String, String)>, usize)> {
    let header_end = raw.windows(4).position(|w| w == b"\r\n\r\n")?;
    let head = std::str::from_utf8(&raw[..header_end]).ok()?;
    let mut lines = head.split("\r\n");

    let status_line = lines.next()?;
    let code = status_line.split_whitespace().nth(1)?.parse::<u16>().ok()?;

    let mut headers = Vec::new();
    for line in lines {
        if let Some((k, v)) = line.split_once(':') {
            headers.push((k.trim().to_string(), v.trim().to_string()));
        }
    }
    Some((code, headers, header_end + 4))
}

/// Map an HTTP status code to a Prometheus-friendly label.
pub fn status_label(code: u16) -> &'static str {
    if (500..600).contains(&code) {
        "5xx"
    } else if (400..500).contains(&code) {
        "4xx"
    } else {
        "2xx_3xx"
    }
}

// ============================================================================
// Upstream dispatch (shared by the HTTP/1.1 and HTTP/2 frontends)
// ============================================================================

#[derive(Debug)]
pub struct UpstreamOutcome {
    pub status: u16,
    /// Full raw HTTP/1.1 response as returned by the upstream.
    pub response: Vec<u8>,
}

#[derive(Debug)]
pub enum DispatchError {
    NoUpstreams,
    AllFailed(String),
}

/// Accept backlog for shard listeners. Capped by the kernel's somaxconn
/// (raise it in containers via compose `sysctls` / `sysctl -w`).
const LISTEN_BACKLOG: i32 = 8191;

/// Bind one shard listener with a deep backlog. On Unix, SO_REUSEPORT lets
/// every shard bind the same address: the kernel then load-balances incoming
/// connections across shards (the nginx model, done in-kernel). On Windows,
/// SO_REUSEADDR is used, which also permits multiple binds in practice.
fn bind_shard_listener(addr: SocketAddr) -> std::net::TcpListener {
    let socket = socket2::Socket::new(
        socket2::Domain::for_address(addr),
        socket2::Type::STREAM,
        None,
    )
    .expect("socket creation failed");
    socket
        .set_reuse_address(true)
        .expect("set_reuse_address failed");
    #[cfg(not(windows))]
    socket
        .set_reuse_port(true)
        .expect("set_reuse_port failed (SO_REUSEPORT unsupported?)");
    socket
        .bind(&addr.into())
        .unwrap_or_else(|e| panic!("failed to bind {addr}: {e}"));
    socket.listen(LISTEN_BACKLOG).expect("listen failed");
    let std_listener: std::net::TcpListener = socket.into();
    std_listener
        .set_nonblocking(true)
        .expect("listener non-blocking failed");
    std_listener
}

/// Connect to an upstream with a timeout. Returns `Err` on timeout/refusal.
/// Resolved-address cache with a TTL. `TcpStream::connect("host:port")`
/// runs a threaded DNS lookup per call; under a connection storm that
/// floods the Docker embedded DNS server and stalls connects (seen as
/// `connect timeout` bursts + health flapping). nginx resolves once and
/// caches; we do the same: one in-flight resolve per name, result shared.
static DNS_CACHE: OnceLock<Mutex<HashMap<String, (Vec<SocketAddr>, Instant)>>> = OnceLock::new();
const DNS_TTL: Duration = Duration::from_secs(30);

async fn resolve_cached(host_port: &str) -> std::io::Result<Vec<SocketAddr>> {
    let cache = DNS_CACHE.get_or_init(|| Mutex::new(HashMap::new()));
    {
        let map = cache.lock().unwrap();
        if let Some((addrs, at)) = map.get(host_port) {
            if at.elapsed() < DNS_TTL {
                return Ok(addrs.clone());
            }
        }
    }
    // Outside the lock: resolve (single-flight per name is not enforced —
    // a rare duplicate resolve is harmless, correctness is unaffected).
    let addrs = tokio::time::timeout(UPSTREAM_CONNECT_TIMEOUT, tokio::net::lookup_host(host_port))
        .await
        .unwrap_or_else(|_| {
            Err(std::io::Error::new(
                std::io::ErrorKind::TimedOut,
                format!("dns timeout for {host_port}"),
            ))
        })?
        .collect::<Vec<_>>();
    if !addrs.is_empty() {
        cache
            .lock()
            .unwrap()
            .insert(host_port.to_string(), (addrs.clone(), Instant::now()));
    }
    Ok(addrs)
}

/// Concurrency cap on upstream TCP connects. Under a 10k-connection storm
/// every shard simultaneously dials the upstream; thousands of concurrent
/// SYN floods over a Docker bridge degrade into connect timeouts for
/// everyone. A small cap keeps connect latency flat; beyond it requests
/// queue in memory instead of the kernel's SYN queue.
static UPSTREAM_CONNECT_SEM: OnceLock<tokio::sync::Semaphore> = OnceLock::new();
fn upstream_connect_permits() -> usize {
    static CAP: OnceLock<usize> = OnceLock::new();
    *CAP.get_or_init(|| {
        std::env::var("JUGGLER_UPSTREAM_CONNECT_CONCURRENCY")
            .ok()
            .and_then(|v| v.parse().ok())
            .unwrap_or(256)
    })
}

async fn connect_upstream(addr: &str) -> std::io::Result<TcpStream> {
    // Resolve once per TTL: connect by IP so the per-call DNS lookup
    // (threaded resolver) never runs inside the storm.
    let addrs = resolve_cached(addr).await?;
    if addrs.is_empty() {
        return Err(std::io::Error::new(
            std::io::ErrorKind::NotFound,
            format!("dns resolved no addresses for {addr}"),
        ));
    }
    let _permit = UPSTREAM_CONNECT_SEM
        .get_or_init(|| tokio::sync::Semaphore::new(upstream_connect_permits()))
        .acquire()
        .await
        .map_err(|_| std::io::Error::new(std::io::ErrorKind::Other, "connect semaphore closed"))?;
    // Try resolved addresses in order; IP:port strings skip the cache.
    let mut last_err: Option<std::io::Error> = None;
    for a in &addrs {
        match tokio::time::timeout(UPSTREAM_CONNECT_TIMEOUT, TcpStream::connect(a)).await {
            Ok(Ok(conn)) => {
                // Disable Nagle: a proxy must never wait to coalesce the
                // request head with a late body, and 200ms delayed-ACK
                // stalls on loopback would eat the latency budget.
                let _ = conn.set_nodelay(true);
                return Ok(conn);
            }
            Ok(Err(e)) => last_err = Some(e),
            Err(_) => {
                last_err = Some(std::io::Error::new(
                    std::io::ErrorKind::TimedOut,
                    format!("connect timeout to {a}"),
                ));
            }
        }
    }
    Err(last_err
        .unwrap_or_else(|| std::io::Error::new(std::io::ErrorKind::Other, "no addresses to connect")))
}

// ----------------------------------------------------------------------------
// Upstream keep-alive pool (HTTP/1.1 connection reuse)
// ----------------------------------------------------------------------------

/// Upstream keep-alive pool. In HTTP/1.1 an idle pooled connection belongs to
/// exactly one request, so a plain `Mutex` is contention-free in practice:
/// pop/push happen once per request, never per byte.
struct UpstreamPool {
    conns: std::sync::Mutex<HashMap<String, Vec<(TcpStream, Instant)>>>,
}

impl UpstreamPool {
    fn new() -> Self {
        Self { conns: std::sync::Mutex::new(HashMap::new()) }
    }

    /// Number of idle sockets currently pooled for `addr`.
    fn pooled_len(&self, addr: &str) -> usize {
        self.conns
            .lock()
            .unwrap()
            .get(addr)
            .map(|v| v.len())
            .unwrap_or(0)
    }

    /// Borrow an idle connection. Returns `None` when the pool is empty.
    fn pop(&self, addr: &str) -> Option<TcpStream> {
        let mut pools = self.conns.lock().unwrap();
        let s = pools.get_mut(addr).and_then(|v| v.pop().map(|(s, _)| s))?;
        UPSTREAM_POOL_SIZE
            .with_label_values(&[addr])
            .dec();
        Some(s)
    }

    /// Return a healthy connection for reuse.
    fn push(&self, addr: &str, s: TcpStream) {
        let mut pools = self.conns.lock().unwrap();
        let pool = pools.entry(addr.to_string()).or_default();
        if pool.len() < upstream_pool_cap() {
            pool.push((s, Instant::now()));
            UPSTREAM_POOL_SIZE
                .with_label_values(&[addr])
                .inc();
        }
        // else: over capacity -> drop (closes the socket).
    }

    /// Drop idle sockets (hot-reload / cap enforcement / periodic pruning).
    fn close_all(&self, addr: Option<&str>) {
        let mut pools = self.conns.lock().unwrap();
        match addr {
            Some(a) => {
                pools.remove(a);
            }
            None => {
                pools.clear();
            }
        }
    }

    /// Discard idle sockets older than UPSTREAM_IDLE_TIMEOUT and refresh
    /// the upstream_pool_size gauges.
    fn prune_stale(&self) {
        let mut pools = self.conns.lock().unwrap();
        let idle_timeout = Duration::from_secs(settings().pool_idle_timeout_secs.get());
        for (addr, vec) in pools.iter_mut() {
            let before = vec.len();
            vec.retain(|(_, born)| born.elapsed() < idle_timeout);
            if vec.len() != before {
                UPSTREAM_POOL_SIZE
                    .with_label_values(&[addr.as_str()])
                    .set(vec.len() as i64);
            }
        }
        pools.retain(|_, v| !v.is_empty());
    }
}

/// Cap on idle sockets kept per upstream address. A live runtime knob:
/// the admin panel can change it per request; a large proxy concurrency
/// (10k in-flight) needs a proportionally large pool, otherwise excess
/// upstream connections close after every response and the churn
/// (TIME_WAIT + reconnects) becomes the bottleneck.
fn upstream_pool_cap() -> usize {
    settings().pool_cap_per_upstream.get() as usize
}

lazy_static! {
    static ref UPSTREAM_POOL: UpstreamPool = UpstreamPool::new();
}

/// Read one response from a keep-alive upstream and tell whether the
/// connection stays reusable (`true`) or must be discarded (`false`).
/// The TcpStream is left unread past the response end: the next request's
/// bytes (if the upstream pipelined a reply already) would otherwise be
/// swallowed, so reuse is only offered when the connection is quiescent.
async fn read_upstream_response_reusable(
    upstream: &mut TcpStream,
) -> anyhow::Result<(Vec<u8>, bool)> {
    let mut response = Vec::with_capacity(8192);
    let mut chunk = [0u8; 8192];
    loop {
        if let Some(end) = detect_response_end(&response) {
            if end > MAX_UPSTREAM_RESPONSE {
                anyhow::bail!("upstream response exceeds {} bytes", MAX_UPSTREAM_RESPONSE);
            }
            response.truncate(end);
            return Ok((response, true));
        }
        if response.len() > MAX_UPSTREAM_RESPONSE {
            anyhow::bail!("upstream response exceeds {} bytes", MAX_UPSTREAM_RESPONSE);
        }
        let n = tokio::time::timeout(UPSTREAM_READ_TIMEOUT, upstream.read(&mut chunk)).await??;
        if n == 0 {
            return Ok((response, false)); // EOF -> server closed: not reusable
        }
        response.extend_from_slice(&chunk[..n]);
    }
}

/// One upstream attempt over the keep-alive pool. `request_is_reusable`
/// tells whether the request body is fully framed (Content-Length present
/// or empty body), which is a precondition for connection reuse.
async fn try_upstream_attempt(
    addr: &str,
    raw_request: &[u8],
    request_is_reusable: bool,
) -> anyhow::Result<UpstreamOutcome> {
    // Fast path: reuse an idle pooled connection. On any error (stale
    // socket, upstream restart) the connection is dropped and a fresh
    // connection is made — a single silent retry, invisible to the caller.
    if request_is_reusable {
        if let Some(mut conn) = UPSTREAM_POOL.pop(addr) {
            match conn.write_all(raw_request).await {
                Ok(_) => match read_upstream_response_reusable(&mut conn).await {
                    // A healthy upstream always writes at least the head;
                    // zero bytes means the socket was closed before the
                    // request was processed (classic reuse race). Retry on
                    // a fresh connection — safe for any method, since the
                    // upstream provably never handled this request.
                    Ok((response, reusable)) if !response.is_empty() => {
                        UPSTREAM_POOL_REUSES_TOTAL.inc();
                        if reusable {
                            UPSTREAM_POOL.push(addr, conn);
                        }
                        return finish_outcome(response);
                    }
                    Ok(_) => {
                        ACTIVE_UPSTREAM_CONNECTIONS.dec();
                        UPSTREAM_STALE_RETRIES_TOTAL.inc();
                        eprintln!("⚠️ pooled conn to {addr} closed before responding, retrying fresh");
                        // fall through to the fresh-connection path
                    }
                    Err(e) => {
                        ACTIVE_UPSTREAM_CONNECTIONS.dec();
                        UPSTREAM_STALE_RETRIES_TOTAL.inc();
                        eprintln!("⚠️ pooled conn to {addr} broke, retrying fresh: {e:#}");
                        // fall through to the fresh-connection path
                    }
                },
                Err(e) => {
                    ACTIVE_UPSTREAM_CONNECTIONS.dec();
                    UPSTREAM_STALE_RETRIES_TOTAL.inc();
                    eprintln!("⚠️ pooled conn to {addr} write failed, retrying fresh: {e}");
                    // fall through to the fresh-connection path
                }
            }
        }
    }

    // Slow path: brand-new connection.
    let mut upstream = connect_upstream(addr).await.map_err(|e| anyhow::anyhow!(e))?;
    ACTIVE_UPSTREAM_CONNECTIONS.inc();
    upstream.write_all(raw_request).await?;
    upstream.flush().await?;
    let (response, reusable) = read_upstream_response_reusable(&mut upstream).await?;
    if reusable && request_is_reusable {
        UPSTREAM_POOL.push(addr, upstream);
    }
    finish_outcome(response)
}

/// Pre-warm the upstream pool: open `target` keep-alive connections to
/// every configured upstream before traffic arrives. This is what kills
/// the opening-burst error spike at 10k: the first requests reuse ready
/// sockets instead of racing to create them (DNS + SYN + connect-cap).
/// Called after startup and after every hot reload (new upstreams only).
async fn prewarm_upstream_pool(config: &Arc<ArcSwap<ServerConfig>>, target: usize) {
    // Adaptive prewarm toggle is the master switch: with it off (small dev
    // stands, integration tests, backends that cannot absorb a connect
    // burst) a hot-reload must not fire 256 connections per upstream —
    // that overflowed weak backends' accept queues and shed the very
    // sockets the pool was trying to warm.
    if !settings().adaptive_prewarm.load(Ordering::Relaxed) {
        return;
    }
    let mut addrs: Vec<String> = Vec::new();
    for route in config.load().routes.values() {
        addrs.extend(route.upstream_addrs.iter().cloned());
    }
    addrs.sort();
    addrs.dedup();
    let mut tasks = Vec::new();
    for addr in addrs {
        let have = UPSTREAM_POOL.pooled_len(&addr);
        for _ in have..target {
            let addr = addr.clone();
            tasks.push(tokio::spawn(async move {
                match connect_upstream(&addr).await {
                    Ok(stream) => UPSTREAM_POOL.push(&addr, stream),
                    Err(e) => eprintln!("⚠️ prewarm connect to {addr} failed: {e}"),
                }
            }));
        }
    }
    for t in tasks {
        let _ = t.await;
    }
}

/// Wrap a fully-read response into an `UpstreamOutcome` after validating
/// that it starts with a parseable HTTP/1.1 head.
fn finish_outcome(response: Vec<u8>) -> anyhow::Result<UpstreamOutcome> {
    if parse_h1_response_head(&response).is_none() {
        anyhow::bail!("upstream sent a non-HTTP response");
    }
    let status = parse_h1_response_head(&response)
        .map(|(code, _, _)| code)
        .unwrap_or(502);
    Ok(UpstreamOutcome { status, response })
}

/// Send a fully-encoded HTTP/1.1 request to the pool of upstreams with
/// Least-Connections ordering and failover. Protocol-neutral: the caller
/// receives the raw upstream response and decides how to forward it to the
/// client (HTTP/1.1 bytes or HTTP/2 frames).
async fn dispatch_to_upstreams(
    raw_request: &[u8],
    upstreams: &[String],
    sig: u64,
) -> (Result<UpstreamOutcome, DispatchError>, Option<String>) {
    // Health gate: unhealthy upstreams are skipped (until their recovery
    // cooldown turns them half-open). Healthy ordering below is still
    // least-connections first, then the rest for failover.
    let mut healthy: Vec<String> =
        upstreams.iter().filter(|a| HEALTH.accepts(a)).cloned().collect();
    if healthy.is_empty() {
        // All upstreams are marked unhealthy (e.g. an outage burst). Rather
        // than hard-failing while every circuit is open, let the request
        // through to the least-loaded one anyway: the half-open probe
        // mechanism will recover healthy peers naturally.
        healthy = upstreams.to_vec();
    }

    let mut ordered: Vec<String> = Vec::with_capacity(healthy.len());
    // Signature oracle: predicted COST of this request shapes the pick.
    // In predictive mode the per-upstream score is the live counter PLUS
    // the predicted cost share: slow signatures (big uploads, heavy APIs)
    // gravitate to the upstream whose queue will actually drain soonest,
    // instead of tying with 3ms probes. With a single upstream candidate
    // the cost table is still recorded (learning signal) but adds no
    // scoring overhead. leastconn mode keeps the cheap classic pick and
    // only weights ties (skipped here for simplicity: learning happens
    // regardless).
    let predicted_cost_ns = sig_predict(sig);
    let picked = if settings().balancer.get() == BALANCER_PREDICTIVE && healthy.len() > 1 {
        BALANCER.select_upstream_predictive_weighted(&healthy, predicted_cost_ns)
    } else {
        BALANCER.select_upstream_predictive(&healthy)
            .or_else(|| BALANCER.select_upstream(&healthy))
            .or_else(|| {
                healthy.first().cloned().map(|a| {
                    let c = BALANCER.connections.load().get(&a).cloned().unwrap_or_default();
                    (a, c)
                })
            })
    };
    if let Some((best, _counter)) = picked {
        ordered.push(best);
        for a in &healthy {
            if !ordered.contains(a) {
                ordered.push((*a).clone());
            }
        }
    } else {
        return (Err(DispatchError::NoUpstreams), None);
    }

    // A half-open upstream with a granted probe slot goes first: the probe
    // request must deterministically reach the circuit under test, not
    // disappear into a random least-connections tie-break. (accepts() only
    // granted the slot to one request — this ordering makes it stick.)
    if let Some(pos) = ordered.iter().position(|a| HEALTH.is_probe_target(a)) {
        let probe_addr = ordered.remove(pos);
        ordered.insert(0, probe_addr);
    }

    let mut last_err: Option<String> = None;

    // Connection reuse precondition: the request must be fully framed.
    // RFC 7230 §3.3.2: a request without Content-Length and without
    // Transfer-Encoding carries no body, so absence of CL on a bodyless
    // GET is safe for reuse; a CL header must simply parse.
    let head = &raw_request[..raw_request
        .windows(4)
        .position(|w| w == b"\r\n\r\n")
        .unwrap_or(raw_request.len())];
    let request_is_reusable = raw_request.is_empty() || body_has_content_length(head);

    for (attempt, addr) in ordered.iter().enumerate() {
        // Track in-flight requests per upstream for the whole attempt
        // (connect + forward), so Least Connections sees live data.
        let counter = BALANCER.connections.load().get(addr).cloned();
        if let Some(c) = &counter {
            c.fetch_add(1, Ordering::SeqCst);
        }

        let attempt_t0 = Instant::now();
        let attempt_result = try_upstream_attempt(addr, raw_request, request_is_reusable).await;
        // Feedback loop: feed the observed cost into the signature's EMA
        // (both success and failure — a failing upstream is also "slow").
        sig_record(sig, attempt_t0.elapsed().as_nanos() as u64);

        if let Some(c) = &counter {
            c.fetch_sub(1, Ordering::SeqCst);
        }

        match attempt_result {
            Ok(outcome) => {
                // finish_outcome already validated the HTTP head; garbage
                // without one came back as an Err (failover applies).
                UPSTREAM_REQUESTS_TOTAL
                    .with_label_values(&[addr, "ok"])
                    .inc();
                HEALTH.record(addr, true);
                return (Ok(outcome), Some(addr.clone()));
            }
            Err(e) => {
                eprintln!("⚠️ upstream {addr} failed: {e:#}");
                UPSTREAM_REQUESTS_TOTAL
                    .with_label_values(&[addr, "error"])
                    .inc();
                HEALTH.record(addr, false);
                last_err = Some(format!("{addr}: {e}"));
            }
        }

        if attempt + 1 < ordered.len() {
            UPSTREAM_FAILOVER_TOTAL.inc();
        }
    }

    (
        Err(DispatchError::AllFailed(last_err.unwrap_or_default())),
        None,
    )
}

// ============================================================================
// Request routing (shared by both frontends)
// ============================================================================

#[derive(Debug)]
enum RouteDecision {
    RateLimited,
    Builtin(u16, String),
    NotFound,
    /// Upstream pool, WASM-rewritten body, headers injected by plugins,
    /// security response headers from the route policy.
    Proxy(Vec<String>, Vec<u8>, Vec<(String, String)>, Vec<(String, String)>),
    /// File served from a route's `static_dir`. `head` is the raw response
    /// head (status line + headers, CRLF-terminated); `body` is the file
    /// content. Built by `serve_static_file`.
    StaticFile(Vec<u8>, Vec<u8>),
}

/// Resolve a request path to an action, handling rate limiting and the
/// built-in endpoints. Only the "Proxy" variant needs the caller to do more
/// work; every other decision already counted its metric.
fn resolve_route(
    path: &str,
    body: &mut Vec<u8>,
    peer_ip: IpAddr,
    proto: &'static str,
    config: &Arc<ArcSwap<ServerConfig>>,
) -> RouteDecision {
    // Rate limiting comes first: even built-in endpoints consume real work
    // (TLS, header parsing), and /metrics scraping at normal rates (5s
    // interval) never approaches the 5 req/s refill.
    if !RATE_LIMITER.check_limit(peer_ip) {
        RATE_LIMIT_REJECTED
            .with_label_values(&["token_bucket"])
            .inc();
        return RouteDecision::RateLimited;
    }

    if path == "/healthz" {
        HTTP_REQUESTS_TOTAL
            .with_label_values(&["/healthz", "200", proto])
            .inc();
        return RouteDecision::Builtin(200, "ok".to_string());
    }
    if path == "/metrics" {
        HTTP_REQUESTS_TOTAL
            .with_label_values(&["/metrics", "200", proto])
            .inc();
        let encoder = TextEncoder::new();
        let mut buf = Vec::new();
        encoder.encode(&REGISTRY.gather(), &mut buf).unwrap();
        return RouteDecision::Builtin(200, String::from_utf8(buf).unwrap());
    }
    if path == "/" {
        // Landing page: makes the server self-describing in a browser.
        HTTP_REQUESTS_TOTAL
            .with_label_values(&["/", "200", proto])
            .inc();
        return RouteDecision::Builtin(200, LANDING_PAGE.to_string());
    }

    let active = config.load();

    // Security gate: a route's deny patterns shadow more-specific matching
    // (a request for /wp-admin/../../wp-config.php is normalized before this
    // point by the parser). Matching is case-insensitive substring.
    let path_lower = path.to_ascii_lowercase();
    for route in active.routes.values() {
        for denied in &route.security.deny_substrings {
            let d = denied.to_ascii_lowercase();
            if path_lower.contains(&d) {
                HTTP_REQUESTS_TOTAL
                    .with_label_values(&[path, "403", proto])
                    .inc();
                return RouteDecision::Builtin(403, "forbidden".to_string());
            }
        }
    }

    // Static routes: longest-prefix match. A request for /site1/img/a.png
    // is served from the static_dir of route /site1 with the remainder as
    // the file path. Exact proxy routes always win over the prefix.
    let mut static_candidates: Vec<(&String, &RouteConfig)> = active
        .routes
        .iter()
        .filter(|(_, r)| r.static_dir.is_some())
        .collect();
    static_candidates.sort_by(|a, b| b.0.len().cmp(&a.0.len()));
    for (prefix, route) in static_candidates {
        let is_exact = path == prefix.as_str();
        let is_child = path.len() > prefix.len()
            && path.starts_with(prefix.as_str())
            && path.as_bytes()[prefix.len()] == b'/';
        if is_exact || is_child {
            if let Some(root) = &route.static_dir {
                let suffix = if is_exact { "/" } else { &path[prefix.len()..] };
                let decision = serve_static_file(root, suffix, true);
                let status = match &decision {
                    RouteDecision::StaticFile(head, _) => head
                        .split(|&b| b == b' ')
                        .nth(1)
                        .and_then(|s| std::str::from_utf8(s).ok())
                        .and_then(|s| s.parse::<u16>().ok())
                        .unwrap_or(200),
                    RouteDecision::NotFound => 404,
                    _ => 200,
                };
                HTTP_REQUESTS_TOTAL
                    .with_label_values(&[prefix.as_str(), &status.to_string(), proto])
                    .inc();
                return decision;
            }
        }
    }

    match active.routes.get(path) {
        Some(route) if !route.upstream_addrs.is_empty() => {
            // Per-request WASM filter pipeline (fail-closed on a trap).
            let plugin = plugin_for_route(&route.wasm_plugin);
            let plugin_headers = match run_plugin_filters(&plugin, path, body) {
                Ok(h) => h,
                Err(e) => {
                    eprintln!("⚠️ wasm filter pipeline failed for {path}: {e}");
                    HTTP_REQUESTS_TOTAL
                        .with_label_values(&[path, "502", proto])
                        .inc();
                    return RouteDecision::Builtin(502, "wasm filter failed".to_string());
                }
            };
            RouteDecision::Proxy(
                route.upstream_addrs.clone(),
                std::mem::take(body),
                plugin_headers,
                route.security.response_headers.clone(),
            )
        }
        _ => {
            // CMS front-controller fallback: if any route declares a
            // cms_fallback_path, unknown paths rewrite there (WordPress's
            // `RewriteRule ^(.*)$ index.php [L]` equivalent).
            for route in active.routes.values() {
                if let Some(fallback) = &route.security.cms_fallback_path {
                    if let Some(target) = active.routes.get(fallback) {
                        if !target.upstream_addrs.is_empty() {
                            // The original URI is forwarded unchanged: the CMS
                            // router (WordPress, Laravel...) resolves it from
                            // REQUEST_URI, exactly as under Apache mod_rewrite.
                            HTTP_REQUESTS_TOTAL
                                .with_label_values(&[fallback, "200", proto])
                                .inc();
                            return RouteDecision::Proxy(
                                target.upstream_addrs.clone(),
                                std::mem::take(body),
                                Vec::new(),
                                target.security.response_headers.clone(),
                            );
                        }
                    }
                }
            }
            HTTP_REQUESTS_TOTAL
                .with_label_values(&[path, "404", proto])
                .inc();
            RouteDecision::NotFound
        }
    }
}

// ============================================================================
// Static file serving (nginx `root` equivalent)
// ============================================================================

/// Largest file served from a `static_dir`. Larger files get 413 — static
/// sites are made of assets, not ISOs; streaming large bodies through the
/// single-buffer response path would starve the shard.
const MAX_STATIC_FILE: u64 = 32 * 1024 * 1024;

/// Extension -> Content-Type. Intentionally table-driven, no dependency:
/// covers everything a static site / CMS frontend ships. Unknown extensions
/// default to `application/octet-stream` (browsers download, never sniff).
fn mime_type_of(path: &str) -> &'static str {
    let ext = path.rsplit('.').next().unwrap_or("").to_ascii_lowercase();
    match ext.as_str() {
        "html" | "htm" => "text/html; charset=utf-8",
        "css" => "text/css; charset=utf-8",
        "js" | "mjs" => "text/javascript; charset=utf-8",
        "json" => "application/json",
        "xml" => "application/xml",
        "txt" | "log" => "text/plain; charset=utf-8",
        "md" => "text/markdown; charset=utf-8",
        "png" => "image/png",
        "jpg" | "jpeg" => "image/jpeg",
        "gif" => "image/gif",
        "svg" => "image/svg+xml",
        "webp" => "image/webp",
        "avif" => "image/avif",
        "ico" => "image/x-icon",
        "bmp" => "image/bmp",
        "woff" => "font/woff",
        "woff2" => "font/woff2",
        "ttf" => "font/ttf",
        "otf" => "font/otf",
        "eot" => "application/vnd.ms-fontobject",
        "mp3" => "audio/mpeg",
        "wav" => "audio/wav",
        "ogg" => "audio/ogg",
        "mp4" => "video/mp4",
        "webm" => "video/webm",
        "pdf" => "application/pdf",
        "zip" => "application/zip",
        "gz" => "application/gzip",
        "wasm" => "application/wasm",
        _ => "application/octet-stream",
    }
}

/// Map a request path suffix (relative to the route prefix) onto a file
/// inside `root`. Returns `None` when the suffix tries to escape (traversal,
/// absolute segments, embedded NUL). A trailing `/` or empty suffix means
/// "directory" and maps to `index.html`.
fn static_path_for(root: &str, suffix: &str) -> Option<String> {
    let suffix = suffix.trim_start_matches('/');
    let rel = if suffix.is_empty() || suffix.ends_with('/') {
        "index.html"
    } else {
        suffix
    };
    if rel.is_empty()
        || rel.contains('\0')
        || rel.split('/').any(|seg| seg == ".." || seg.is_empty())
    {
        return None;
    }
    Some(format!("{}/{}", root.trim_end_matches('/'), rel))
}

/// Build a raw HTTP/1.1 head for a static response. H2 requests get the head
/// stripped by the caller; H1 ships it as-is.
fn static_http_head(status_line: &str, content_type: &str, len: usize, keep_alive: bool) -> Vec<u8> {
    format!(
        "HTTP/1.1 {}\r\nContent-Type: {}\r\nContent-Length: {}\r\nConnection: {}\r\n\r\n",
        status_line,
        content_type,
        len,
        if keep_alive { "keep-alive" } else { "close" }
    )
    .into_bytes()
}

/// Serve one file from a `static_dir`. Ordering matters for security:
/// 1. resolve the logical path and reject traversal syntactically;
/// 2. canonicalize — the OS resolves symlinks, so a link pointing outside
///    the root also fails the prefix check (nginx open-at()/chroot levels);
/// 3. prefix-check the canonical path against the canonical root.
fn serve_static_file(
    root: &str,
    suffix: &str,
    keep_alive: bool,
) -> RouteDecision {
    let Some(path) = static_path_for(root, suffix) else {
        return RouteDecision::StaticFile(
            static_http_head("403 Forbidden", "text/plain; charset=utf-8", 9, keep_alive),
            b"forbidden".to_vec(),
        );
    };
    let canon_root = std::fs::canonicalize(root).ok();
    match std::fs::read(&path) {
        Ok(body) => {
            // Containment re-check on the real path (symlink escape). If the
            // root itself cannot be canonicalized (deleted mid-flight), deny.
            let contained = canon_root.as_ref().is_some_and(|r| {
                std::fs::canonicalize(&path)
                    .map(|p| p.starts_with(r))
                    .unwrap_or(false)
            });
            if !contained {
                return RouteDecision::StaticFile(
                    static_http_head("403 Forbidden", "text/plain; charset=utf-8", 9, keep_alive),
                    b"forbidden".to_vec(),
                );
            }
            if body.len() as u64 > MAX_STATIC_FILE {
                return RouteDecision::StaticFile(
                    static_http_head(
                        "413 Payload Too Large",
                        "text/plain; charset=utf-8",
                        16,
                        keep_alive,
                    ),
                    b"payload too large".to_vec(),
                );
            }
            let ct = mime_type_of(&path);
            RouteDecision::StaticFile(
                static_http_head("200 OK", ct, body.len(), keep_alive),
                body,
            )
        }
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => RouteDecision::NotFound,
        Err(e) if e.kind() == std::io::ErrorKind::PermissionDenied => {
            RouteDecision::StaticFile(
                static_http_head("403 Forbidden", "text/plain; charset=utf-8", 9, keep_alive),
                b"forbidden".to_vec(),
            )
        }
        // A directory without a trailing slash: retry as its index.html.
        Err(e) if e.kind() == std::io::ErrorKind::IsADirectory => {
            let idx = format!("{}/index.html", path.trim_end_matches('/'));
            match std::fs::read(&idx) {
                Ok(body) => {
                    let ct = mime_type_of(&idx);
                    RouteDecision::StaticFile(
                        static_http_head("200 OK", ct, body.len(), keep_alive),
                        body,
                    )
                }
                Err(_) => RouteDecision::NotFound,
            }
        }
        Err(_) => RouteDecision::StaticFile(
            static_http_head("500 Internal Server Error", "text/plain; charset=utf-8", 15, keep_alive),
            b"internal error".to_vec(),
        ),
    }
}

/// Default document root: `www/` next to the binary's working directory,
/// with one sample site per `sample_sitesN` directory so a fresh install
/// has something to serve immediately (nginx/htdocs convention).
fn default_static_root() -> String {
    env_or("JUGGLER_WWW_DIR", "www")
}

/// Idempotently create the document root with `sample_sitesN` folders, each
/// containing an index.html. Returns how many sample dirs were created.
fn ensure_www_layout(root: &str) -> usize {
    let mut created = 0usize;
    if std::fs::create_dir_all(root).is_err() {
        eprintln!("⚠️ cannot create document root {root}");
        return created;
    }
    for n in 1..=3 {
        let dir = format!("{root}/sample_sites{n}");
        let idx = format!("{dir}/index.html");
        if std::fs::create_dir_all(&dir).is_ok() && !std::path::Path::new(&idx).exists() {
            let page = format!(
                "<!DOCTYPE html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><title>Juggler sample site {n}</title>\
                 <style>body{{font-family:system-ui;background:#0f1117;color:#e6e9f0;display:grid;place-items:center;height:100vh;margin:0}}\
                 .c{{text-align:center}}h1{{color:#4ade80}}</style></head>\
                 <body><div class=\"c\"><h1>🤹 Sample site {n}</h1>\
                 <p>Served by Juggler from <code>{dir}</code>.</p>\
                 <p>Add files here and they are served instantly — no restart needed.</p></div></body></html>"
            );
            if std::fs::write(&idx, page).is_ok() {
                created += 1;
            }
        }
    }
    created
}

/// Auto-register `/<dir>` static routes for every subdirectory of `root`.
/// Called at startup and after every Control Plane update, so new site
/// folders are picked up without a restart. Returns the merged route map.
fn merge_static_routes(root: &str, mut routes: HashMap<String, RouteConfig>) -> HashMap<String, RouteConfig> {
    let Ok(entries) = std::fs::read_dir(root) else {
        return routes;
    };
    for entry in entries.flatten() {
        if !entry.path().is_dir() {
            continue;
        }
        let name = entry.file_name().to_string_lossy().into_owned();
        if name.starts_with('.') {
            continue;
        }
        let path = format!("/{name}");
        let cfg = routes.entry(path).or_default();
        cfg.static_dir = Some(entry.path().to_string_lossy().into_owned());
    }
    routes
}

// ============================================================================
// main: thread-per-core runtime, Control Plane, Data Plane accept loop
// ============================================================================

fn env_or(name: &str, default: &str) -> String {
    std::env::var(name).unwrap_or_else(|_| default.to_string())
}

fn main() -> Result<()> {
    register_metrics();
    // Restore admin-tuned runtime settings before anything reads a knob.
    load_settings_file();

    let http_addr: SocketAddr = env_or("JUGGLER_HTTP_ADDR", "127.0.0.1:8443").parse()?;
    let grpc_addr: SocketAddr = env_or("JUGGLER_GRPC_ADDR", "127.0.0.1:50051").parse()?;
    let routes_file = env_or("JUGGLER_ROUTES_FILE", "routes.json");

    // Document root: create www/ with sample_sites1..3 on first run (nginx
    // htdocs convention) and auto-register a static route per subdirectory.
    let www_root = default_static_root();
    let samples = ensure_www_layout(&www_root);
    if samples > 0 {
        println!("📂 Document root {www_root}/ ready ({samples} sample site(s) created)");
    }

    // Upstream keep-alive pool: start fresh (never inherit sockets from a
    // previous incarnation) and prune stale idle sockets periodically.
    UPSTREAM_POOL.close_all(None);
    let shared_config = Arc::new(ArcSwap::from_pointee({
        let loaded = load_persisted_config(&routes_file).unwrap_or_default();
        ServerConfig { routes: merge_static_routes(&www_root, loaded.routes) }
    }));
    {
        let prune_cfg = shared_config.clone();
        std::thread::Builder::new()
            .name("upstream-pool-prune".into())
            .spawn(move || {
                let rt = tokio::runtime::Builder::new_current_thread()
                    .enable_all()
                    .build()
                    .expect("upstream pool prune runtime");
                rt.block_on(async {
                    loop {
                        tokio::time::sleep(UPSTREAM_POOL_PRUNE_INTERVAL).await;
                        UPSTREAM_POOL.prune_stale();
                        adaptive_prewarm_tick(&prune_cfg);
                    }
                });
            })
            .expect("failed to spawn upstream pool pruner");
    }

    let core_ids: Vec<CoreId> = core_affinity::get_core_ids()
        .ok_or_else(|| anyhow::anyhow!("failed to detect CPU cores for thread affinity"))?;
    let num_cores = core_ids.len();
    let mode = accept_mode();
    match mode {
        AcceptMode::KernelReusePort => {
            println!("🤹 Juggler: {num_cores} shard(s), thread-per-core, SO_REUSEPORT accept, Pythia forecast core v2 (HTTP/1.1 + HTTP/2)");
        }
        AcceptMode::HandOff => {
            println!("🤹 Juggler: {num_cores} shard(s), thread-per-core, Pythia forecast hand-off accept (no SO_REUSEPORT on this platform)");
        }
    }

    // Restore routes from the same config the pruner shares (loaded above).
    let n_routes = shared_config.load().routes.len();
    if n_routes > 0 {
        println!("📥 Restored {n_routes} route(s) from previous run");
        BALANCER.update_registry(&shared_config.load().routes);
        load_plugin_modules(&shared_config.load().routes);
        WASM_MODULES_LOADED.set(wasm_registry_lock().len() as i64);
    }

    // Pre-warm upstream pools in the background: by the time the first
    // client arrives, keep-alive sockets to every configured upstream are
    // already open (kills the opening-burst timeout spike at 10k).
    {
        let warm_cfg = shared_config.clone();
        std::thread::Builder::new()
            .name("pool-prewarm".into())
            .spawn(move || {
                if !settings().adaptive_prewarm.load(Ordering::Relaxed) {
                    println!("🔥 Adaptive prewarm disabled — skipping initial pool warm-up");
                    return;
                }
                let rt = tokio::runtime::Builder::new_current_thread()
                    .enable_all()
                    .build()
                    .expect("prewarm runtime");
                rt.block_on(async {
                    prewarm_upstream_pool(&warm_cfg, PREWARM_CONNECTIONS).await;
                    println!("🔥 Upstream pools pre-warmed");
                });
            })
            .expect("failed to spawn pool prewarmer");
    }

    let tls_config = Arc::new(create_tls_config());
    let acceptor = TlsAcceptor::from(tls_config);

    // Admin web UI + JSON API (JUGGLER_ADMIN_ADDR, default 127.0.0.1:9090):
    // dashboard with per-shard telemetry, route table, and hot-reload POST.
    spawn_admin_server(shared_config.clone(), routes_file.clone());

    // One OS thread per core, each with its own single-threaded Tokio runtime
    // and its own SO_REUSEPORT listener: the kernel load-balances connections
    // across shards (nginx model). A connection is accepted, parsed, routed
    // and served entirely on one core — no cross-core migration, no
    // work-stealing stalls under connection storms. The gRPC Control Plane
    // runs on shard 0 (a dedicated 2-thread runtime beside it for robustness).
    let mut join_handles = Vec::new();
    NUM_SHARDS.store(num_cores, Ordering::Relaxed);
    init_buf_pools(num_cores);
    init_shard_counters(num_cores);
    // Hand-off mode: central acceptors must exist before shards drain, and
    // the forecast core is sized from NUM_SHARDS on first touch.
    if mode == AcceptMode::HandOff {
        init_handoff_mailboxes(num_cores);
        spawn_acceptor("juggler-accept-tls", http_addr, HandoffKind::Tls, num_cores);
        if let Ok(plain) = std::env::var("JUGGLER_PLAIN_HTTP_ADDR") {
            let plain_addr: SocketAddr = plain
                .parse()
                .unwrap_or_else(|_| panic!("bad JUGGLER_PLAIN_HTTP_ADDR"));
            spawn_acceptor("juggler-accept-plain", plain_addr, HandoffKind::Plain, num_cores);
            println!("🌐 Data Plane (plain HTTP) listening on http://{plain_addr} (hand-off)");
        }
        if let Ok(redir) = std::env::var("JUGGLER_REDIRECT_HTTP") {
            let redir_addr: SocketAddr = redir
                .parse()
                .unwrap_or_else(|_| panic!("bad JUGGLER_REDIRECT_HTTP"));
            spawn_acceptor("juggler-accept-redir", redir_addr, HandoffKind::Redir, num_cores);
            println!("↪️  HTTP→HTTPS redirect listening on http://{redir_addr} → https://{http_addr}");
        }
        println!("🔒 Data Plane (HTTPS) listening on https://{http_addr} (forecast hand-off accept)");
    }
    for (i, core) in core_ids.iter().enumerate() {
        let core_id = *core;
        let shard_idx = i as u32;
        let acceptor = acceptor.clone();
        let cfg = shared_config.clone();
        let http_addr = http_addr;
        let grpc_addr = grpc_addr;
        let routes_file = routes_file.clone();
        let plain_addr = std::env::var("JUGGLER_PLAIN_HTTP_ADDR").ok();
        let redirect_addr = std::env::var("JUGGLER_REDIRECT_HTTP").ok();
        let handle = std::thread::Builder::new()
            .name(format!("juggler-shard-{i}"))
            .spawn(move || {
                core_affinity::set_for_current(core_id);
                run_shard(
                    shard_idx,
                    num_cores as u32,
                    http_addr,
                    plain_addr,
                    redirect_addr,
                    grpc_addr,
                    routes_file,
                    acceptor,
                    cfg,
                );
            })
            .expect("failed to spawn shard thread");
        join_handles.push(handle);
    }
    // ---- Graceful shutdown on Ctrl-C ----
    // Shards run forever; a signal terminates the process cleanly.
    let rt = tokio::runtime::Builder::new_current_thread()
        .enable_all()
        .build()
        .expect("main runtime failed");
    rt.block_on(async {
        // Shard telemetry sampler: folds raw per-shard counters into the
        // Holt (level, trend) state, refreshes Prometheus gauges and asks
        // mimalloc to return freed pages to the OS.
        tokio::spawn(async move {
            let mut interval = tokio::time::interval(SHARD_SAMPLE_INTERVAL);
            interval.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Skip);
            loop {
                interval.tick().await;
                let samples: Vec<u64> = shard_counters()
                    .iter()
                    .map(|c| c.active_conns.load(Ordering::Relaxed) as u64)
                    .collect();
                update_shard_stats(&samples);
                let snap = shard_stats_snapshot();
                push_history_snapshot(&snap);
                for (i, s) in snap.iter().enumerate() {
                    SHARD_ACTIVE_CONNS
                        .with_label_values(&[&i.to_string()])
                        .set(s.active_conns as i64);
                    SHARD_FORECAST_CONNS
                        .with_label_values(&[&i.to_string()])
                        .set(s.forecast.round() as i64);
                }
                for addr in BALANCER.connections.load().keys() {
                    let sample = BALANCER
                        .connections
                        .load()
                        .get(addr)
                        .map(|c| c.load(Ordering::Relaxed))
                        .unwrap_or(0);
                    BALANCER.update_forecast(addr, sample);
                    let (level, trend) = BALANCER.forecast_of(addr);
                    UPSTREAM_FORECAST_LOAD
                        .with_label_values(&[addr.as_str()])
                        .set((level + trend).round() as i64);
                }
                #[cfg(target_family = "unix")]
                unsafe {
                    // Encourage the allocator to return freed pages to the OS
                    // after load spikes (keep-RSS-low policy).
                    mi_collect();
                }
            }
        });
        if tokio::signal::ctrl_c().await.is_ok() {
            println!("🛑 Ctrl-C received: shutting down");
            std::process::exit(0);
        }
    });

    for h in join_handles {
        let _ = h.join();
    }

    Ok(())
}

/// Body of one accept shard: a current-thread runtime pinned to its core.
/// Shard 0 additionally serves the plain-HTTP listener (bench convenience)
/// and the gRPC Control Plane, so control traffic never lands on data-plane
/// cores.
/// RAII: decrements the shard's active-connection counter when the
/// connection task finishes (success, error or cancel — Drop runs either
/// way). The connection lives entirely on its shard's thread, so a plain
/// usize index is sound here.
struct ShardConnGuard(usize);
impl Drop for ShardConnGuard {
    fn drop(&mut self) {
        if let Some(c) = shard_counters().get(self.0) {
            c.active_conns.fetch_sub(1, Ordering::Relaxed);
        }
    }
}

/// How incoming connections reach the shards.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
enum AcceptMode {
    /// One SO_REUSEPORT listener per shard: the kernel hashes the 4-tuple
    /// across listeners and connections are spread in-kernel (nginx's
    /// model, zero user-space coordination). Linux/BSD only.
    KernelReusePort,
    /// Single listener + user-space hand-off: the acceptor thread pushes
    /// each connection into the chosen shard's lock-free queue. Windows has
    /// no SO_REUSEPORT (SO_REUSEADDR there lets every shard bind but the
    /// kernel hands every accept to ONE lucky listener), so without this
    /// all traffic lands on a single core on Windows dev machines.
    HandOff,
}

fn accept_mode() -> AcceptMode {
    if cfg!(windows) {
        AcceptMode::HandOff
    } else {
        AcceptMode::KernelReusePort
    }
}

/// Per-shard hand-off mailbox: a lock-free MPMC queue of already-accepted
/// sockets (with the peer IP — rate limiting is per-client-IP and must
/// survive the hand-off) plus an approximate depth counter (cheap
/// scheduling signal; exactness is irrelevant — it only biases the
/// choice). The dispatcher never blocks on push.
struct ShardMailbox {
    q: crossbeam_queue::SegQueue<(TcpStream, IpAddr)>,
    depth: AtomicUsize,
    /// Park/unpark for the shard's drain task. SegQueue::pop() is
    /// NON-blocking (returns None immediately) — without this the drain
    /// loop would busy-spin at 100% CPU per shard thread, starving the
    /// very clients and backends it serves. notify_one() stores a permit
    /// when no one is waiting, so a push racing with park never stalls.
    notify: tokio::sync::Notify,
}

impl ShardMailbox {
    fn new() -> Self {
        Self {
            q: crossbeam_queue::SegQueue::new(),
            depth: AtomicUsize::new(0),
            notify: tokio::sync::Notify::new(),
        }
    }

    fn push(&self, s: TcpStream, ip: IpAddr) {
        self.q.push((s, ip));
        self.depth.fetch_add(1, Ordering::Relaxed);
        // Wake the drain task (permit semantics make a push that races
        // with the park-to-wait window lossless).
        self.notify.notify_one();
        // Backpressure accounting: total queued connections across all
        // mailboxes drive the global accept pause (kernel backlog absorbs
        // the burst instead of unbounded userspace memory).
        update_backpressure_state(PENDING_CONNS.fetch_add(1, Ordering::AcqRel) + 1);
    }

    /// Pop one queued connection (FIFO); None when idle.
    fn pop(&self) -> Option<(TcpStream, IpAddr)> {
        let item = self.q.pop()?;
        self.depth.fetch_sub(1, Ordering::Relaxed);
        update_backpressure_state(PENDING_CONNS.fetch_sub(1, Ordering::AcqRel) - 1);
        Some(item)
    }

    fn len_approx(&self) -> usize {
        self.depth.load(Ordering::Relaxed)
    }

    /// Park until a push signals the notifier (permit semantics: if the
    /// notify fired while we were between pop attempts, the future below
    /// resolves immediately). Timed: a spurious-wakeup safety net so a
    /// lost permit can never wedge a shard forever.
    async fn wait_for_item(&self) {
        let _ = tokio::time::timeout(std::time::Duration::from_millis(50), self.notify.notified())
            .await;
    }
}

/// Mailboxes for every (shard, listener kind). Kinds are independent
/// channels so a plain-HTTP storm cannot delay TLS accepts.
static HANDOFF_TLS: OnceLock<Vec<ShardMailbox>> = OnceLock::new();
static HANDOFF_PLAIN: OnceLock<Vec<ShardMailbox>> = OnceLock::new();
static HANDOFF_REDIR: OnceLock<Vec<ShardMailbox>> = OnceLock::new();

fn init_handoff_mailboxes(shards: usize) {
    let _ = HANDOFF_TLS.set((0..shards.max(1)).map(|_| ShardMailbox::new()).collect());
    let _ = HANDOFF_PLAIN.set((0..shards.max(1)).map(|_| ShardMailbox::new()).collect());
    let _ = HANDOFF_REDIR.set((0..shards.max(1)).map(|_| ShardMailbox::new()).collect());
}

fn handoff_tls() -> &'static [ShardMailbox] {
    HANDOFF_TLS.get().map(|v| v.as_slice()).unwrap_or(&[])
}

fn handoff_plain() -> &'static [ShardMailbox] {
    HANDOFF_PLAIN.get().map(|v| v.as_slice()).unwrap_or(&[])
}

fn handoff_redir() -> &'static [ShardMailbox] {
    HANDOFF_REDIR.get().map(|v| v.as_slice()).unwrap_or(&[])
}

/// Pick the least-loaded shard for a new connection: forecast-first (the
/// v2 core's one-step-ahead prediction sees a ramp before raw counters
/// do), then queue depth, then live active conns; strictly better than
/// pure round-robin under bursty keep-alive traffic (one client opening
/// 50 connections would otherwise pin one shard for their lifetime).
fn pick_shard(n: usize, kind: HandoffKind) -> usize {
    if n == 0 {
        return 0;
    }
    let fc = shard_fc();
    let (depth, active) = match kind {
        HandoffKind::Tls => (handoff_tls(), shard_counters()),
        HandoffKind::Plain => (handoff_plain(), shard_counters()),
        HandoffKind::Redir => (handoff_redir(), shard_counters()),
    };
    let mut best = 0usize;
    let mut best_cost = f64::INFINITY;
    for i in 0..n {
        let fcst = fc.cell(i).map(|c| c.forecast()).unwrap_or(0.0);
        let qd = depth.get(i).map(|m| m.len_approx()).unwrap_or(0) as f64;
        let act = active.get(i).map(|c| c.active_conns.load(Ordering::Relaxed)).unwrap_or(0) as f64;
        let cost = fcst * 2.0 + qd * 4.0 + act;
        if cost < best_cost {
            best_cost = cost;
            best = i;
        }
    }
    best
}

#[derive(Clone, Copy)]
enum HandoffKind {
    Tls,
    Plain,
    /// HTTP→HTTPS 301 redirector connections (plain, no TLS).
    Redir,
}

/// The single acceptor: owns the one listener and hands each accepted
/// socket to the chosen shard's mailbox. Runs on a dedicated small runtime
/// thread — accept is a syscall loop, no pinning needed (the kernel spreads
/// its IRQs; the data path stays on the shards).
fn spawn_acceptor(
    name: &'static str,
    bind_addr: SocketAddr,
    kind: HandoffKind,
    shards: usize,
) {
    let std_listener = bind_shard_listener(bind_addr);
    let rt = tokio::runtime::Builder::new_current_thread()
        .enable_all()
        .build()
        .expect("acceptor runtime failed");
    std::thread::Builder::new()
        .name(name.into())
        .spawn(move || {
            rt.block_on(async move {
                // Convert inside the runtime context: from_std registers the
                // fd with this thread's reactor.
                let listener = tokio::net::TcpListener::from_std(std_listener)
                    .unwrap_or_else(|e| panic!("{name} listener -> tokio failed: {e}"));
                loop {
                    // Backpressure: when the hand-off budget is exhausted,
                    // STOP accepting. New clients then queue in the kernel's
                    // listen backlog (8191 deep) and are served with TCP
                    // retransmit timing once pressure drops — far better
                    // than unbounded userspace buffering (OOM under a flood).
                    while ACCEPT_BACKPRESSURE.load(Ordering::Acquire) {
                        tokio::time::sleep(std::time::Duration::from_millis(5)).await;
                    }
                    match listener.accept().await {
                        Ok((stream, peer)) => {
                            let _ = stream.set_nodelay(true);
                            let mailboxes = match kind {
                                HandoffKind::Tls => handoff_tls(),
                                HandoffKind::Plain => handoff_plain(),
                                HandoffKind::Redir => handoff_redir(),
                            };
                            if let Some(m) = mailboxes.get(pick_shard(shards, kind)) {
                                m.push(stream, peer.ip());
                            }
                        }
                        Err(e) => eprintln!("❌ {name} accept error: {e}"),
                    }
                }
            })
        })
        .expect("failed to spawn acceptor thread");
}

fn run_shard(
    shard_idx: u32,
    _shard_count: u32,
    http_addr: SocketAddr,
    plain_addr: Option<String>,
    redirect_addr: Option<String>,
    grpc_addr: SocketAddr,
    routes_file: String,
    acceptor: TlsAcceptor,
    shared_config: Arc<ArcSwap<ServerConfig>>,
) {
    let rt = tokio::runtime::Builder::new_current_thread()
        .enable_all()
        .build()
        .expect("shard runtime failed");
    // A LocalSet is what makes spawn_local legal: connection tasks never
    // leave this shard's thread, which is the whole point of sharding.
    let local = tokio::task::LocalSet::new();
    local.block_on(&rt, async move {
        if shard_idx == 0 {
            // ---- Control Plane (gRPC management interface), shard 0 only ----
            // A 2-thread runtime keeps the control plane responsive even if
            // shard 0's data-plane accept loop stalls.
            let grpc_cfg = shared_config.clone();
            std::thread::Builder::new()
                .name("grpc-control-plane".into())
                .spawn(move || {
                    let rt = tokio::runtime::Builder::new_multi_thread()
                        .worker_threads(2)
                        .enable_all()
                        .build()
                        .expect("grpc runtime failed");
                    rt.block_on(async {
                        let svc = MyControlPlane::new(grpc_cfg, routes_file);
                        if let Err(e) = Server::builder()
                            .add_service(ControlPlaneServer::new(svc))
                            .serve(grpc_addr)
                            .await
                        {
                            eprintln!("❌ gRPC Control Plane error: {e:#}");
                        }
                    });
                })
                .expect("failed to spawn grpc thread");
            println!("⚙️  Control Plane (gRPC) listening on {grpc_addr}");
        }

        // ---- Optional plain-HTTP Data Plane.
        // KernelReusePort: every shard gets its own SO_REUSEPORT listener so
        // the kernel spreads plain traffic too (dev/bench convenience path).
        // HandOff: shards drain their plain mailbox instead; the central
        // acceptor owns the single listener and picks the shard per conn.
        if let Some(plain) = plain_addr {
            if accept_mode() == AcceptMode::HandOff {
                let shard = shard_idx as usize;
                let cfg = shared_config.clone();
                tokio::task::spawn_local(async move {
                    loop {
                        // Pop is non-blocking; when the mailbox is empty,
                        // park on the notifier instead of spinning — 0% CPU
                        // idle, exact wake-up on the first handed-off conn.
                        let Some((stream, peer_ip)) =
                            handoff_plain().get(shard).and_then(|m| m.pop())
                        else {
                            if let Some(m) = handoff_plain().get(shard) {
                                m.wait_for_item().await;
                            }
                            continue;
                        };
                        let cfg = cfg.clone();
                        if let Some(c) = shard_counters().get(shard) {
                            c.accepted_total.fetch_add(1, Ordering::Relaxed);
                            c.active_conns.fetch_add(1, Ordering::Relaxed);
                        }
                        tokio::task::spawn_local(async move {
                            let _guard = ShardConnGuard(shard);
                            handle_connection(stream, cfg, peer_ip, shard).await
                        });
                    }
                });
            } else {
            let plain_addr: SocketAddr = plain
                .parse()
                .unwrap_or_else(|_| panic!("bad JUGGLER_PLAIN_HTTP_ADDR"));
            let listener = tokio::net::TcpListener::from_std(bind_shard_listener(plain_addr))
                .expect("plain listener -> tokio failed");
            if shard_idx == 0 {
                println!("🌐 Data Plane (plain HTTP) listening on http://{plain_addr}");
            }
            let cfg = shared_config.clone();
            tokio::task::spawn_local(async move {
                loop {
                    match listener.accept().await {
                        Ok((stream, peer)) => {
                            let _ = stream.set_nodelay(true);
                            let cfg = cfg.clone();
                            if let Some(c) = shard_counters().get(shard_idx as usize) {
                                c.accepted_total.fetch_add(1, Ordering::Relaxed);
                                c.active_conns.fetch_add(1, Ordering::Relaxed);
                            }
                            // Local spawn: the connection lives on this shard.
                            tokio::task::spawn_local(async move {
                                let _guard = ShardConnGuard(shard_idx as usize);
                                handle_connection(stream, cfg, peer.ip(), shard_idx as usize).await
                            });
                        }
                        Err(e) => eprintln!("❌ plain accept error: {e}"),
                    }
                }
            });
            }
        }

        // ---- Optional HTTP→HTTPS redirect path: same sharded accept
        // model (KernelReusePort: own listener per shard; HandOff: drain
        // the redirect mailbox), answers 301 with preserved host/path/query.
        if let Some(redir) = redirect_addr {
            let tls_port = http_addr.port();
            let spawn_redirect = move |stream: TcpStream, peer_ip: IpAddr, shard: usize| {
                tokio::task::spawn_local(async move {
                    let _guard = ShardConnGuard(shard);
                    if let Err(e) = handle_redirect_connection(stream, tls_port).await {
                        eprintln!("⚠️ redirect error from {peer_ip}: {e:#}");
                    }
                });
            };
            if accept_mode() == AcceptMode::HandOff {
                let shard = shard_idx as usize;
                tokio::task::spawn_local(async move {
                    loop {
                        let Some((stream, peer_ip)) =
                            handoff_redir().get(shard).and_then(|m| m.pop())
                        else {
                            if let Some(m) = handoff_redir().get(shard) {
                                m.wait_for_item().await;
                            }
                            continue;
                        };
                        spawn_redirect(stream, peer_ip, shard);
                    }
                });
            } else {
            let redir_addr: SocketAddr = redir
                .parse()
                .unwrap_or_else(|_| panic!("bad JUGGLER_REDIRECT_HTTP"));
            let listener = tokio::net::TcpListener::from_std(bind_shard_listener(redir_addr))
                .expect("redirect listener -> tokio failed");
            if shard_idx == 0 {
                println!("↪️  HTTP→HTTPS redirect listening on http://{redir_addr} → https://{http_addr}");
            }
            tokio::task::spawn_local(async move {
                loop {
                    match listener.accept().await {
                        Ok((stream, peer)) => {
                            let _ = stream.set_nodelay(true);
                            spawn_redirect(stream, peer.ip(), shard_idx as usize);
                        }
                        Err(e) => eprintln!("❌ redirect accept error: {e}"),
                        }
                }
            });
            }
        }

        // ---- TLS Data Plane.
        // KernelReusePort: this shard's own listener, kernel-spread.
        // HandOff: drain the TLS mailbox fed by the central acceptor.
        if accept_mode() == AcceptMode::HandOff {
            let shard = shard_idx as usize;
            loop {
                let Some((stream, peer_ip)) = handoff_tls().get(shard).and_then(|m| m.pop()) else {
                    if let Some(m) = handoff_tls().get(shard) {
                        m.wait_for_item().await;
                    }
                    continue;
                };
                let tls = acceptor.clone();
                let cfg = shared_config.clone();
                if let Some(c) = shard_counters().get(shard) {
                    c.accepted_total.fetch_add(1, Ordering::Relaxed);
                    c.active_conns.fetch_add(1, Ordering::Relaxed);
                }
                tokio::task::spawn_local(async move {
                    let _guard = ShardConnGuard(shard);
                    match tls.accept(stream).await {
                        Ok(tls_stream) => {
                            // ALPN decides the frontend: h2 gets the
                            // multiplexing handler, everything else
                            // (http/1.1 or no ALPN) the h1 loop.
                            let is_h2 = tls_stream.get_ref().1.alpn_protocol()
                                == Some(b"h2".as_slice());
                            let result = if is_h2 {
                                handle_h2_connection(tls_stream, cfg, peer_ip).await
                            } else {
                                handle_connection(tls_stream, cfg, peer_ip, shard).await
                            };
                            if let Err(e) = result {
                                eprintln!("⚠️ connection error from {peer_ip}: {e:#}");
                            }
                        }
                        Err(e) => {
                            eprintln!("⚠️ TLS handshake failed from {peer_ip}: {e}");
                        }
                    }
                });
            }
        }
        let listener =
            tokio::net::TcpListener::from_std(bind_shard_listener(http_addr))
                .expect("tls listener -> tokio failed");
        if shard_idx == 0 {
            println!("🔒 Data Plane (HTTPS) listening on https://{http_addr} (sharded accept, SO_REUSEPORT)");
        }
        loop {
            match listener.accept().await {
                Ok((stream, peer)) => {
                    let _ = stream.set_nodelay(true);
                    let tls = acceptor.clone();
                    let cfg = shared_config.clone();
                    if let Some(c) = shard_counters().get(shard_idx as usize) {
                        c.accepted_total.fetch_add(1, Ordering::Relaxed);
                        c.active_conns.fetch_add(1, Ordering::Relaxed);
                    }
                    // Local spawn: stays on this shard's core for life.
                    tokio::task::spawn_local(async move {
                        let _guard = ShardConnGuard(shard_idx as usize);
                        match tls.accept(stream).await {
                            Ok(tls_stream) => {
                                // ALPN decides the frontend: h2 gets the
                                // multiplexing handler, everything else
                                // (http/1.1 or no ALPN) the h1 loop.
                                let is_h2 = tls_stream.get_ref().1.alpn_protocol()
                                    == Some(b"h2".as_slice());
                                let result = if is_h2 {
                                    handle_h2_connection(tls_stream, cfg, peer.ip()).await
                                } else {
                                    handle_connection(tls_stream, cfg, peer.ip(), shard_idx as usize).await
                                };
                                if let Err(e) = result {
                                    eprintln!("⚠️ connection error from {peer}: {e:#}");
                                }
                            }
                            Err(e) => {
                                eprintln!("⚠️ TLS handshake failed from {peer}: {e}");
                            }
                        }
                    });
                }                Err(e) => eprintln!("❌ accept error: {e}"),
            }
        }
    });
}

// ============================================================================
// Data Plane: HTTP/1.1 frontend (per-connection keep-alive loop)
// ============================================================================

const MAX_REQUEST_HEAD: usize = 32 * 1024;

/// HTML page served at `/` so the server is self-describing in a browser.
const LANDING_PAGE: &str = r#"<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Juggler Web Server</title>
<style>
body{background:#0f1117;color:#e6e9f0;font:16px/1.6 "Segoe UI",system-ui,sans-serif;
max-width:720px;margin:0 auto;padding:60px 24px}
h1{color:#4ade80}code{background:#10131c;border:1px solid #262b3a;border-radius:4px;
padding:1px 6px}li{margin:6px 0}
</style></head><body>
<h1>🤹 Juggler Web Server v0.9.0</h1>
<p>Forecast core: <b>Pythia</b> — adaptive Holt predictor + signature cost oracle (Named after the Delphic oracle).</p>
<p>Thread-per-core HTTPS reverse proxy with sharded SO_REUSEPORT accept,
hot-reloadable gRPC Control Plane, WASM plugin filters and passive health
checks.</p>
<ul>
<li><code>/healthz</code> — liveness probe</li>
<li><code>/metrics</code> — Prometheus metrics</li>
<li>Configured routes are proxied per the routing table (see README)</li>
</ul>
<p><em>Note:</em> the built-in certificate is self-signed; browsers warn —
accept the exception or trust the cert.</p>
</body></html>
"#;

/// Keep-alive safety cap per connection. Env-tunable so long-running load
/// tests can avoid reconnect churn (default 1000 matches production).
fn max_keepalive_requests() -> usize {
    settings().max_keepalive_requests.get() as usize
}

/// Per-shard connection-buffer pool. Each worker keeps its own lock-free
/// freelist of 16 KiB `BytesMut` read buffers (the size nginx uses for
/// client buffers) so a keep-alive connection does not `malloc`/`free` a
/// fresh allocation on every request and freed buffers stay cache-warm on
/// the same core. The `Mutex<()>` is only ever contended on cross-shard
/// steals; the MPMC queue itself is lock-free.
struct BufferPool {
    cap: usize,
    q: crossbeam_queue::SegQueue<BytesMut>,
    lock: Mutex<()>,
}

impl BufferPool {
    const CAP: usize = 16 * 1024;

    fn get(&self) -> BytesMut {
        if let Some(b) = self.q.pop() {
            return b;
        }
        // BytesMut::zeroed(n) yields len == n (a zero-filled buffer), so
        // clear() to an empty len with capacity n before first use.
        let mut b = BytesMut::zeroed(Self::CAP);
        b.clear();
        b
    }

    fn put(&self, mut b: BytesMut) {
        // Only pool buffers that stayed at pool capacity (no pathological
        // oversized heads retained forever).
        if b.capacity() != Self::CAP {
            return;
        }
        b.clear();
        let _g = self.lock.lock();
        if self.q.len() < 64 {
            self.q.push(b);
        }
        // else: drop (freelist full)
    }
}

/// One pool per shard, created at startup.
static BUF_POOLS: OnceLock<Vec<BufferPool>> = OnceLock::new();

fn init_buf_pools(shards: usize) {
    let _ = BUF_POOLS.set(
        (0..shards.max(1))
            .map(|_| BufferPool {
                cap: BufferPool::CAP,
                q: crossbeam_queue::SegQueue::new(),
                lock: Mutex::new(()),
            })
            .collect(),
    );
}

fn buf_pool(shard_idx: usize) -> &'static BufferPool {
    let pools = BUF_POOLS.get().expect("buf pools not initialized");
    &pools[shard_idx % pools.len()]
}

/// Serve one connection (TLS or plain): loop over requests until the client
/// asks to close, an error occurs, or the safety limit is reached.
async fn handle_connection<S>(
    mut client: S,
    config: Arc<ArcSwap<ServerConfig>>,
    peer_ip: IpAddr,
    shard_idx: usize,
) -> Result<()>
where
    S: tokio::io::AsyncRead + tokio::io::AsyncWrite + Unpin + Send,
{
    let mut served: usize = 0;
    let mut buf = buf_pool(shard_idx).get();
    // Watchdog: slow-request detector. If the whole request exceeds 1 s,
    // log shard+path — this is the ground truth for "which plane stalls"
    // (a conn that never reaches here is stuck in hand-off/accept, not in
    // the pipeline).
    let conn_t0 = Instant::now();

    loop {
        // ---- read one full request ----
        let parse_t0 = Instant::now();
        let req = loop {
            if buf.len() > MAX_REQUEST_HEAD {
                client
                    .write_all(&http_response(
                        "431 Request Header Fields Too Large",
                        "header too large",
                        false,
                    ))
                    .await?;
                buf_pool(shard_idx).put(buf);
                return Ok(());
            }
            let chunk = client.read_buf(&mut buf).await?;
            if chunk == 0 {
                if served > 1 {
                    KEEPALIVE_CONNECTIONS_TOTAL.inc();
                }
                buf_pool(shard_idx).put(buf);
                return Ok(()); // client closed the connection
            }
            if let Some(req) = parse_request(&buf) {
                let need = req.body_offset + req.content_length();
                if buf.len() >= need {
                    // Stage: parse = read-to-full-request (socket read + scan).
                    record_stage(shard_idx, STAGE_PARSE, parse_t0);
                    break req;
                }
            }
        };

        let route_t0 = Instant::now();
        // Materialize the path for routing/metrics (single small alloc; the
        // range stays zero-copy internally).
        let path = req.path(&buf);
        let keep_alive = req.wants_keep_alive() && served + 1 < max_keepalive_requests();
        served += 1;

        // Body bytes live after the header block; the WASM pipeline (run
        // inside resolve_route) may rewrite them before forwarding.
        let mut body: Vec<u8> = buf[req.body_offset.min(buf.len())..].to_vec();

        record_stage(shard_idx, STAGE_ROUTE, route_t0);

        match resolve_route(&path, &mut body, peer_ip, PROTO_H1, &config) {
            RouteDecision::RateLimited => {
                client
                    .write_all(&http_response(
                        "429 Too Many Requests",
                        "Rate limit exceeded. Slow down.",
                        false,
                    ))
                    .await?;
                buf_pool(shard_idx).put(buf);
                return Ok(());
            }
            RouteDecision::Builtin(status, body) => {
                let status_line = format!("{status} OK");
                let response = if body.starts_with("<!DOCTYPE html>") {
                    http_html_response(&status_line, &body, keep_alive)
                } else {
                    http_response(&status_line, &body, keep_alive)
                };
                client.write_all(&response).await?;
            }
            RouteDecision::NotFound => {
                client
                    .write_all(&http_response("404 Not Found", "route not found", keep_alive))
                    .await?;
            }
            RouteDecision::StaticFile(mut head, file_body) => {
                // Re-issue the head with the connection's actual keep-alive
                // decision (serve_static_file built it for `true`).
                if !keep_alive {
                    head = patch_connection_header(&head, false);
                }
                client.write_all(&head).await?;
                client.write_all(&file_body).await?;
            }
            RouteDecision::Proxy(upstreams, body, extra_headers, security_headers) => {
                let started = Instant::now();
                let forwarded = build_forwarded_request(&req, &buf, &body, &extra_headers);
                // Signature oracle: hash the request pattern so the cost
                // prediction is available to the balancer and the observed
                // cost is fed back after dispatch.
                let sig = request_signature_hash(
                    req.method(&buf).as_bytes(),
                    &path,
                    body.len(),
                );
                let (result, used_upstream) =
                    dispatch_to_upstreams(&forwarded, &upstreams, sig).await;
                record_stage(shard_idx, STAGE_UPSTREAM, started);
                let elapsed = started.elapsed().as_secs_f64();
                HTTP_RESPONSE_SECONDS
                    .with_label_values(&[&path])
                    .observe(elapsed);
                if let Some(addr) = &used_upstream {
                    UPSTREAM_REQUESTS_TOTAL
                        .with_label_values(&[addr, "ok"])
                        .inc();
                }

                let resp_t0 = Instant::now();
                let final_status = match result {
                    Ok(outcome) => {
                        let patched = with_security_headers(
                            normalize_response_in_place(outcome.response, keep_alive),
                            &security_headers,
                        );
                        client.write_all(&patched).await?;
                        client.flush().await?;
                        status_label(outcome.status)
                    }
                    Err(DispatchError::NoUpstreams) => {
                        client
                            .write_all(&http_response(
                                "503 Service Unavailable",
                                "no upstreams available",
                                keep_alive,
                            ))
                            .await?;
                        "503"
                    }
                    Err(DispatchError::AllFailed(e)) => {
                        eprintln!("⚠️ all upstreams failed for {path}: {e}");
                        client
                            .write_all(&http_response(
                                "502 Bad Gateway",
                                "upstream unreachable",
                                keep_alive,
                            ))
                            .await?;
                        "502"
                    }
                };
                HTTP_REQUESTS_TOTAL
                    .with_label_values(&[&path, final_status, PROTO_H1])
                    .inc();
                record_stage(shard_idx, STAGE_RESPOND, resp_t0);
            }
        }

        // Slow-request watchdog: >1 s wall time for the whole request.
        let req_wall = parse_t0.elapsed();
        if req_wall.as_millis() > 1000 {
            eprintln!(
                "🐢 slow request on shard {shard_idx}: {} took {:.3}s (conn age {:.1}s)",
                path,
                req_wall.as_secs_f64(),
                conn_t0.elapsed().as_secs_f64()
            );
        }

        if !keep_alive {
            if served > 1 {
                KEEPALIVE_CONNECTIONS_TOTAL.inc();
            }
            buf_pool(shard_idx).put(buf);
            return Ok(());
        }
        consume_consumed_request(&mut buf, &req);
    }
}

/// Drop the bytes of the request that was just handled from the buffer so
/// the next iteration can parse the following pipelined request.
/// Consume the bytes of a fully dispatched request. Splitting preserves the
/// allocation (BytesMut keeps its capacity across `split_to`), so the buffer
/// can be returned to the shard pool when the connection closes.
fn consume_consumed_request(buf: &mut BytesMut, req: &RawRequest) {
    let need = req.body_offset + req.content_length();
    let n = need.min(buf.len());
    let _ = buf.split_to(n);
}

/// Build the bytes forwarded upstream for an HTTP/1.1 client request:
/// the original head, plus X-Forwarded-For / X-Request-Id and any headers
/// injected by WASM plugins, followed by the (possibly plugin-rewritten)
/// body. Content-Length always matches the body that is actually sent.
///
/// Single-pass: one allocation, no intermediate header buffer, no splice
/// (the old path paid a full tail memmove + a second head copy whenever a
/// plugin rewrote the body).
fn build_forwarded_request(
    req: &RawRequest,
    raw: &[u8],
    body: &[u8],
    extra_headers: &[(String, String)],
) -> Vec<u8> {
    let head_len = req.body_offset.min(raw.len());
    let head_end = head_len.saturating_sub(2); // keep the last header CRLF, drop the blank line
    let extras_len: usize = extra_headers.iter().map(|(k, v)| k.len() + v.len() + 4).sum();
    let mut out = Vec::with_capacity(head_len + body.len() + extras_len + 128);

    // Copy the head (without the final blank-line CRLF), rewriting the
    // Content-Length line inline when the body size changed.
    let cl_differs = req.content_length() != body.len();
    if cl_differs {
        match find_header_line(&raw[..head_end], b"content-length") {
            Some((ls, le)) => {
                out.extend_from_slice(&raw[..ls]);
                out.extend_from_slice(format!("Content-Length: {}\r\n", body.len()).as_bytes());
                out.extend_from_slice(&raw[le..head_end]);
            }
            None => out.extend_from_slice(&raw[..head_end]),
        }
    } else {
        out.extend_from_slice(&raw[..head_end]);
    }

    // Injected headers, written directly into the output (zero temp allocs).
    out.extend_from_slice(b"X-Forwarded-For: client\r\n");
    out.extend_from_slice(b"X-Request-Id: req-");
    const HEX: &[u8; 16] = b"0123456789abcdef";
    let r = rand::random::<u64>();
    let mut shift = 64i32;
    while shift > 0 {
        shift -= 4;
        out.push(HEX[((r >> shift) & 0xF) as usize]);
    }
    out.extend_from_slice(b"\r\n");
    for (k, v) in extra_headers {
        out.extend_from_slice(k.as_bytes());
        out.extend_from_slice(b": ");
        out.extend_from_slice(v.as_bytes());
        out.extend_from_slice(b"\r\n");
    }
    // Ask the upstream to keep the connection open: without this, an h1
    // origin closes the socket after the first response and the keep-alive
    // pool would never form.
    out.extend_from_slice(b"Connection: keep-alive\r\n");
    out.extend_from_slice(b"\r\n");
    out.extend_from_slice(body);
    out
}

/// Locate a header line (case-insensitive name) inside a raw request head.
/// Returns (line_start, line_end) with `line_end` past the trailing CRLF.
fn find_header_line(head: &[u8], name: &[u8]) -> Option<(usize, usize)> {
    let name_str = std::str::from_utf8(name).ok()?;
    let mut off = 0usize;
    let mut first = true;
    while off < head.len() {
        let end = head[off..].windows(2).position(|w| w == b"\r\n").map(|p| off + p).unwrap_or(head.len());
        if !first {
            let line = &head[off..end];
            if let Some(colon) = line.iter().position(|&b| b == b':') {
                let kn = &line[..colon];
                let kn = std::str::from_utf8(kn).unwrap_or("").trim();
                if kn.eq_ignore_ascii_case(name_str) {
                    return Some((off, (end + 2).min(head.len())));
                }
            }
        }
        first = false;
        if end >= head.len() {
            break;
        }
        off = end + 2;
    }
    None
}

/// (Kept for tests + potential reuse; the hot path rewrites CL inline now.)
#[allow(dead_code)]
fn replace_content_length(mut raw: Vec<u8>, new_len: usize) -> Vec<u8> {
    let needle = b"Content-Length:";
    let pos = raw
        .windows(needle.len())
        .position(|w| w.eq_ignore_ascii_case(needle));
    if let Some(start) = pos {
        if let Some(end) = raw[start..].windows(2).position(|w| w == b"\r\n") {
            let replacement = format!("Content-Length: {new_len}\r\n");
            raw.splice(start..start + end + 2, replacement.as_bytes().iter().copied());
        }
    }
    raw
}

/// True when the raw request head declares its body length, i.e. the upstream
/// can finish reading the request without relying on connection close. This is
/// the precondition for reusing an upstream connection.
pub fn body_has_content_length(head: &[u8]) -> bool {
    let text = match std::str::from_utf8(head) {
        Ok(t) => t,
        Err(_) => return false,
    };
    // Chunked request bodies need framing-sensitive handling; keep it simple
    // and never reuse connections that carried one.
    let has_te = text.split("\r\n").skip(1).any(|l| {
        l.split_once(':')
            .map(|(k, _)| k.trim().eq_ignore_ascii_case("transfer-encoding"))
            .unwrap_or(false)
    });
    if has_te {
        return false;
    }
    match text.split("\r\n").skip(1).find(|l| {
        l.split_once(':')
            .map(|(k, _)| k.trim().eq_ignore_ascii_case("content-length"))
            .unwrap_or(false)
    }) {
        // No CL and no TE: the request carries no body (RFC 7230 §3.3.2),
        // so it is fully framed and safe for connection reuse.
        None => true,
        Some(line) => line
            .split_once(':')
            .and_then(|(_, v)| v.trim().parse::<usize>().ok())
            .is_some(),
    }
}

// ============================================================================
// Data Plane: HTTP/2 frontend (ALPN "h2", request multiplexing)
// ============================================================================

/// Serve one TLS connection negotiated as HTTP/2: accept streams until the
/// client closes the connection (each stream = one request, multiplexed).
async fn handle_h2_connection<S>(
    stream: S,
    config: Arc<ArcSwap<ServerConfig>>,
    peer_ip: IpAddr,
) -> Result<()>
where
    S: tokio::io::AsyncRead + tokio::io::AsyncWrite + Unpin + Send + 'static,
{
    let mut conn = h2::server::handshake(stream).await?;
    let mut served: usize = 0;

    while let Some(request) = conn.accept().await {
        let (request, respond) = match request {
            Ok(r) => r,
            Err(e) => {
                eprintln!("⚠️ h2 stream error: {e}");
                return Ok(());
            }
        };
        served += 1;
        if served > max_keepalive_requests() {
            break; // safety valve, mirrors the HTTP/1.1 keep-alive cap
        }
        let cfg = config.clone();
        tokio::spawn(async move {
            if let Err(e) = handle_h2_request(request, respond, cfg, peer_ip).await {
                eprintln!("⚠️ h2 request error: {e:#}");
            }
        });
    }
    if served > 1 {
        KEEPALIVE_CONNECTIONS_TOTAL.inc();
    }
    Ok(())
}

/// Send a complete HTTP/2 response (status + headers + body) for a request
/// stream.
async fn send_h2_response(
    respond: &mut SendResponse<Bytes>,
    status: u16,
    extra_headers: &[(&str, &str)],
    body: Bytes,
) -> Result<()> {
    let mut builder = http::Response::builder().status(status);
    for (k, v) in extra_headers {
        builder = builder.header(*k, *v);
    }
    let response = builder
        .body(())
        .map_err(|e| anyhow::anyhow!("invalid h2 response: {e}"))?;
    let mut send_stream = respond
        .send_response(response, false)
        .map_err(|e| anyhow::anyhow!("h2 send_response failed: {e}"))?;
    send_stream
        .send_data(body, true)
        .map_err(|e| anyhow::anyhow!("h2 send_data failed: {e}"))?;
    Ok(())
}

/// Serve one HTTP/2 request stream: resolve the route, then either answer
/// directly or re-encode the request as HTTP/1.1 for the upstream pool and
/// translate the response back into HTTP/2 frames.
async fn handle_h2_request(
    request: http::Request<RecvStream>,
    mut respond: SendResponse<Bytes>,
    config: Arc<ArcSwap<ServerConfig>>,
    peer_ip: IpAddr,
) -> Result<()> {
    let (head, mut body) = request.into_parts();

    // Full target (path + query) for forwarding, bare path for routing.
    let target = head
        .uri
        .path_and_query()
        .map(|pq| pq.as_str().to_string())
        .unwrap_or_else(|| "/".to_string());
    let path = head.uri.path().to_string();

    // Read the full request body (end_stream or Content-Length framed).
    let mut body_bytes = Vec::new();
    while let Some(chunk) = body.data().await {
        let data = chunk.map_err(|e| anyhow::anyhow!("h2 body read failed: {e}"))?;
        let _ = body.flow_control().release_capacity(data.len());
        body_bytes.extend_from_slice(&data);
        if body_bytes.len() > MAX_REQUEST_HEAD {
            send_h2_response(
                &mut respond,
                431,
                &[("content-type", "text/plain; charset=utf-8")],
                Bytes::from_static(b"payload too large"),
            )
            .await?;
            return Ok(());
        }
    }

    match resolve_route(&path, &mut body_bytes, peer_ip, PROTO_H2, &config) {
        RouteDecision::RateLimited => {
            send_h2_response(
                &mut respond,
                429,
                &[("content-type", "text/plain; charset=utf-8")],
                Bytes::from_static(b"Rate limit exceeded. Slow down."),
            )
            .await?;
        }
        RouteDecision::Builtin(status, text) => {
            let html_ct = "text/html; charset=utf-8";
            let ct = if status == 200 && text.starts_with("<!DOCTYPE html>") {
                html_ct
            } else {
                "text/plain; charset=utf-8"
            };
            send_h2_response(&mut respond, status, &[("content-type", ct)], Bytes::from(text))
                .await?;
        }
        RouteDecision::NotFound => {
            send_h2_response(
                &mut respond,
                404,
                &[("content-type", "text/plain; charset=utf-8")],
                Bytes::from_static(b"route not found"),
            )
            .await?;
        }
        RouteDecision::StaticFile(raw_head, file_body) => {
            // Parse the status + Content-Type out of the prebuilt H1 head.
            let text = String::from_utf8_lossy(&raw_head);
            let status: u16 = text
                .split_whitespace()
                .nth(1)
                .and_then(|s| s.parse().ok())
                .unwrap_or(200);
            let ct = text
                .split("\r\n")
                .find_map(|l| {
                    l.strip_prefix("Content-Type:")
                        .map(|v| v.trim().to_string())
                })
                .unwrap_or_else(|| "application/octet-stream".to_string());
            send_h2_response(&mut respond, status, &[("content-type", ct.as_str())],
                Bytes::from(file_body)).await?;
        }
        RouteDecision::Proxy(upstreams, body, extra_headers, security_headers) => {
            let started = Instant::now();
            let forwarded = build_h1_request_from_parts(
                &head.method,
                &target,
                &head.headers,
                &body,
                &extra_headers,
            );
            let sig = request_signature_hash(
                head.method.as_str().as_bytes(),
                &path,
                body.len(),
            );
            let (result, used_upstream) =
                dispatch_to_upstreams(&forwarded, &upstreams, sig).await;
            HTTP_RESPONSE_SECONDS
                .with_label_values(&[&path])
                .observe(started.elapsed().as_secs_f64());
            if let Some(addr) = &used_upstream {
                UPSTREAM_REQUESTS_TOTAL
                    .with_label_values(&[addr, "ok"])
                    .inc();
            }

            let final_status = match result {
                Ok(outcome) => {
                    // Translate the upstream HTTP/1.1 response into h2 frames.
                    if let Some((code, headers, body_start)) =
                        parse_h1_response_head(&outcome.response)
                    {
                        let mut hdrs: Vec<(String, String)> = Vec::new();
                        for (k, v) in &security_headers {
                            let exists = headers
                                .iter()
                                .any(|(hk, _)| hk.eq_ignore_ascii_case(k));
                            if !exists {
                                hdrs.push((title_case_header(k), v.clone()));
                            }
                        }
                        for (k, v) in &headers {
                            let kl = k.to_ascii_lowercase();
                            // Strip hop-by-hop / framing headers: HTTP/2
                            // owns framing and connection management.
                            if matches!(
                                kl.as_str(),
                                "connection"
                                    | "transfer-encoding"
                                    | "keep-alive"
                                    | "proxy-connection"
                                    | "upgrade"
                            ) {
                                continue;
                            }
                            hdrs.push((title_case_header(k), v.clone()));
                        }
                        let hdr_refs: Vec<(&str, &str)> = hdrs
                            .iter()
                            .map(|(k, v)| (k.as_str(), v.as_str()))
                            .collect();
                        let body = Bytes::copy_from_slice(&outcome.response[body_start..]);
                        send_h2_response(&mut respond, code, &hdr_refs, body).await?;
                        status_label(code)
                    } else {
                        send_h2_response(
                            &mut respond,
                            502,
                            &[("content-type", "text/plain; charset=utf-8")],
                            Bytes::from_static(b"upstream sent a non-HTTP response"),
                        )
                        .await?;
                        "502"
                    }
                }
                Err(DispatchError::NoUpstreams) => {
                    send_h2_response(
                        &mut respond,
                        503,
                        &[("content-type", "text/plain; charset=utf-8")],
                        Bytes::from_static(b"no upstreams available"),
                    )
                    .await?;
                    "503"
                }
                Err(DispatchError::AllFailed(e)) => {
                    eprintln!("⚠️ all upstreams failed for {path}: {e}");
                    send_h2_response(
                        &mut respond,
                        502,
                        &[("content-type", "text/plain; charset=utf-8")],
                        Bytes::from_static(b"upstream unreachable"),
                    )
                    .await?;
                    "502"
                }
            };
            HTTP_REQUESTS_TOTAL
                .with_label_values(&[&path, final_status, PROTO_H2])
                .inc();
        }
    }

    Ok(())
}

/// Encode an HTTP/2 request (already parsed by the h2 crate) as a complete
/// HTTP/1.1 request for the upstream. Hop-by-hop headers are stripped,
/// Host is carried over, X-Forwarded-For / X-Request-Id are (re)set, and
/// Content-Length always matches the forwarded body.
pub fn build_h1_request_from_parts(
    method: &http::Method,
    target: &str,
    headers: &HeaderMap,
    body: &[u8],
    extra_headers: &[(String, String)],
) -> Vec<u8> {
    let mut out = Vec::with_capacity(body.len() + 512);
    out.extend_from_slice(format!("{} {} HTTP/1.1\r\n", method.as_str(), target).as_bytes());

    let mut host: Option<&str> = None;
    for (name, value) in headers {
        let n = name.as_str();
        // Hop-by-hop and framing headers must not cross the h2 -> h1 bridge.
        if matches!(
            n,
            "connection" | "te" | "upgrade" | "transfer-encoding" | "keep-alive"
                | "proxy-connection" | "content-length"
        ) {
            continue;
        }
        if n == "host" {
            host = value.to_str().ok();
            continue;
        }
        // Juggler sets these itself (replace, nginx-style).
        if matches!(n, "x-forwarded-for" | "x-request-id") {
            continue;
        }
        if let Ok(v) = value.to_str() {
            out.extend_from_slice(
                format!("{}: {}\r\n", title_case_header(n), v).as_bytes(),
            );
        }
    }
    out.extend_from_slice(
        format!("Host: {}\r\n", host.unwrap_or("localhost")).as_bytes(),
    );
    out.extend_from_slice(b"Connection: keep-alive\r\n");
    out.extend_from_slice(b"X-Forwarded-For: client\r\n");
    out.extend_from_slice(format!("X-Request-Id: req-{:x}\r\n", rand::random::<u64>()).as_bytes());
    for (k, v) in extra_headers {
        out.extend_from_slice(format!("{k}: {v}\r\n").as_bytes());
    }
    out.extend_from_slice(format!("Content-Length: {}\r\n", body.len()).as_bytes());
    out.extend_from_slice(b"\r\n");
    out.extend_from_slice(body);
    out
}

// ============================================================================
// Unit tests
// ============================================================================

#[cfg(test)]
mod tests {
    use super::*;
    use std::time::Duration;

    // ---- Static file serving ----

    #[test]
    fn mime_table_covers_common_types() {
        assert_eq!(mime_type_of("a/index.html"), "text/html; charset=utf-8");
        assert_eq!(mime_type_of("style.CSS"), "text/css; charset=utf-8");
        assert_eq!(mime_type_of("app.js"), "text/javascript; charset=utf-8");
        assert_eq!(mime_type_of("img/photo.jpeg"), "image/jpeg");
        assert_eq!(mime_type_of("font.woff2"), "font/woff2");
        assert_eq!(mime_type_of("blob.xyz"), "application/octet-stream");
        assert_eq!(mime_type_of("noext"), "application/octet-stream");
    }

    #[test]
    fn static_path_maps_and_rejects_traversal() {
        let root = "/tmp/www/site1";
        assert_eq!(static_path_for(root, "/"), Some(format!("{root}/index.html")));
        assert_eq!(static_path_for(root, ""), Some(format!("{root}/index.html")));
        assert_eq!(
            static_path_for(root, "/css/main.css"),
            Some(format!("{root}/css/main.css"))
        );
        assert_eq!(static_path_for(root, "/../etc/passwd"), None);
        assert_eq!(static_path_for(root, "/a/../../b"), None);
        assert_eq!(static_path_for(root, "/a//b"), None);
        assert_eq!(static_path_for(root, "/a\0b"), None);
    }

    #[test]
    fn serve_static_file_roundtrip_and_404() {
        let tmp = std::env::temp_dir().join(format!("jug_static_{}", std::process::id()));
        let site = tmp.join("site");
        std::fs::create_dir_all(&site).unwrap();
        std::fs::write(site.join("index.html"), b"<h1>hi</h1>").unwrap();
        std::fs::write(site.join("data.json"), b"{\"ok\":true}").unwrap();
        let root = site.to_string_lossy().into_owned();

        let (head, body) = match serve_static_file(&root, "/", true) {
            RouteDecision::StaticFile(h, b) => (h, b),
            other => panic!("expected StaticFile, got {other:?}"),
        };
        let head = String::from_utf8(head).unwrap();
        assert!(head.starts_with("HTTP/1.1 200 OK"));
        assert!(head.contains("Content-Type: text/html"));
        assert_eq!(body, b"<h1>hi</h1>");

        let (head, _) = match serve_static_file(&root, "/data.json", true) {
            RouteDecision::StaticFile(h, b) => (h, b),
            other => panic!("expected StaticFile, got {other:?}"),
        };
        assert!(String::from_utf8(head).unwrap().contains("application/json"));

        assert!(matches!(
            serve_static_file(&root, "/missing.png", true),
            RouteDecision::NotFound
        ));
        assert!(matches!(
            serve_static_file(&root, "/../escape.txt", true),
            RouteDecision::StaticFile(_, _)
        ));
        std::fs::remove_dir_all(&tmp).ok();
    }

    #[test]
    fn ensure_www_layout_creates_sample_sites_idempotently() {
        let tmp = std::env::temp_dir().join(format!("jug_www_{}", std::process::id()));
        let root = tmp.to_string_lossy().into_owned();
        assert_eq!(ensure_www_layout(&root), 3, "first run creates 3 samples");
        assert_eq!(ensure_www_layout(&root), 0, "second run creates none");
        for n in 1..=3 {
            let idx = format!("{root}/sample_sites{n}/index.html");
            assert!(std::path::Path::new(&idx).exists(), "{idx} must exist");
        }
        std::fs::remove_dir_all(&tmp).ok();
    }

    #[test]
    fn merge_static_routes_maps_subdirectories() {
        let tmp = std::env::temp_dir().join(format!("jug_merge_{}", std::process::id()));
        std::fs::create_dir_all(tmp.join("alpha")).unwrap();
        std::fs::create_dir_all(tmp.join(".hidden")).unwrap();
        std::fs::write(tmp.join("file.txt"), b"x").unwrap();
        let routes = merge_static_routes(
            &tmp.to_string_lossy(),
            HashMap::new(),
        );
        assert_eq!(routes.len(), 1, "only visible dirs become routes");
        assert_eq!(
            routes["/alpha"].static_dir.as_deref(),
            Some(tmp.join("alpha").to_string_lossy().as_ref())
        );
        std::fs::remove_dir_all(&tmp).ok();
    }

    // ---- TokenBucket ----

    #[test]
    fn bucket_allows_burst_then_rejects() {
        let mut b = TokenBucket::new(3.0, 0.0); // no refill
        assert!(b.take(None));
        assert!(b.take(None));
        assert!(b.take(None));
        assert!(!b.take(None));
        assert!(!b.take(None));
    }

    #[test]
    fn bucket_refills_over_time() {
        let mut b = TokenBucket::new(1.0, 1000.0); // 1000 tokens/sec
        assert!(b.take(None));
        std::thread::sleep(Duration::from_millis(5));
        assert!(b.take(None), "bucket should refill after 5ms at 1000 t/s");
    }

    #[test]
    fn bucket_does_not_exceed_capacity() {
        let mut b = TokenBucket::new(2.0, 10_000.0);
        std::thread::sleep(Duration::from_millis(10));
        assert!(b.take(None));
        assert!(b.take(None));
        assert!(!b.take(None), "capacity cap must hold even after long refill");
    }

    // ---- IpRateLimiter ----

    #[test]
    fn limiter_isolates_ips() {
        let lim = IpRateLimiter::new(2.0, 0.0, 100);
        let a: IpAddr = "10.0.0.1".parse().unwrap();
        let b: IpAddr = "10.0.0.2".parse().unwrap();
        assert!(lim.check_limit(a));
        assert!(lim.check_limit(a));
        assert!(!lim.check_limit(a)); // exhausted
        assert!(lim.check_limit(b)); // separate bucket
    }

    #[test]
    fn limiter_map_is_bounded() {
        let lim = IpRateLimiter::new(1.0, 0.0, 10);
        for i in 0..100u32 {
            let ip: IpAddr = format!("10.1.{i}.1").parse().unwrap();
            let _ = lim.check_limit(ip);
        }
        assert!(lim.buckets.lock().unwrap().len() <= 11);
    }

    // ---- HTTP parsing ----

    /// Legacy `(String, Vec<(String,String)>)` parser kept ONLY for the
    /// allocation benchmark below: proves the zero-copy rewrite head-to-head
    /// in one process.
    fn parse_request_legacy(buf: &[u8]) -> Option<(String, String, String, Vec<(String, String)>, usize)> {
        let header_end = buf.windows(4).position(|w| w == b"\r\n\r\n")?;
        let head = std::str::from_utf8(&buf[..header_end]).ok()?;
        let mut lines = head.split("\r\n");
        let request_line = lines.next()?;
        let mut parts = request_line.split_whitespace();
        let method = parts.next()?.to_string();
        let target = parts.next()?.to_string();
        let version = parts.next().unwrap_or("HTTP/1.1").to_string();
        if method.is_empty() || target.is_empty() {
            return None;
        }
        let mut headers = Vec::new();
        for line in lines {
            if let Some((k, v)) = line.split_once(':') {
                headers.push((k.trim().to_string(), v.trim().to_string()));
            }
        }
        let path = target.split('?').next().unwrap_or("").to_string();
        Some((method, path, version, headers, header_end + 4))
    }

    #[test]
    fn bench_parse_request_zero_copy_vs_legacy() {
        let raw =
            b"GET /api/users?id=7&tab=overview HTTP/1.1\r\nHost: example.com\r\nUser-Agent: bench/1.0\r\nAccept: */*\r\nAccept-Encoding: gzip, br\r\nConnection: keep-alive\r\nContent-Length: 0\r\n\r\n";
        let iters = 200_000u32;

        let t0 = std::time::Instant::now();
        let mut sink = 0usize;
        for _ in 0..iters {
            let r = parse_request_legacy(raw).unwrap();
            sink += r.0.len() + r.1.len() + r.3.len();
        }
        let legacy_ns = t0.elapsed().as_nanos() as f64 / iters as f64;

        let t1 = std::time::Instant::now();
        for _ in 0..iters {
            let r = parse_request(raw).unwrap();
            sink += r.method(raw).len() + r.path(raw).len();
        }
        let zero_ns = t1.elapsed().as_nanos() as f64 / iters as f64;

        println!(
            "parse benchmark: legacy={legacy_ns:.0} ns/req, zero-copy={zero_ns:.0} ns/req, speedup={:.2}x (sink={sink})",
            legacy_ns / zero_ns
        );
    }

    #[test]
    fn parses_simple_get() {
        let raw = b"GET /api/users?id=7 HTTP/1.1\r\nHost: x\r\nUser-Agent: t\r\n\r\n";
        let r = parse_request(raw).unwrap();
        assert_eq!(r.method(raw), "GET");
        assert_eq!(r.path(raw), "/api/users");
        assert_eq!(r.version(raw), "HTTP/1.1");
        assert_eq!(r.headers.len(), 2);
        assert_eq!(r.content_length(), 0);
    }

    #[test]
    fn parses_post_with_content_length() {
        let raw = b"POST /submit HTTP/1.1\r\nContent-Length: 5\r\n\r\nhello";
        let r = parse_request(raw).unwrap();
        assert_eq!(r.method(raw), "POST");
        assert_eq!(r.content_length(), 5);
    }

    #[test]
    fn rejects_garbage() {
        assert!(parse_request(b"\x16\x03\x01\x00garbage").is_none());
        assert!(parse_request(b"").is_none());
        assert!(parse_request(b"GET").is_none());
    }

    // ---- keep-alive negotiation ----

    #[test]
    fn keep_alive_defaults() {
        let r = parse_request(b"GET / HTTP/1.1\r\nHost: x\r\n\r\n").unwrap();
        assert!(r.wants_keep_alive(), "HTTP/1.1 defaults to keep-alive");

        let r = parse_request(b"GET / HTTP/1.0\r\n\r\n").unwrap();
        assert!(!r.wants_keep_alive(), "HTTP/1.0 defaults to close");

        let r =
            parse_request(b"GET / HTTP/1.1\r\nConnection: close\r\n\r\n").unwrap();
        assert!(!r.wants_keep_alive());

        let r = parse_request(
            b"GET / HTTP/1.0\r\nConnection: keep-alive\r\n\r\n",
        )
        .unwrap();
        assert!(r.wants_keep_alive(), "HTTP/1.0 can opt in");
    }

    // ---- Connection header rewriting ----

    #[test]
    fn patches_connection_header() {
        let raw = b"HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nhi";
        let patched = patch_connection_header(raw, true);
        let text = String::from_utf8(patched).unwrap();
        assert!(text.contains("Connection: keep-alive"));
        assert!(!text.contains("Connection: close"));

        let patched = patch_connection_header(raw, false);
        assert!(String::from_utf8(patched).unwrap().contains("Connection: close"));
    }

    #[test]
    fn patch_appends_close_when_header_missing() {
        let raw = b"HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n";
        let patched = patch_connection_header(raw, false);
        let text = String::from_utf8(patched).unwrap();
        assert!(text.contains("Connection: close"));

        // keep-alive without a header must stay untouched.
        let patched = patch_connection_header(raw, true);
        assert_eq!(patched, raw.to_vec());
    }

    #[test]
    fn normalizes_upstream_10_to_11() {
        let raw = b"HTTP/1.0 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nhi";
        let out = normalize_response_for_client(raw, true);
        let text = String::from_utf8(out).unwrap();
        assert!(text.starts_with("HTTP/1.1 200 OK"), "version must be normalized");
        assert!(text.contains("Connection: keep-alive"));
        assert!(!text.contains("Connection: close"));

        // HTTP/1.1 responses pass through byte-identical (keep-alive).
        let raw = b"HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n";
        let out = normalize_response_for_client(raw, true);
        assert_eq!(out, raw.to_vec());
    }

    // ---- upstream response termination ----

    #[test]
    fn detects_end_by_content_length() {
        let raw = b"HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello";
        assert_eq!(detect_response_end(raw), Some(raw.len()));
        // body shorter than Content-Length -> end not reached yet
        assert_eq!(detect_response_end(&raw[..raw.len() - 4]), None);
    }

    #[test]
    fn detects_end_of_chunked_response() {
        let raw = b"HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n\
5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n";
        assert_eq!(detect_response_end(raw), Some(raw.len()));
        assert_eq!(
            detect_response_end(&raw[..raw.len() - 3]),
            None,
            "truncated last chunk must not count as finished"
        );
    }

    #[test]
    fn chunked_with_extensions_and_trailer() {
        let raw = b"HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n\
4;ext=1\r\nwiki\r\n0\r\nX-Trailer: v\r\n\r\n";
        assert_eq!(detect_response_end(raw), Some(raw.len()));
    }

    #[test]
    fn no_framing_means_eof_semantics() {
        let raw = b"HTTP/1.1 200 OK\r\n\r\nwhatever";
        assert_eq!(detect_response_end(raw), None);
    }

    // ---- upstream response head parsing ----

    #[test]
    fn parses_h1_response_head() {
        let raw = b"HTTP/1.1 201 Created\r\nX-Backend: fast\r\nContent-Length: 2\r\n\r\nhi";
        let (code, headers, body_start) = parse_h1_response_head(raw).unwrap();
        assert_eq!(code, 201);
        assert_eq!(headers[0].0, "X-Backend");
        assert_eq!(&raw[body_start..], b"hi");
    }

    #[test]
    fn parses_h1_response_head_rejects_garbage() {
        assert!(parse_h1_response_head(b"not http at all").is_none());
    }

    #[test]
    fn status_label_buckets() {
        assert_eq!(status_label(200), "2xx_3xx");
        assert_eq!(status_label(302), "2xx_3xx");
        assert_eq!(status_label(404), "4xx");
        assert_eq!(status_label(502), "5xx");
    }

    // ---- ALPN ----

    #[test]
    fn alpn_offers_h2_and_http11() {
        let cfg = create_tls_config();
        assert_eq!(cfg.alpn_protocols, vec![b"h2".to_vec(), b"http/1.1".to_vec()]);
    }

    // ---- h2 -> h1 request encoding ----

    #[test]
    fn h2_request_is_reencoded_as_h1() {
        let mut headers = HeaderMap::new();
        headers.insert("host", "example.com".parse().unwrap());
        headers.insert("user-agent", "h2-client".parse().unwrap());
        headers.insert("x-forwarded-for", "spoofed".parse().unwrap());
        let out = build_h1_request_from_parts(
            &http::Method::POST,
            "/api?x=1",
            &headers,
            b"payload",
            &[],
        );
        let text = String::from_utf8(out).unwrap();
        assert!(text.starts_with("POST /api?x=1 HTTP/1.1\r\n"));
        assert!(text.contains("Host: example.com"));
        assert!(text.contains("User-Agent: h2-client"));
        // hop-by-hop / framing headers must not leak through
        assert!(!text.to_ascii_lowercase().contains("transfer-encoding"));
        // forwarded headers are replaced, not duplicated
        assert_eq!(text.matches("X-Forwarded-For").count(), 1);
        assert!(text.contains("X-Forwarded-For: client"));
        assert!(text.contains("X-Request-Id: req-"));
        // body framing is exact
        assert!(text.contains("Content-Length: 7\r\n"));
        assert!(text.ends_with("\r\n\r\npayload"));
    }

    #[test]
    fn title_cases_header_names() {
        assert_eq!(title_case_header("content-type"), "Content-Type");
        assert_eq!(title_case_header("x-request-id"), "X-Request-Id");
        assert_eq!(title_case_header("a"), "A");
        assert_eq!(title_case_header(""), "");
    }

    // ---- HTTP/2 end-to-end over an in-memory pipe ----

    #[tokio::test]
    async fn h2_end_to_end_on_duplex() {
        let (client_io, server_io) = tokio::io::duplex(65536);

        // Mini h2 "server": answers every stream with 200 + X-Backend + body.
        tokio::spawn(async move {
            let mut conn = h2::server::handshake(server_io).await.unwrap();
            while let Some(Ok((_request, mut respond))) = conn.accept().await {
                let response = http::Response::builder()
                    .status(200)
                    .header("x-backend", "h2-test")
                    .body(())
                    .unwrap();
                let mut send = respond.send_response(response, false).unwrap();
                send.send_data(Bytes::from_static(b"h2-ok"), true).unwrap();
            }
        });

        let (mut client, h2conn) = h2::client::handshake(client_io).await.unwrap();
        tokio::spawn(async move {
            let _ = h2conn.await;
        });

        let request = http::Request::builder()
            .method(http::Method::GET)
            .uri("https://localhost/healthz")
            .body(())
            .unwrap();
        let (response, _body_rx) = client.send_request(request, true).unwrap();
        let resp = response.await.unwrap();
        assert_eq!(resp.status(), 200);
        assert_eq!(resp.headers().get("x-backend").unwrap(), "h2-test");

        let mut body = resp.into_body();
        let chunk = body.data().await.unwrap().unwrap();
        assert_eq!(&chunk[..], b"h2-ok");
    }

    // ---- Least Connections balancer ----

    #[test]
    fn balancer_prefers_least_loaded() {
        let bal = LeastConnectionsBalancer::new();
        let mut routes = HashMap::new();
        routes.insert(
            "/api".into(),
            RouteConfig {
                upstream_addrs: vec!["a:1".into(), "b:1".into()],
                wasm_plugin: None,
                security: SecurityConfig::default(),
                static_dir: None,
            },
        );
        bal.update_registry(&routes);

        // Load the FIRST upstream directly through the registry (no random
        // selection involved), then verify the balancer prefers the other.
        let registry = bal.connections.load();
        let a_counter = registry.get("a:1").unwrap().clone();
        drop(registry);
        a_counter.fetch_add(2, Ordering::SeqCst); // a:1 now has 2 active

        let (addr2, _) = bal
            .select_upstream(&routes["/api"].upstream_addrs)
            .unwrap();
        assert_eq!(addr2, "b:1", "pick must go to the idle upstream");
    }

    #[test]
    fn balancer_preserves_counters_across_reload() {
        let bal = LeastConnectionsBalancer::new();
        let mut cfg1 = HashMap::new();
        cfg1.insert(
            "/api".into(),
            RouteConfig { upstream_addrs: vec!["a:1".into()], wasm_plugin: None, security: SecurityConfig::default(), static_dir: None },
        );
        bal.update_registry(&cfg1);
        let (_, c) = bal.select_upstream(&["a:1".to_string()]).unwrap();
        c.fetch_add(7, Ordering::SeqCst);

        let mut cfg2 = HashMap::new();
        cfg2.insert(
            "/api".into(),
            RouteConfig {
                upstream_addrs: vec!["a:1".into(), "b:1".into()],
                wasm_plugin: None,
                security: SecurityConfig::default(),
                static_dir: None,
            },
        );
        bal.update_registry(&cfg2);

        let registry = bal.connections.load();
        let a_counter = registry.get("a:1").unwrap();
        assert_eq!(
            a_counter.load(Ordering::SeqCst),
            7,
            "counter of surviving upstream must persist"
        );

        let picked = bal
            .select_upstream(&["a:1".to_string(), "b:1".to_string()])
            .unwrap();
        assert_eq!(picked.0, "b:1");
    }

    // ---- Config hot reload via Control Plane logic ----

    #[tokio::test]
    async fn control_plane_swaps_config_atomically() {
        let shared = Arc::new(ArcSwap::from_pointee(ServerConfig::default()));
        let file = std::env::temp_dir().join("juggler_test_routes_ok.json");
        let cp = MyControlPlane::new(shared.clone(), file.to_string_lossy().to_string());

        let mut routes = HashMap::new();
        routes.insert(
            "/api".into(),
            PbRouteConfig {
                upstream_addrs: vec!["127.0.0.1:7001".into()],
                wasm_plugin: None,
                security: None,
            },
        );
        let resp = cp
            .update_config(Request::new(UpdateConfigRequest { routes }))
            .await
            .unwrap();
        assert!(resp.into_inner().success);

        let snapshot = shared.load();
        assert!(snapshot.routes.contains_key("/api"));

        // The config must also be persisted.
        let persisted: ServerConfig =
            serde_json::from_str(&std::fs::read_to_string(&file).unwrap()).unwrap();
        assert!(persisted.routes.contains_key("/api"));
        let _ = std::fs::remove_file(&file);
    }

    #[tokio::test]
    async fn control_plane_rejects_invalid_routes() {
        let shared = Arc::new(ArcSwap::from_pointee(ServerConfig::default()));
        let cp = MyControlPlane::new(
            shared.clone(),
            std::env::temp_dir()
                .join("juggler_test_routes_bad.json")
                .to_string_lossy()
                .to_string(),
        );

        let mut bad = HashMap::new();
        bad.insert(
            "api".into(),
            PbRouteConfig { upstream_addrs: vec!["x:1".into()], wasm_plugin: None, security: None },
        );
        assert!(cp
            .update_config(Request::new(UpdateConfigRequest { routes: bad }))
            .await
            .is_err());

        let mut empty = HashMap::new();
        empty.insert(
            "/api".into(),
            PbRouteConfig { upstream_addrs: vec![], wasm_plugin: None, security: None },
        );
        assert!(cp
            .update_config(Request::new(UpdateConfigRequest { routes: empty }))
            .await
            .is_err());
    }

    // ---- WASM plugin pipeline ----

    /// Compile WAT source into a wasmtime module (test helper).
    fn wat_module(wat_src: &str) -> Arc<wasmtime::Module> {
        let binary = wat::parse_str(wat_src).expect("WAT parse failed");
        Arc::new(
            wasmtime::Module::from_binary(wasm_engine(), &binary)
                .expect("module compile failed"),
        )
    }

    const HEADER_PLUGIN: &str = r#"
        (module
          (import "juggler" "add_header"
            (func $add_header (param i32 i32 i32 i32) (result i32)))
          ;; 17 pages: the fixed ABI layout (path window at 1 MiB, scratch
          ;; behind it) must fit the module's own declared memory, because
          ;; data segments are applied at instantiation, before the host
          ;; can grow anything.
          (memory (export "memory") 17)
          (data (i32.const 1060000) "X-Wasm-Test")
          (data (i32.const 1060040) "works")
          (func (export "on_request")
            (drop (call $add_header
              (i32.const 1060000) (i32.const 11)
              (i32.const 1060040) (i32.const 5))))
          (func (export "transform_body") (param i32 i32) (result i32)
            (i32.const -1))
        )
    "#;

    const UPPER_PLUGIN: &str = r#"
        (module
          (import "juggler" "alloc" (func $alloc (param i32) (result i32)))
          (memory (export "memory") 17)
          (func (export "transform_body") (param $ptr i32) (param $len i32) (result i32)
            (local $out i32) (local $i i32) (local $b i32)
            (local.set $out (call $alloc (i32.add (i32.const 4) (local.get $len))))
            (if (i32.eqz (local.get $out)) (then (return (i32.const -1))))
            (i32.store (local.get $out) (local.get $len))
            (block $done
              (loop $again
                (br_if $done (i32.ge_u (local.get $i) (local.get $len)))
                (local.set $b (i32.load8_u (i32.add (local.get $ptr) (local.get $i))))
                (if (i32.and (i32.ge_u (local.get $b) (i32.const 97))
                             (i32.le_u (local.get $b) (i32.const 122)))
                  (then (local.set $b (i32.sub (local.get $b) (i32.const 32)))))
                (i32.store8
                  (i32.add (i32.add (local.get $out) (i32.const 4)) (local.get $i))
                  (local.get $b))
                (local.set $i (i32.add (local.get $i) (i32.const 1)))
                (br $again)))
            (local.get $out))
        )
    "#;

    const PATH_PLUGIN: &str = r#"
        (module
          (import "juggler" "path" (func $path (result i32)))
          (import "juggler" "add_header"
            (func $add_header (param i32 i32 i32 i32) (result i32)))
          (memory (export "memory") 17)
          (data (i32.const 1060000) "X-Path-Len")
          (func (export "on_request")
            (drop (call $add_header
              (i32.const 1060000) (i32.const 10)
              (i32.const 1052672) (call $path))))
          (func (export "transform_body") (param i32 i32) (result i32)
            (i32.const -1))
        )
    "#;

    const TRAP_PLUGIN: &str = r#"
        (module
          (memory (export "memory") 17)
          (func (export "on_request")
            (unreachable))
        )
    "#;

    const INFINITE_LOOP_PLUGIN: &str = r#"
        (module
          (memory (export "memory") 17)
          (func (export "on_request")
            (loop $forever (br $forever)))
        )
    "#;

    #[test]
    fn example_wat_plugins_compile() {
        // CI-style contract: the shipped example plugins in plugins/ must
        // stay valid WAT. (The committed .wasm files are regenerated by
        // `cargo test` into target/, so this also guards ABI drift.)
        for name in ["add_header", "upper_body"] {
            let src = std::fs::read_to_string(format!("plugins/{name}.wat")).unwrap();
            wat::parse_str(&src).unwrap_or_else(|e| panic!("{name}.wat is invalid: {e}"));
        }
    }

    #[test]
    fn wasm_add_header_plugin_injects_header() {
        let module = wat_module(HEADER_PLUGIN);
        let mut body = b"hello".to_vec();
        let headers = run_plugin_filters(&Some(module), "/api", &mut body).unwrap();
        assert_eq!(headers, vec![("X-Wasm-Test".to_string(), "works".to_string())]);
        assert_eq!(body, b"hello"); // body untouched (transform returned -1)
    }

    #[test]
    fn wasm_body_transform_uppercases() {
        let module = wat_module(UPPER_PLUGIN);
        let mut body = b"juggler keeps the balls in the air".to_vec();
        let headers = run_plugin_filters(&Some(module), "/api", &mut body).unwrap();
        assert!(headers.is_empty());
        assert_eq!(body, b"JUGGLER KEEPS THE BALLS IN THE AIR");
    }

    #[test]
    fn wasm_plugin_sees_request_path() {
        // The plugin calls juggler.path() and attaches the path string it
        // received as a header value, proving host -> plugin data flow.
        let module = wat_module(PATH_PLUGIN);
        let mut body = Vec::new();
        let headers = run_plugin_filters(&Some(module), "/api/items", &mut body).unwrap();
        assert_eq!(
            headers,
            vec![("X-Path-Len".to_string(), "/api/items".to_string())]
        );
    }

    #[test]
    fn wasm_trap_fails_closed() {
        let module = wat_module(TRAP_PLUGIN);
        let mut body = b"data".to_vec();
        assert!(run_plugin_filters(&Some(module), "/api", &mut body).is_err());
    }

    #[test]
    fn wasm_infinite_loop_is_fuel_capped() {
        let module = wat_module(INFINITE_LOOP_PLUGIN);
        let mut body = b"data".to_vec();
        let started = std::time::Instant::now();
        let r = run_plugin_filters(&Some(module), "/api", &mut body);
        assert!(r.is_err(), "infinite loop must trap on fuel exhaustion");
        assert!(started.elapsed() < std::time::Duration::from_secs(5));
    }

    #[test]
    fn wasm_no_plugin_is_fail_open_noop() {
        let mut body = b"unchanged".to_vec();
        let headers = run_plugin_filters(&None, "/api", &mut body).unwrap();
        assert!(headers.is_empty());
        assert_eq!(body, b"unchanged");
    }

    #[test]
    fn wasm_oversized_body_rejected_without_invocation() {
        let module = wat_module(UPPER_PLUGIN);
        let mut body = vec![b'a'; MAX_PLUGIN_BODY + 1];
        assert!(run_plugin_filters(&Some(module), "/api", &mut body).is_err());
    }

    #[test]
    fn forwarded_request_gets_plugin_header_and_new_length() {
        // h1 path: a rewritten body must produce an updated Content-Length
        // and the plugin header must be appended before the head terminator.
        let raw = b"POST /api HTTP/1.1\r\nHost: x\r\nContent-Length: 3\r\n\r\nabc";
        let req = parse_request(raw).unwrap();
        let body = b"abcd".to_vec();
        let extra = vec![("X-Plugin".to_string(), "1".to_string())];
        let out = build_forwarded_request(&req, raw, &body, &extra);
        let parsed = parse_request(&out).unwrap();
        assert_eq!(parsed.content_length(), 4);
        assert!(out.windows(11).any(|w| w == b"X-Plugin: 1"));
        // Body starts at the parsed offset of the NEW request (headers were
        // inserted, so it moved relative to the original).
        assert_eq!(&out[parsed.body_offset..], b"abcd");
    }

    #[test]
    fn replace_content_length_rewrites_value() {
        let raw = b"POST /x HTTP/1.1\r\nContent-Length: 10\r\n\r\n".to_vec();
        let out = replace_content_length(raw, 42);
        let s = String::from_utf8(out).unwrap();
        assert!(s.contains("Content-Length: 42\r\n"), "got: {s}");
    }

    // ---- upstream health tracker ---------------------------------------

    fn fast_policy() -> HealthPolicy {
        HealthPolicy { max_failures: 3, cooldown: Duration::from_millis(50) }
    }

    #[test]
    fn health_marks_unhealthy_after_n_failures() {
        let t = HealthTracker::with_policy(fast_policy());
        assert!(t.accepts("a:1"));
        t.record("a:1", false);
        t.record("a:1", false);
        assert!(t.accepts("a:1"), "below threshold must stay healthy");
        t.record("a:1", false);
        assert!(!t.accepts("a:1"), "3rd consecutive failure must mark unhealthy");
        // A success resets the consecutive-failure counter.
        let t2 = HealthTracker::with_policy(fast_policy());
        t2.record("a:1", false);
        t2.record("a:1", false);
        t2.record("a:1", true);
        t2.record("a:1", false);
        t2.record("a:1", false);
        assert!(t2.accepts("a:1"), "success must reset the failure streak");
    }

    #[test]
    fn health_recovers_via_half_open_probe() {
        let t = HealthTracker::with_policy(fast_policy());
        for _ in 0..3 {
            t.record("a:1", false);
        }
        assert!(!t.accepts("a:1"));
        std::thread::sleep(Duration::from_millis(60)); // cooldown 50ms
        // Cooldown expired: exactly one probe request is let through...
        assert!(t.accepts("a:1"), "cooldown expiry must allow a probe");
        assert!(!t.accepts("a:1"), "second request during half-open must wait");
        // ...and its failure re-opens the circuit immediately.
        t.record("a:1", false);
        assert!(!t.accepts("a:1"), "failed probe must re-open the circuit");

        // Recovery: next probe succeeds -> healthy again.
        std::thread::sleep(Duration::from_millis(60));
        assert!(t.accepts("a:1"));
        t.record("a:1", true);
        assert!(t.accepts("a:1"), "successful probe must close the circuit");
    }

    #[test]
    fn health_retain_drops_removed_upstreams() {
        let t = HealthTracker::with_policy(fast_policy());
        for _ in 0..3 {
            t.record("a:1", false);
        }
        for _ in 0..3 {
            t.record("b:2", false);
        }
        t.retain(["a:1".to_string()].into_iter());
        // a:1 keeps its (unhealthy) history, b:2 is forgotten entirely.
        assert!(!t.accepts("a:1"));
        assert!(t.accepts("b:2"), "removed upstream must be forgotten, not kept");
    }

    // ---- Security layer (deny patterns + response headers) ----

    fn sec_config() -> Arc<ArcSwap<ServerConfig>> {
        let mut route = RouteConfig {
            upstream_addrs: vec!["10.0.0.1:80".into()],
            wasm_plugin: None,
            security: SecurityConfig {
                deny_substrings: vec!["wp-config.php".into(), ".git".into()],
                response_headers: vec![
                    ("X-Frame-Options".into(), "DENY".into()),
                    ("X-Content-Type-Options".into(), "nosniff".into()),
                ],
                cms_fallback_path: Some("/cms".into()),
            },
            static_dir: None,
        };
        let cms = RouteConfig {
            upstream_addrs: vec!["10.0.0.2:80".into()],
            wasm_plugin: None,
            security: SecurityConfig::default(),
            static_dir: None,
        };
        let mut routes = HashMap::new();
        routes.insert("/api".to_string(), route.clone());
        routes.insert("/cms".to_string(), cms);
        route.upstream_addrs.clear(); // an empty route only carries policy
        routes.insert("/policy".to_string(), route);
        Arc::new(ArcSwap::from_pointee(ServerConfig { routes }))
    }

    fn sec_resolve(path: &str, body: &mut Vec<u8>) -> RouteDecision {
        resolve_route(path, body, "203.0.113.7".parse().unwrap(), PROTO_H1, &sec_config())
    }

    #[test]
    fn security_deny_blocks_dotfiles_and_config() {
        for path in [
            "/wp-config.php",
            "/wp-admin/wp-config.php",
            "/.git/config",
            "/WP-CONFIG.PHP", // case-insensitive
        ] {
            let mut body = Vec::new();
            match sec_resolve(path, &mut body) {
                RouteDecision::Builtin(403, _) => {}
                other => panic!("{path} must be denied, got {other:?}"),
            }
        }
    }

    #[test]
    fn security_headers_arrive_on_proxy_decision() {
        let mut body = Vec::new();
        match sec_resolve("/api", &mut body) {
            RouteDecision::Proxy(_, _, _, hdrs) => {
                assert!(hdrs.contains(&("X-Frame-Options".to_string(), "DENY".to_string())));
                assert!(hdrs.contains(&("X-Content-Type-Options".to_string(), "nosniff".to_string())));
            }
            other => panic!("expected proxy, got {other:?}"),
        }
    }

    #[test]
    fn with_security_headers_injects_and_replaces() {
        let base = b"HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nX-Frame-Options: SAMEORIGIN\r\n\r\nbody".to_vec();
        let out = String::from_utf8(with_security_headers(
            base,
            &[("X-Frame-Options".to_string(), "DENY".to_string())],
        ))
        .unwrap();
        assert!(out.contains("X-Frame-Options: DENY"), "existing value replaced: {out}");
        assert!(!out.contains("SAMEORIGIN"));
        assert!(out.ends_with("\r\n\r\nbody"), "head/body boundary intact: {out}");
    }

    #[test]
    fn cms_fallback_rewrites_unknown_paths() {
        let mut body = Vec::new();
        match sec_resolve("/some/pretty/url", &mut body) {
            // Unknown path -> falls through to the /cms front controller.
            RouteDecision::Proxy(upstreams, _, _, hdrs) => {
                assert_eq!(upstreams, vec!["10.0.0.2:80".to_string()]);
                assert!(hdrs.is_empty(), "fallback target has no security headers");
            }
            other => panic!("expected fallback proxy, got {other:?}"),
        }
    }

    #[test]
    fn route_without_fallback_still_404s() {
        // /metrics is builtin; an unknown path on a config WITHOUT a fallback
        // must stay NotFound. Build a minimal config without cms_fallback.
        let cfg = Arc::new(ArcSwap::from_pointee(ServerConfig {
            routes: HashMap::new(),
        }));
        let mut body = Vec::new();
        match resolve_route("/nope", &mut body, "127.0.0.1".parse().unwrap(), PROTO_H1, &cfg) {
            RouteDecision::NotFound => {}
            other => panic!("expected NotFound, got {other:?}"),
        }
    }

    // ---- JUGGLER_REDIRECT_HTTP: Location building (pure part, extracted
    // from handle_redirect_connection for testability) ----

    #[test]
    fn redirect_location_dev_port() {
        // Host without port + non-443 TLS port → port is appended.
        let loc = redirect_location("example.dev", "/admin/login?next=/dash", 8443);
        assert_eq!(loc, "https://example.dev:8443/admin/login?next=/dash");
    }

    #[test]
    fn redirect_location_prod_443() {
        let loc = redirect_location("example.com", "/wp-admin", 443);
        assert_eq!(loc, "https://example.com/wp-admin");
    }

    #[test]
    fn redirect_location_host_port_is_stripped() {
        // Host carries the PLAIN redirect port (client's port) — must be
        // stripped and replaced by the TLS port, or the redirect loops.
        let loc = redirect_location("127.0.0.1:8090", "/wp-admin?x=1", 8443);
        assert_eq!(loc, "https://127.0.0.1:8443/wp-admin?x=1");
    }

    #[test]
    fn redirect_location_no_host_falls_back() {
        let loc = redirect_location("", "/x", 8443);
        assert_eq!(loc, "https://127.0.0.1:8443/x");
    }

    // ---- Shard telemetry: Holt forecast ----

    #[test]
    fn holt_constant_series_stays_flat() {
        let mut state = (0.0, 0.0);
        for _ in 0..20 {
            state = holt_forecast(state.0, state.1, 10.0);
        }
        let (level, trend) = state;
        // Holt overshoots a step input transiently (alpha*level + beta*trend
        // ringing); after 20 steps it must have settled near 10.
        assert!((level - 10.0).abs() < 1.0, "level must converge to ~10, got {level}");
        assert!(trend.abs() < 0.5, "flat series must have ~0 trend, got {trend}");
    }

    #[test]
    fn holt_rising_series_has_positive_trend() {
        let mut state = (0.0, 0.0);
        for x in 0..30 {
            state = holt_forecast(state.0, state.1, x as f64);
        }
        assert!(state.1 > 0.5, "rising series must produce positive trend, got {}", state.1);
    }

    #[test]
    fn holt_forecast_extrapolates_beyond_last_sample() {
        // After a rising ramp, forecast (level+trend) must exceed the level.
        let mut state = (0.0, 0.0);
        for x in 0..25 {
            state = holt_forecast(state.0, state.1, x as f64);
        }
        assert!(state.0 + state.1 > state.0);
    }

    #[test]
    fn update_shard_stats_resizes_and_folds() {
        update_shard_stats(&[5, 0, 7]);
        let snap = shard_stats_snapshot();
        // Snapshot is driven by shard_counters() (empty in unit tests), but
        // update must not panic and the state vector must resize.
        let st = shard_holt_state().lock().unwrap();
        assert_eq!(st.len(), 3);
    }

    // ---- Predictive balancer ----

    #[test]
    fn predictive_balancer_prefers_falling_upstream() {
        let b = LeastConnectionsBalancer::new();
        b.update_registry(&HashMap::from([(
            "/".to_string(),
            crate::RouteConfig {
                upstream_addrs: vec!["a:1".to_string(), "b:2".to_string()],
                ..Default::default()
            },
        )]));
        // Equal current load (5 vs 5)...
        for _ in 0..5 {
            b.connections.load()["a:1"].fetch_add(1, Ordering::Relaxed);
            b.connections.load()["b:2"].fetch_add(1, Ordering::Relaxed);
        }
        // ...but a:1 drains (30→11) while b:2 fills up (1→20): the forecast
        // must pick the draining upstream even though live counters tie.
        for i in 0..20 {
            b.update_forecast("a:1", 30 - i);
            b.update_forecast("b:2", 1 + i);
        }
        let (picked, _) = b.select_upstream_predictive(&["a:1".into(), "b:2".into()]).unwrap();
        assert_eq!(picked, "a:1", "falling upstream must win the forecast");
    }

    #[test]
    fn settings_knobs_clamp_and_apply() {
        use std::collections::HashMap as Hm;
        // Valid: balancer as string, numbers clamped into range.
        let mut up: Hm<String, serde_json::Value> = Hm::new();
        up.insert("balancer".into(), serde_json::json!("predictive"));
        up.insert("pool_cap_per_upstream".into(), serde_json::json!(999_999)); // > max -> clamped
        up.insert("rate_limit_tokens".into(), serde_json::json!(0)); // 0 = disabled
        let applied = apply_settings_update(&up).unwrap();
        assert_eq!(applied, 3);
        assert_eq!(settings().balancer.get(), BALANCER_PREDICTIVE);
        assert_eq!(
            settings().pool_cap_per_upstream.get(),
            settings().pool_cap_per_upstream.max
        );
        assert_eq!(settings().rate_limit_tokens.get(), 0);

        // Unknown knob is rejected.
        let mut bad: Hm<String, serde_json::Value> = Hm::new();
        bad.insert("no_such_knob".into(), serde_json::json!(1));
        assert!(apply_settings_update(&bad).is_err());

        // adaptive_prewarm is a bool knob.
        let mut aw: Hm<String, serde_json::Value> = Hm::new();
        aw.insert("adaptive_prewarm".into(), serde_json::json!(false));
        assert!(apply_settings_update(&aw).is_ok());
        assert!(!settings().adaptive_prewarm.load(Ordering::Relaxed));
        aw.insert("adaptive_prewarm".into(), serde_json::json!(true));
        let _ = apply_settings_update(&aw);
    }

    // ---- Signature oracle ----

    #[test]
    fn signature_buckets_numeric_segments_and_body() {
        // Same pattern, different ids -> SAME signature (table does not explode).
        let a = request_signature_hash(b"GET", "/users/42/profile", 0);
        let b = request_signature_hash(b"GET", "/users/7/profile", 0);
        assert_eq!(a, b, "numeric segments must collapse to :id");

        // Mixed segment stays literal (v1 vs v2 are genuinely different routes).
        let v1 = request_signature_hash(b"GET", "/api/v1/x", 0);
        let v2 = request_signature_hash(b"GET", "/api/v2/x", 0);
        assert_ne!(v1, v2);

        // Method matters.
        let g = request_signature_hash(b"GET", "/submit", 0);
        let p = request_signature_hash(b"POST", "/submit", 10);
        assert_ne!(g, p, "method+body class must be part of the signature");

        // Body size classes differ.
        let small = request_signature_hash(b"POST", "/up", 500);
        let big = request_signature_hash(b"POST", "/up", 100_000);
        assert_ne!(small, big);
    }

    #[test]
    fn signature_ema_learns_and_predicts() {
        // Unknown signature -> 45 µs default.
        let sig = request_signature_hash(b"GET", "/oracle-test-path", 0);
        // Record 10 samples of ~1 ms; EMA (k=1/8) must converge near it.
        for _ in 0..12 {
            sig_record(sig, 1_000_000);
        }
        let pred = sig_predict(sig);
        assert!(
            (900_000..=1_100_000).contains(&pred),
            "EMA must converge to ~1ms, got {pred}"
        );

        // A cost outlier moves the prediction up but not instantly to it.
        sig_record(sig, 10_000_000);
        let after = sig_predict(sig);
        assert!(after > pred, "slow sample must raise the EMA");
        assert!(after < 10_000_000, "one sample must not fully replace the EMA");
    }

    #[test]
    fn weighted_predictive_prefers_cheap_upstream_when_loaded() {
        let b = LeastConnectionsBalancer::new();
        b.update_registry(&HashMap::from([(
            "/".to_string(),
            crate::RouteConfig {
                upstream_addrs: vec!["a:1".to_string(), "b:2".to_string()],
                ..Default::default()
            },
        )]));
        // Equal live load.
        b.connections.load()["a:1"].store(3, Ordering::Relaxed);
        b.connections.load()["b:2"].store(3, Ordering::Relaxed);
        // With a huge predicted cost both scores tie -> still returns one.
        let (picked, _) = b
            .select_upstream_predictive_weighted(&["a:1".into(), "b:2".into()], 50_000_000)
            .unwrap();
        assert!(["a:1", "b:2"].contains(&picked.as_str()));

        // Unequal load: heavy request must prefer the emptier upstream.
        b.connections.load()["a:1"].store(0, Ordering::Relaxed);
        b.connections.load()["b:2"].store(6, Ordering::Relaxed);
        let (picked, _) = b
            .select_upstream_predictive_weighted(&["a:1".into(), "b:2".into()], 50_000_000)
            .unwrap();
        assert_eq!(picked, "a:1", "cost-weighted score must respect live load");
    }

    #[test]
    fn backpressure_watermarks_flip_with_hysteresis() {
        // Above high watermark -> ON.
        update_backpressure_state(PENDING_CONNS_MAX + 1);
        assert!(ACCEPT_BACKPRESSURE.load(Ordering::Acquire));

        // Between low and high -> stays ON (hysteresis, no thrash).
        update_backpressure_state(PENDING_CONNS_LOW + 10);
        assert!(ACCEPT_BACKPRESSURE.load(Ordering::Acquire));

        // Below low watermark -> OFF.
        update_backpressure_state(PENDING_CONNS_LOW - 1);
        assert!(!ACCEPT_BACKPRESSURE.load(Ordering::Acquire));
    }

    #[test]
    fn settings_json_contains_all_knobs() {
        let json = settings_json();
        for name in [
            "balancer",
            "rate_limit_tokens",
            "pool_cap_per_upstream",
            "max_keepalive_requests",
            "prewarm_floor",
            "health_cooldown_secs",
        ] {
            assert!(json.contains(name), "settings JSON missing {name}");
        }
    }

    #[test]
    fn predictive_falls_back_without_forecast() {
        let b = LeastConnectionsBalancer::new();
        b.update_registry(&HashMap::from([(
            "/".to_string(),
            crate::RouteConfig {
                upstream_addrs: vec!["a:1".to_string(), "b:2".to_string()],
                ..Default::default()
            },
        )]));
        b.connections.load()["a:1"].store(2, Ordering::Relaxed);
        b.connections.load()["b:2"].store(9, Ordering::Relaxed);
        // No forecast data: must behave like least-connections.
        let (picked, _) = b.select_upstream_predictive(&["a:1".into(), "b:2".into()]).unwrap();
        assert_eq!(picked, "a:1");
    }
}
