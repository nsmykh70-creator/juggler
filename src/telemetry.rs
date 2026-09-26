//! Telemetry: shard load tracking with lock-free Holt-forecast cells,
//! the admin panel's response-class counters, RPS history ring, log ring,
//! and process RSS/CPU/uptime probes. Pure recording + snapshots; JSON
//! serialization lives with the admin API.

use std::collections::VecDeque;
use std::sync::atomic::{AtomicI64, AtomicU64, AtomicUsize, Ordering};
use std::sync::{Mutex, OnceLock};
use std::time::Instant;

use serde::Serialize;


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
pub(crate) struct ShardCounters {
    pub(crate) active_conns: AtomicUsize,
    pub(crate) accepted_total: AtomicUsize,
}

static SHARD_COUNTERS: OnceLock<Vec<ShardCounters>> = OnceLock::new();

/// Register N shard counter slots. Must be called before shard threads spawn.
pub(crate) fn init_shard_counters(n: usize) {
    let _ = SHARD_COUNTERS.set((0..n).map(|_| ShardCounters::default()).collect());
}

pub(crate) fn shard_counters() -> &'static [ShardCounters] {
    SHARD_COUNTERS.get().map(|v| v.as_slice()).unwrap_or(&[])
}

/// EMA smoothing factor and Holt trend factor.
const EMA_ALPHA: f64 = 0.3;
const TREND_BETA: f64 = 0.1;

/// Holt's linear trend over an EMA-smoothed series: level + trend, i.e. a
/// one-step-ahead forecast of the next sample. (level, trend) start at 0.
pub(crate) fn holt_forecast(prev_level: f64, prev_trend: f64, sample: f64) -> (f64, f64) {
    let level = EMA_ALPHA * sample + (1.0 - EMA_ALPHA) * (prev_level + prev_trend);
    let trend = TREND_BETA * (level - prev_level) + (1.0 - TREND_BETA) * prev_trend;
    (level, trend)
}

/// A single sample of one shard's load: current active connections plus the
/// Holt forecast of the next sample (>= current: load growing, <: falling).
#[derive(Debug, Clone, Serialize)]
pub(crate) struct ShardStat {
    pub(crate) shard: usize,
    pub(crate) active_conns: u64,
    pub(crate) accepted_total: u64,
    /// Smoothed level (EMA) of active connections.
    pub(crate) level: f64,
    /// Holt trend: positive = load growing, negative = draining.
    pub(crate) trend: f64,
    /// level + trend: predicted load one sample ahead.
    pub(crate) forecast: f64,
}

/// Shared (level, trend) per shard, folded by the sampler thread.
pub(crate) fn shard_holt_state() -> &'static Mutex<Vec<(f64, f64)>> {
    static STATE: OnceLock<Mutex<Vec<(f64, f64)>>> = OnceLock::new();
    STATE.get_or_init(|| Mutex::new(Vec::new()))
}

/// Fold the latest raw samples into the shared telemetry state.
pub(crate) fn update_shard_stats(samples: &[u64]) {
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
pub(crate) struct HistPoint {
    pub(crate) level: f64,
    pub(crate) forecast: f64,
}

pub(crate) const FORECAST_HISTORY_CAP: usize = 120;

fn forecast_history() -> &'static Mutex<Vec<std::collections::VecDeque<HistPoint>>> {
    static H: OnceLock<Mutex<Vec<std::collections::VecDeque<HistPoint>>>> = OnceLock::new();
    H.get_or_init(|| Mutex::new(Vec::new()))
}

