// Standalone Juggler scale-bench load generator: HTTP/1.1 keep-alive GETs
// against a static 1 KB file with a HARD request budget (not duration),
// N parallel workers. Built with plain rustc so a flaky toolchain cannot
// block the comparison run.
//
// Usage: scaleload <host> <port> <path> <conns> <total_requests>
// Prints one JSON line: {"ok","err","rps","p50_ms","p99_ms","bytes"}
use std::io::{Read, Write};
use std::net::TcpStream;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Instant;

static OK: AtomicUsize = AtomicUsize::new(0);
static ERR: AtomicUsize = AtomicUsize::new(0);
static BUDGET: AtomicUsize = AtomicUsize::new(0);

fn main() {
    let a: Vec<String> = std::env::args().collect();
    if a.len() < 6 {
        eprintln!("usage: scaleload <host> <port> <path> <conns> <total_requests>");
        std::process::exit(2);
    }
    let host = a[1].clone();
    let port: u16 = a[2].parse().expect("port");
    let path = a[3].clone();
    let conns: usize = a[4].parse().expect("conns");
    let total: usize = a[5].parse().expect("total");
    BUDGET.store(total, Ordering::Relaxed);

    let lat: Arc<Mutex<Vec<f64>>> = Arc::new(Mutex::new(Vec::with_capacity(total)));
    let started = Instant::now();
    let req = format!("GET {path} HTTP/1.1\r\nHost: {host}\r\n\r\n");

    let mut handles = Vec::new();
    for _ in 0..conns {
        let req = req.clone();
        let host = host.clone();
        let lat = lat.clone();
        handles.push(std::thread::spawn(move || {
            let mut buf = [0u8; 8192];
            'conn: while BUDGET.load(Ordering::Relaxed) > 0 {
                let mut s = match TcpStream::connect((host.as_str(), port)) {
                    Ok(s) => s,
                    Err(_) => {
                        ERR.fetch_add(1, Ordering::Relaxed);
                        continue;
                    }
                };
                let _ = s.set_nodelay(true);
                let mut reader = s.try_clone().expect("clone");
                'reqs: loop {
                    // Reserve one unit of the budget BEFORE sending: a plain
                    // fetch_sub after the fact can underflow when parallel
                    // workers drain the counter while this request is in
                    // flight (usize wraps to MAX and the run never ends).
                    let mut b = BUDGET.load(Ordering::Relaxed);
                    loop {
                        if b == 0 {
                            break 'conn;
                        }
                        match BUDGET.compare_exchange(b, b - 1, Ordering::Relaxed, Ordering::Relaxed) {
                            Ok(_) => break,
                            Err(x) => b = x,
                        }
                    }
                    let t0 = Instant::now();
                    if s.write_all(req.as_bytes()).is_err() {
                        ERR.fetch_add(1, Ordering::Relaxed);
                        continue 'conn;
                    }
                    // Read one full H1 response (headers + Content-Length body)
                    let mut hdr = Vec::with_capacity(512);
                    let mut one = [0u8; 1];
                    loop {
                        match reader.read(&mut one) {
                            Ok(0) | Err(_) => {
                                ERR.fetch_add(1, Ordering::Relaxed);
                                continue 'conn;
                            }
                            Ok(_) => {
                                hdr.push(one[0]);
                                if hdr.ends_with(b"\r\n\r\n") {
                                    break;
                                }
                            }
                        }
                    }
                    let head = String::from_utf8_lossy(&hdr).into_owned();
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
                    let mut body = vec![0u8; cl];
                    if reader.read_exact(&mut body).is_err() {
                        ERR.fetch_add(1, Ordering::Relaxed);
                        continue 'conn;
                    }
                    if !status_ok {
                        ERR.fetch_add(1, Ordering::Relaxed);
                        continue 'conn;
                    }
                    let ms = t0.elapsed().as_secs_f64() * 1000.0;
                    lat.lock().unwrap().push(ms);
                    OK.fetch_add(1, Ordering::Relaxed);
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
        "{{\"ok\":{},\"err\":{},\"rps\":{:.1},\"p50_ms\":{:.2},\"p99_ms\":{:.2}}}",
        OK.load(Ordering::Relaxed),
        ERR.load(Ordering::Relaxed),
        OK.load(Ordering::Relaxed) as f64 / elapsed,
        p(50.0),
        p(99.0)
    );
}
