// Standalone Juggler scale-bench load generator: HTTP/1.1 keep-alive GETs
// against a static 1 KB file with a HARD request budget (not duration),
// N parallel workers, optional pipelining depth.
// Built with plain rustc so a flaky toolchain cannot block the comparison run.
//
// Usage: scaleload <host> <port> <path> <conns> <total_requests> [depth]
//   depth = requests written back-to-back per batch (default 1 = no pipelining).
// Prints one JSON line: {"ok","err","rps","p50_ms","p99_ms","depth"}
use std::io::{Read, Write};
use std::net::TcpStream;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Instant;

static OK: AtomicUsize = AtomicUsize::new(0);
static ERR: AtomicUsize = AtomicUsize::new(0);
static BUDGET: AtomicUsize = AtomicUsize::new(0);

/// Reserve up to `want` budget units atomically; returns how many were
/// reserved (multi-unit CAS loop, the 1-unit case degenerates to the old one).
fn reserve(want: usize) -> usize {
    let mut b = BUDGET.load(Ordering::Relaxed);
    loop {
        if b == 0 {
            return 0;
        }
        let take = want.min(b);
        match BUDGET.compare_exchange(b, b - take, Ordering::Relaxed, Ordering::Relaxed) {
            Ok(_) => return take,
            Err(x) => b = x,
        }
    }
}

/// If `acc` holds a complete H1 response (head + Content-Length body),
/// return (consumed_bytes, status_was_2xx).
fn try_parse_response(acc: &[u8]) -> Option<(usize, bool)> {
    let head_end = acc.windows(4).position(|w| w == b"\r\n\r\n")? + 4;
    let head = std::str::from_utf8(&acc[..head_end]).ok()?;
    let status_ok = head.starts_with("HTTP/1.1 2") || head.starts_with("HTTP/1.0 2");
    let cl: usize = head
        .split("\r\n")
        .find_map(|l| {
            let (k, v) = l.split_once(':')?;
            if k.eq_ignore_ascii_case("content-length") {
                v.trim().parse().ok()
            } else {
                None
            }
        })
        .unwrap_or(0);
    let total = head_end + cl;
    if acc.len() < total {
        return None;
    }
    Some((total, status_ok))
}

fn main() {
    let a: Vec<String> = std::env::args().collect();
    if a.len() < 6 {
        eprintln!("usage: scaleload <host> <port> <path> <conns> <total_requests> [depth]");
        std::process::exit(2);
    }
    let host = a[1].clone();
    let port: u16 = a[2].parse().expect("port");
    let path = a[3].clone();
    let conns: usize = a[4].parse().expect("conns");
    let total: usize = a[5].parse().expect("total");
    let depth: usize = if a.len() > 6 {
        a[6].parse().expect("depth")
    } else {
        1
    };
    let depth = depth.max(1);
    BUDGET.store(total, Ordering::Relaxed);

    let lat: Arc<Mutex<Vec<f64>>> = Arc::new(Mutex::new(Vec::with_capacity(total)));
    let started = Instant::now();
    let req = format!("GET {path} HTTP/1.1\r\nHost: {host}\r\n\r\n");
    let batch = req.repeat(depth);

    let mut handles = Vec::new();
    for _ in 0..conns {
        let batch = batch.clone();
        let req = req.clone();
        let host = host.clone();
        let lat = lat.clone();
        handles.push(std::thread::spawn(move || {
            let mut buf = [0u8; 16384];
            'conn: while BUDGET.load(Ordering::Relaxed) > 0 {
                let mut s = match TcpStream::connect((host.as_str(), port)) {
                    Ok(s) => s,
                    Err(_) => {
                        ERR.fetch_add(1, Ordering::Relaxed);
                        continue;
                    }
                };
                let _ = s.set_nodelay(true);
                // A blocking read without a timeout can hang forever if a
                // peer stalls mid-response (observed with nginx-in-Docker on
                // reconnect storms). 15 s is far above any legit p99 here.
                let _ = s.set_read_timeout(Some(std::time::Duration::from_secs(15)));
                let mut reader = s.try_clone().expect("clone");
                let mut acc: Vec<u8> = Vec::with_capacity(batch.len() * 2);
                'reqs: loop {
                    // Reserve the whole batch BEFORE sending (underflow-safe).
                    let got = reserve(depth);
                    if got == 0 {
                        break 'conn;
                    }
                    let t0 = Instant::now();
                    // depth == 1 keeps the original single-request wire shape.
                    if s.write_all(if got == 1 { req.as_bytes() } else { &batch[..got * req.len()] })
                        .is_err()
                    {
                        ERR.fetch_add(1, Ordering::Relaxed);
                        continue 'conn;
                    }
                    // Parse `got` responses out of the accumulated bytes.
                    for _ in 0..got {
                        let resp = loop {
                            if let Some(r) = try_parse_response(&acc) {
                                break r;
                            }
                            match reader.read(&mut buf) {
                                Ok(0) | Err(_) => {
                                    ERR.fetch_add(1, Ordering::Relaxed);
                                    continue 'conn;
                                }
                                Ok(n) => acc.extend_from_slice(&buf[..n]),
                            }
                        };
                        acc.drain(..resp.0);
                        if !resp.1 {
                            ERR.fetch_add(1, Ordering::Relaxed);
                            continue;
                        }
                        let ms = t0.elapsed().as_secs_f64() * 1000.0;
                        lat.lock().unwrap().push(ms);
                        OK.fetch_add(1, Ordering::Relaxed);
                    }
                }
            }
        }));
    }
    for h in handles {
        let _ = h.join();
    }
    let elapsed = started.elapsed().as_secs_f64();
    let mut l = lat.lock().unwrap().clone();
    l.sort_by(|a, b| a.partial_cmp(b).unwrap());
    let p = |q: f64| -> f64 {
        if l.is_empty() {
            0.0
        } else {
            l[((l.len() as f64 * q / 100.0) as usize).min(l.len() - 1)]
        }
    };
    println!(
        "{{\"ok\":{},\"err\":{},\"rps\":{:.1},\"p50_ms\":{:.2},\"p99_ms\":{:.2},\"depth\":{}}}",
        OK.load(Ordering::Relaxed),
        ERR.load(Ordering::Relaxed),
        OK.load(Ordering::Relaxed) as f64 / elapsed,
        p(50.0),
        p(99.0),
        depth
    );
}