/// Append one sample per shard to the history ring buffers (called by the
/// telemetry sampler every SHARD_SAMPLE_INTERVAL).
pub(crate) fn push_history_snapshot(shards: &[ShardStat]) {
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
pub(crate) fn history_snapshot() -> Vec<std::collections::VecDeque<HistPoint>> {
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
pub(crate) struct FcCell {
    pub(crate) level: AtomicI64,
    pub(crate) trend: AtomicI64,
    pub(crate) alpha: AtomicI64,
    pub(crate) beta: AtomicI64,
    /// Last raw sample folded in (for the admin panel).
    pub(crate) last_sample: AtomicI64,
}

impl FcCell {
    pub(crate) const fn zeroed() -> Self {
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
    pub(crate) fn fold(&self, sample: f64, sample_scale: f64) {
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
        let a2 = a.saturating_add((((FC_ADAPT_K as i128) * (err as i128 - a as i128)) >> 3) as i64);
        let b2 = b.saturating_add((((FC_ADAPT_K as i128) * (err as i128 - b as i128)) >> 3) as i64);
        self.level.store(level, Ordering::Relaxed);
        self.trend.store(trend, Ordering::Relaxed);
        self.alpha
            .store(a2.clamp(FC_ALPHA_MIN, FC_ALPHA_MAX), Ordering::Relaxed);
        self.beta
            .store(b2.clamp(FC_BETA_MIN, FC_BETA_MAX), Ordering::Relaxed);
        self.last_sample.store(s, Ordering::Relaxed);
    }

    /// One-step-ahead forecast, plain units.
    pub(crate) fn forecast(&self) -> f64 {
        let v = self.level.load(Ordering::Relaxed) + self.trend.load(Ordering::Relaxed);
        v as f64 / FC_ONE as f64
    }

    pub(crate) fn level_f(&self) -> f64 {
        self.level.load(Ordering::Relaxed) as f64 / FC_ONE as f64
    }

    pub(crate) fn trend_f(&self) -> f64 {
        self.trend.load(Ordering::Relaxed) as f64 / FC_ONE as f64
    }

    pub(crate) fn sample_f(&self) -> f64 {
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
pub(crate) struct ForecastCore {
    pub(crate) cells: Vec<FcCell>,
}

impl ForecastCore {
    pub(crate) fn new(n: usize) -> Self {
        Self {
            cells: (0..n).map(|_| FcCell::zeroed()).collect(),
        }
    }

    pub(crate) fn cell(&self, i: usize) -> Option<&FcCell> {
        self.cells.get(i)
    }
}

static SHARD_FC: OnceLock<ForecastCore> = OnceLock::new();

pub(crate) fn shard_fc() -> &'static ForecastCore {
    SHARD_FC.get_or_init(|| ForecastCore::new(shard_counters().len().max(num_cores_hint())))
}

/// Shard count is known before the counters are registered (main() knows
/// the core count first); fall back to the registered length otherwise.
fn num_cores_hint() -> usize {
    NUM_SHARDS
        .load(Ordering::Relaxed)
        .max(shard_counters().len())
        .max(1)
}

/// Set once in main() before init_shard_counters so the forecast core is
/// sized correctly on first use.
pub(crate) static NUM_SHARDS: AtomicUsize = AtomicUsize::new(0);

// ---- Per-stage request latency tracking (lock-free, per-core accumulate) ----

pub(crate) const STAGE_PARSE: usize = 0;
pub(crate) const STAGE_ROUTE: usize = 1;
pub(crate) const STAGE_UPSTREAM: usize = 2;
pub(crate) const STAGE_RESPOND: usize = 3;
pub(crate) const STAGE_COUNT: usize = 4;
pub(crate) const STAGE_NAMES: [&str; STAGE_COUNT] = ["read+parse", "route", "upstream", "respond"];
pub(crate) const LAT_RING_CAP: usize = 64; // powers of two

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
        #[allow(clippy::declare_interior_mutable_const)]
        const ZERO: AtomicU64 = AtomicU64::new(0);
        Self {
            count: [ZERO; STAGE_COUNT],
            total_ns: [ZERO; STAGE_COUNT],
            ring: [ZERO; STAGE_COUNT * LAT_RING_CAP],
            idx: AtomicUsize::new(0),
        }
    }

    #[inline]
    pub(crate) fn record(&self, stage: usize, ns: u64) {
        self.count[stage].fetch_add(1, Ordering::Relaxed);
        self.total_ns[stage].fetch_add(ns, Ordering::Relaxed);
        let i = self.idx.fetch_add(1, Ordering::Relaxed);
        self.ring[(stage * LAT_RING_CAP) + (i % LAT_RING_CAP)].store(ns, Ordering::Relaxed);
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
pub(crate) fn record_stage(shard: usize, stage: usize, started: Instant) {
    let Some(acc) = stage_accums().get(shard) else {
        return;
    };
    acc.record(stage, started.elapsed().as_nanos() as u64);
}

/// Fold all per-core accumulators into global stage totals (sampler call).
pub(crate) struct StageTotals {
    pub(crate) count: [u64; STAGE_COUNT],
    pub(crate) avg_us: [f64; STAGE_COUNT],
    pub(crate) p99_ms: [f64; STAGE_COUNT],
}

pub(crate) fn fold_stage_totals() -> StageTotals {
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
pub(crate) fn stage_totals_slot() -> &'static Mutex<Option<(Instant, StageTotals)>> {
    static S: OnceLock<Mutex<Option<(Instant, StageTotals)>>> = OnceLock::new();
    S.get_or_init(|| Mutex::new(None))
}

/// Snapshot for the admin UI / metrics exporter.
pub(crate) fn shard_stats_snapshot() -> Vec<ShardStat> {
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
// Admin panel telemetry: response-class counters, RPS history, log ring
// ============================================================================
//
// All counters are lock-free atomics on the data path (one relaxed add per
// response); the sampler folds them into a history ring and the admin API
// serializes snapshots. No allocation on the request path.

/// Response classes for the dashboard: 2xx, 3xx, 4xx, 5xx.
pub(crate) const RESP_CLASS_COUNT: usize = 4;
pub(crate) static RESP_CLASS_COUNTERS: [AtomicU64; RESP_CLASS_COUNT] = [
    AtomicU64::new(0),
    AtomicU64::new(0),
    AtomicU64::new(0),
    AtomicU64::new(0),
];
pub(crate) static RESP_429_COUNTER: AtomicU64 = AtomicU64::new(0);
pub(crate) static RESP_502_503_COUNTER: AtomicU64 = AtomicU64::new(0);

/// Cumulative totals (never reset): the sampler consumes the per-interval
/// counters via swap and re-accumulates here so /api/overview deltas work.
pub(crate) static RESP_CLASS_TOTALS: [AtomicU64; RESP_CLASS_COUNT] = [
    AtomicU64::new(0),
    AtomicU64::new(0),
    AtomicU64::new(0),
    AtomicU64::new(0),
];
pub(crate) static RESP_429_TOTAL: AtomicU64 = AtomicU64::new(0);
pub(crate) static RESP_5XX_TOTAL: AtomicU64 = AtomicU64::new(0);

/// One row of the RPS/latency history ring, appended by the sampler.
#[derive(Clone, Copy)]
pub(crate) struct PanelSample {
    pub(crate) at_ms: u64, // unix ms
    pub(crate) reqs: u64,  // requests completed in this interval
    pub(crate) classes: [u64; RESP_CLASS_COUNT],
    pub(crate) r429: u64,
    pub(crate) r5xx_gateway: u64,
    pub(crate) active_conns: u64,
    pub(crate) rss_kib: u64,
    pub(crate) cpu_permille: u32, // process CPU ‰ (0..1000 = 0..100%)
    pub(crate) p50_us: u64,
    pub(crate) p99_us: u64,
}

const PANEL_HISTORY_CAP: usize = 180;

fn panel_history() -> &'static Mutex<VecDeque<PanelSample>> {
    static H: OnceLock<Mutex<VecDeque<PanelSample>>> = OnceLock::new();
    H.get_or_init(|| Mutex::new(VecDeque::new()))
}

pub(crate) fn push_panel_sample(s: PanelSample) {
    let mut h = panel_history().lock().unwrap();
    if h.len() == PANEL_HISTORY_CAP {
        h.pop_front();
    }
    h.push_back(s);
}

pub(crate) fn panel_history_snapshot() -> Vec<PanelSample> {
    panel_history().lock().unwrap().iter().copied().collect()
}

pub(crate) fn resp_class_index(code: u16) -> Option<usize> {
    match code {
        200..=299 => Some(0),
        300..=399 => Some(1),
        400..=499 => Some(2),
        500..=599 => Some(3),
        _ => None,
    }
}

/// Record a finished response for the panel counters. Cheap enough to call
/// from every response path (H1 + H2).
pub(crate) fn record_response_class(code: u16) {
    if let Some(i) = resp_class_index(code) {
        RESP_CLASS_COUNTERS[i].fetch_add(1, Ordering::Relaxed);
    }
    if code == 429 {
        RESP_429_COUNTER.fetch_add(1, Ordering::Relaxed);
    }
    if code == 502 || code == 503 {
        RESP_502_503_COUNTER.fetch_add(1, Ordering::Relaxed);
    }
}

// ---- structured log ring (Логи page) ----

#[derive(Clone)]
pub(crate) struct LogEntry {
    pub(crate) at_ms: u64,
    pub(crate) level: &'static str, // "info" | "warn" | "error"
    pub(crate) scope: String,       // dataplane | upstream | control | admin | system
    pub(crate) message: String,
}

pub(crate) const LOG_RING_CAP: usize = 512;

fn log_ring() -> &'static Mutex<VecDeque<LogEntry>> {
    static L: OnceLock<Mutex<VecDeque<LogEntry>>> = OnceLock::new();
    L.get_or_init(|| Mutex::new(VecDeque::new()))
}

/// Push a panel log entry. Called from background paths only (health flips,
/// backpressure transitions, config reloads, admin auth failures, watchdog,
/// upstream errors) — never on the hot per-response path.
pub(crate) fn panel_log(level: &'static str, scope: &str, message: String) {
    let mut ring = log_ring().lock().unwrap();
    if ring.len() == LOG_RING_CAP {
        ring.pop_front();
    }
    ring.push_back(LogEntry {
        at_ms: now_unix_ms(),
        level,
        scope: scope.to_string(),
        message,
    });
}

pub(crate) fn log_ring_snapshot(limit: usize, level: Option<&str>, scope: Option<&str>) -> Vec<LogEntry> {
    let ring = log_ring().lock().unwrap();
    ring.iter()
        .rev() // newest first
        .filter(|e| level.is_none_or(|l| e.level == l))
        .filter(|e| scope.is_none_or(|s| e.scope == s))
        .take(limit)
        .cloned()
        .collect()
}

pub(crate) fn now_unix_ms() -> u64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_millis() as u64)
        .unwrap_or(0)
}

// ---- process resources (Система page): RSS + CPU of this process ----

#[cfg(target_family = "unix")]
pub(crate) fn process_rss_kib() -> u64 {
    // /proc/self/statm: size resident fields in pages.
    std::fs::read_to_string("/proc/self/statm")
        .ok()
        .and_then(|s| {
            s.split_whitespace()
                .nth(1)
                .and_then(|v| v.parse::<u64>().ok())
        })
        .map(|pages| pages * 4096 / 1024)
        .unwrap_or(0)
}

#[cfg(target_family = "windows")]
pub(crate) fn process_rss_kib() -> u64 {
    // Windows host runs are dev-only; the bench image is Linux. Report 0
    // rather than pulling in winapi: the UI hides the RSS row when 0.
    0
}

#[cfg(target_family = "unix")]
static PROCESS_CPU_SNAPSHOT: OnceLock<Mutex<(Instant, u64)>> = OnceLock::new();

#[cfg(target_family = "unix")]
fn cpu_snapshot() -> &'static Mutex<(Instant, u64)> {
    PROCESS_CPU_SNAPSHOT.get_or_init(|| Mutex::new((Instant::now(), 0)))
}

