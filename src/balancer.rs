//! Load balancing: least-connections with random tie-break, the
//! predictive (Holt-forecast) variant, and the per-request-pattern
//! signature oracle (EMA of upstream cost) that weights predictive picks.

use std::collections::HashMap;
use std::sync::atomic::{AtomicI64, AtomicU64, AtomicUsize, Ordering};
use std::sync::{Arc, OnceLock};

use arc_swap::ArcSwap;
use lazy_static::lazy_static;

use rand::seq::SliceRandom;

use crate::telemetry::FcCell;
use crate::RouteConfig;

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
        let mut names: Vec<String> = self.connections.load().keys().cloned().collect();
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

    pub(crate) fn update_forecast(&self, addr: &str, sample: usize) {
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
    pub fn select_upstream_predictive(
        &self,
        addrs: &[String],
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
            *tied.choose(&mut rand::thread_rng()).unwrap()
        } else {
            best.as_ref().map(|(_, a, _)| *a)?
        };
        Some((picked.to_string(), registry.get(picked).unwrap().clone()))
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
            if n > 0 {
                (sum as f64 / n as f64, true)
            } else {
                (0.0, false)
            }
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
            *tied.choose(&mut rand::thread_rng()).unwrap()
        } else {
            best.as_ref().map(|(_, a, _)| *a)?
        };
        Some((picked.to_string(), registry.get(picked).unwrap().clone()))
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
pub(crate) fn balancer_mode() -> &'static str {
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
pub(crate) const SIG_TABLE_SIZE: usize = 256;

#[repr(align(64))]
pub(crate) struct SigSlot {
    /// EMA of upstream processing time, nanoseconds (0 = no data yet).
    ema_ns: AtomicU64,
}

pub(crate) static SIG_TABLE: OnceLock<Vec<SigSlot>> = OnceLock::new();

/// 0.125 smoothing: an EMA keeps recent cost changes (backend slowdowns
/// must be seen within ~8 samples of the same signature).
pub(crate) const SIG_EMA_SHIFT: u32 = 3;

pub(crate) fn sig_table() -> &'static [SigSlot] {
    SIG_TABLE.get_or_init(|| {
        (0..SIG_TABLE_SIZE)
            .map(|_| SigSlot {
                ema_ns: AtomicU64::new(0),
            })
            .collect()
    })
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
    let flush_seg = |byte: &mut dyn FnMut(u8), seg: &mut Vec<u8>| {
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
pub(crate) fn sig_record(sig: u64, elapsed_ns: u64) {
    let slot = &sig_table()[(sig as usize) % SIG_TABLE_SIZE];
    slot.ema_ns
        .fetch_update(Ordering::Relaxed, Ordering::Relaxed, |old| {
            Some(if old == 0 {
                elapsed_ns
            } else {
                (old * 7 + elapsed_ns) >> SIG_EMA_SHIFT
            })
        })
        .ok();
}

/// Predicted cost of a signature in ns; default 45 µs when unseen.
pub(crate) fn sig_predict(sig: u64) -> u64 {
    let slot = &sig_table()[(sig as usize) % SIG_TABLE_SIZE];
    let v = slot.ema_ns.load(Ordering::Relaxed);
    if v == 0 {
        45_000
    } else {
        v
    }
}