pub(crate) fn process_cpu_permille() -> u32 {
    // /proc/self/stat utime+stime in clock ticks (Linux only).
    #[cfg(target_family = "unix")]
    {
        let ticks = std::fs::read_to_string("/proc/self/stat")
            .ok()
            .and_then(|s| {
                // fields 14,15 (1-based) are utime, stime; field 2 (comm) can
                // contain spaces/parens — split after the last ')'.
                let after = s.rsplit_once(')').map(|(_, r)| r)?;
                let f: Vec<&str> = after.split_whitespace().collect();
                let u: u64 = f.get(11)?.parse().ok()?; // utime (index 11 after comm)
                let st: u64 = f.get(12)?.parse().ok()?; // stime
                Some(u + st)
            })
            .unwrap_or(0);
        let mut guard = cpu_snapshot().lock().unwrap();
        let (ref mut last_t, ref mut last_ticks) = *guard;
        let now = Instant::now();
        let dt = now.duration_since(*last_t).as_secs_f64();
        let permille = if dt >= 0.5 && *last_ticks > 0 && ticks >= *last_ticks {
            // 100 ticks/sec typical (CLK_TCK); ‰ of one core.
            (((ticks - *last_ticks) as f64) / dt * 1000.0 / 100.0) as u32
        } else {
            0
        };
        *last_t = now;
        *last_ticks = ticks;
        permille.min(100_000) // multi-core can exceed 1000‰
    }
    #[cfg(target_family = "windows")]
    {
        0
    }
}


