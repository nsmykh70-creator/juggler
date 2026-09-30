//! Standalone Juggler load generator: h1 / h2 / h3 against the same TLS
//! endpoint (:8443, ALPN selects the protocol), N connections × S concurrent
//! in-flight streams per connection, duration-limited. Also `h1plain` (no
//! TLS) to cross-check against wrk on the plain port.
//!
//! Purpose: remove the Python event-loop bottleneck and measure the
//! server-side ceiling of each protocol plane with a single consistent
//! client.
//!
//! Usage (project root; cert.pem/key.pem in cwd for TLS modes):
//!   cargo run --release --bin h3load -- h3 127.0.0.1 8443 /healthz 256 10 4
//!   h3load <h1|h2|h3|h1plain> <host> <port> <path> <conns> <seconds> [streams]
//!
//! Output (single JSON line):
//!   {"mode","conns","streams","seconds","ok","err","rps","p50_ms","p99_ms"}

use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use std::time::{Duration, Instant};

use bytes::Buf;
use rustls_pki_types::{pem::PemObject, CertificateDer, ServerName};

type BoxErr = Box<dyn std::error::Error + Send + Sync>;

#[derive(Clone)]
struct Stats(Arc<Inner>);

struct Inner {
    ok: AtomicUsize,
    err: AtomicUsize,
}

impl Stats {
    fn new() -> Self {
        Self(Arc::new(Inner {
            ok: AtomicUsize::new(0),
            err: AtomicUsize::new(0),
        }))
    }
    /// Count a success and consume one unit of the request budget. The budget
    /// is `usize::MAX` when unlimited; worker loops stop once it hits 0.
    #[allow(dead_code)] // symmetric with ok_budgeted; h1/h1plain use ok()
    fn ok(&self) {
        self.0.ok.fetch_add(1, Ordering::Relaxed);
    }
    fn ok_budgeted(&self, budget: &AtomicUsize) {
        self.0.ok.fetch_add(1, Ordering::Relaxed);
        budget.fetch_sub(1, Ordering::Relaxed);
    }
    fn err(&self) {
        self.0.err.fetch_add(1, Ordering::Relaxed);
    }
    fn counts(&self) -> (usize, usize) {
        (
            self.0.ok.load(Ordering::Relaxed),
            self.0.err.load(Ordering::Relaxed),
        )
    }
}

fn percentile(v: &[f64], p: f64) -> f64 {
    if v.is_empty() {
        return 0.0;
    }
    let mut v = v.to_vec();
    v.sort_by(|a, b| a.partial_cmp(b).unwrap());
    let idx = ((v.len() as f64) * p / 100.0).min((v.len() - 1) as f64) as usize;
    (v[idx] * 100.0).round() / 100.0 // ms, 2 decimals
}

// ---------------------------------------------------------------- TLS setup --
fn client_tls(alpn: Option<&[u8]>) -> rustls::ClientConfig {
    let certs = CertificateDer::pem_file_iter("cert.pem")
        .expect("cert.pem in cwd (run from project root)")
        .collect::<Result<Vec<_>, _>>()
        .expect("valid cert.pem");
    let mut roots = rustls::RootCertStore::empty();
    for c in &certs {
        roots.add(c.clone()).ok();
    }
    let mut cfg = rustls::ClientConfig::builder()
        .with_root_certificates(roots)
        // Self-signed benchmark cert: the same material must be presented by
        // the server, so client auth is unnecessary — plain server-auth only.
        .with_no_client_auth();
    if let Some(a) = alpn {
        cfg.alpn_protocols = vec![a.to_vec()];
    }
    cfg
}

fn server_name(host: &str) -> ServerName<'static> {
    ServerName::try_from(host.to_string()).expect("server name")
}

/// Latency sample buffer owned by one connection task; merged at the end.
#[derive(Default)]
struct Local {
    lat_us: Vec<f64>,
}

impl Local {
    fn sample(&mut self, t0: Instant) {
        self.lat_us.push(t0.elapsed().as_secs_f64() * 1_000_000.0);
    }
}

// ---------------------------------------------------------------------- H1 --
/// One response on a keep-alive stream: status line + headers, then exactly
/// Content-Length bytes (the benchmark target answers with a fixed CL).
async fn read_h1_response<R: tokio::io::AsyncRead + Unpin>(s: &mut R) -> Result<(), BoxErr> {
    use tokio::io::AsyncReadExt;
    let mut head = Vec::with_capacity(256);
    let mut byte = [0u8; 1];
    // Headers are tiny (<100 B) and arrive in one segment; byte-wise scan of
    // the terminator keeps framing exact without a state machine.
    loop {
        let n = s.read(&mut byte).await?;
        if n == 0 {
            return Err("eof in head".into());
        }
        head.push(byte[0]);
        if head.ends_with(b"\r\n\r\n") {
            break;
        }
        if head.len() > 16 * 1024 {
            return Err("header too large".into());
        }
    }
    let head = String::from_utf8_lossy(&head);
    let status_ok = head.starts_with("HTTP/1.1 2") || head.starts_with("HTTP/1.0 2");
    if !status_ok {
        return Err(format!("status {}", head.lines().next().unwrap_or("")).into());
    }
    let cl: usize = head
        .lines()
        .find_map(|l| {
            let (k, v) = l.split_once(':')?;
            k.eq_ignore_ascii_case("content-length")
                .then(|| v.trim().parse().ok())?
        })
        .unwrap_or(0);
    let mut body = vec![0u8; cl];
    s.read_exact(&mut body).await?;
    Ok(())
}

async fn run_h1(
    host: &str,
    port: u16,
    path: &str,
    conns: usize,
    deadline: Instant,
    stats: Stats,
    budget: Arc<AtomicUsize>,
) {
    let tls = Arc::new(client_tls(None));
    let addr = format!("{host}:{port}");
    let host = host.to_string();
    let path = path.to_string();
    let mut handles = Vec::with_capacity(conns);
    for _ in 0..conns {
        let (tls, host, path, addr, stats, budget) = (
            tls.clone(),
            host.clone(),
            path.clone(),
            addr.clone(),
            stats.clone(),
            budget.clone(),
        );
        handles.push(tokio::spawn(async move {
            let mut local = Local::default();
            use tokio::io::AsyncWriteExt;
            let mut res: Result<(), BoxErr>;
            'conn: while Instant::now() < deadline && budget.load(Ordering::Relaxed) > 0 {
                res = async {
                    let tcp = tokio::net::TcpStream::connect(&addr).await?;
                    let connector = tokio_rustls::TlsConnector::from(tls.clone());
                    let mut s = connector.connect(server_name(&host), tcp).await?;
                    let req = format!("GET {path} HTTP/1.1\r\nHost: {host}\r\n\r\n");
                    while Instant::now() < deadline && budget.load(Ordering::Relaxed) > 0 {
                        let t0 = Instant::now();
                        s.write_all(req.as_bytes()).await?;
                        read_h1_response(&mut s).await?;
                        local.sample(t0);
                        stats.ok_budgeted(&budget);
                    }
                    Ok(())
                }
                .await;
                if let Err(ref e) = res {
                    if std::env::var("H3LOAD_DEBUG").is_ok() {
                        static ONCE: AtomicUsize = AtomicUsize::new(0);
                        if ONCE.fetch_add(1, Ordering::Relaxed) < 3 {
                            eprintln!("error: {e}");
                        }
                    }
                    stats.err();
                    tokio::time::sleep(Duration::from_millis(5)).await;
                } else {
                    break 'conn;
                }
            }
            local
        }));
    }
    for h in handles {
        if let Ok(l) = h.await {
            MERGED.with(|m| m.borrow_mut().lat_us.extend(l.lat_us));
        }
    }
}

async fn run_h1plain(
    host: &str,
    port: u16,
    path: &str,
    conns: usize,
    deadline: Instant,
    stats: Stats,
    budget: Arc<AtomicUsize>,
) {
    let addr = format!("{host}:{port}");
    let host = host.to_string();
    let path = path.to_string();
    let mut handles = Vec::with_capacity(conns);
    for _ in 0..conns {
        let (host, path, addr, stats, budget) = (
            host.clone(),
            path.clone(),
            addr.clone(),
            stats.clone(),
            budget.clone(),
        );
        handles.push(tokio::spawn(async move {
            let mut local = Local::default();
            use tokio::io::AsyncWriteExt;
            let mut res: Result<(), BoxErr>;
            'conn: while Instant::now() < deadline && budget.load(Ordering::Relaxed) > 0 {
                res = async {
                    let mut s = tokio::net::TcpStream::connect(&addr).await?;
                    let req = format!("GET {path} HTTP/1.1\r\nHost: {host}\r\n\r\n");
                    while Instant::now() < deadline && budget.load(Ordering::Relaxed) > 0 {
                        let t0 = Instant::now();
                        s.write_all(req.as_bytes()).await?;
                        read_h1_response(&mut s).await?;
                        local.sample(t0);
                        stats.ok_budgeted(&budget);
                    }
                    Ok(())
                }
                .await;
                if let Err(ref e) = res {
                    if std::env::var("H3LOAD_DEBUG").is_ok() {
                        static ONCE: AtomicUsize = AtomicUsize::new(0);
                        if ONCE.fetch_add(1, Ordering::Relaxed) < 3 {
                            eprintln!("error: {e}");
                        }
                    }
                    stats.err();
                    tokio::time::sleep(Duration::from_millis(5)).await;
                } else {
                    break 'conn;
                }
            }
            local
        }));
    }
    for h in handles {
        if let Ok(l) = h.await {
            MERGED.with(|m| m.borrow_mut().lat_us.extend(l.lat_us));
        }
    }
}

// ---------------------------------------------------------------------- H2 --
#[allow(clippy::too_many_arguments)]
async fn run_h2(
    host: &str,
    port: u16,
    path: &str,
    conns: usize,
    streams: usize,
    deadline: Instant,
    stats: Stats,
    budget: Arc<AtomicUsize>,
) {
    let tls = Arc::new(client_tls(Some(b"h2")));
    let addr = format!("{host}:{port}");
    let host = host.to_string();
    let path = path.to_string();
    let mut handles = Vec::with_capacity(conns);
    for _ in 0..conns {
        let (tls, host, path, addr, stats, budget) = (
            tls.clone(),
            host.clone(),
            path.clone(),
            addr.clone(),
            stats.clone(),
            budget.clone(),
        );
        handles.push(tokio::spawn(async move {
            let mut local = Local::default();
            let mut res: Result<(), BoxErr>;
            'conn: while Instant::now() < deadline && budget.load(Ordering::Relaxed) > 0 {
                res = async {
                    let tcp = tokio::net::TcpStream::connect(&addr).await?;
                    let connector = tokio_rustls::TlsConnector::from(tls.clone());
                    let tls_stream = connector.connect(server_name(&host), tcp).await?;
                    let (client, conn) = h2::client::handshake(tls_stream).await?;
                    tokio::spawn(async move {
                        let _ = conn.await;
                    });
                    let mut req_tasks = Vec::with_capacity(streams);
                    for _ in 0..streams {
                        let (mut client, path, host, stats, budget) = (
                            client.clone(),
                            path.clone(),
                            host.clone(),
                            stats.clone(),
                            budget.clone(),
                        );
                        req_tasks.push(tokio::spawn(async move {
                            let mut lat = Vec::new();
                            while Instant::now() < deadline && budget.load(Ordering::Relaxed) > 0 {
                                let t0 = Instant::now();
                                let req = http::Request::builder()
                                    .uri(format!("https://{host}{path}"))
                                    .body(())
                                    .unwrap();
                                let (resp, _send) = client.send_request(req, true)?;
                                let resp = resp.await?;
                                if resp.status() != 200 {
                                    return Err(format!("status {}", resp.status()).into());
                                }
                                let mut body = resp.into_body();
                                while let Some(chunk) = body.data().await {
                                    chunk?;
                                }
                                lat.push(t0.elapsed().as_secs_f64() * 1_000_000.0);
                                stats.ok_budgeted(&budget);
                            }
                            Ok::<Vec<f64>, BoxErr>(lat)
                        }));
                    }
                    let mut any_err: Option<BoxErr> = None;
                    for t in req_tasks {
                        match t.await {
                            Ok(Ok(lat)) => local.lat_us.extend(lat),
                            Ok(Err(e)) => any_err = Some(e),
                            Err(e) => any_err = Some(e.to_string().into()),
                        }
                    }
                    any_err.map_or(Ok(()), Err)
                }
                .await;
                if let Err(ref e) = res {
                    if std::env::var("H3LOAD_DEBUG").is_ok() {
                        static ONCE: AtomicUsize = AtomicUsize::new(0);
                        if ONCE.fetch_add(1, Ordering::Relaxed) < 3 {
                            eprintln!("error: {e}");
                        }
                    }
                    stats.err();
                    tokio::time::sleep(Duration::from_millis(5)).await;
                } else {
                    break 'conn;
                }
            }
            local
        }));
    }
    for h in handles {
        if let Ok(l) = h.await {
            MERGED.with(|m| m.borrow_mut().lat_us.extend(l.lat_us));
        }
    }
}

// ---------------------------------------------------------------------- H3 --
#[allow(clippy::too_many_arguments)]
async fn run_h3(
    host: &str,
    port: u16,
    path: &str,
    conns: usize,
    streams: usize,
    deadline: Instant,
    stats: Stats,
    budget: Arc<AtomicUsize>,
) {
    let mut client_cfg = quinn::ClientConfig::new(Arc::new(
        quinn::crypto::rustls::QuicClientConfig::try_from(Arc::new(client_tls(Some(b"h3"))))
            .expect("quic client crypto"),
    ));
    let mut transport = quinn::TransportConfig::default();
    transport.max_concurrent_bidi_streams(quinn::VarInt::from_u32(streams.clamp(1, 1024) as u32));
    client_cfg.transport_config(Arc::new(transport));

    let mut endpoint = quinn::Endpoint::client("0.0.0.0:0".parse().unwrap()).expect("client udp");
    endpoint.set_default_client_config(client_cfg);

    let addr: std::net::SocketAddr = format!("{host}:{port}").parse().expect("socket addr");
    let host = host.to_string();
    let path = path.to_string();
    let mut handles = Vec::with_capacity(conns);
    for _ in 0..conns {
        let (endpoint, host, path, stats, budget) = (
            endpoint.clone(),
            host.clone(),
            path.clone(),
            stats.clone(),
            budget.clone(),
        );
        handles.push(tokio::spawn(async move {
            let mut local = Local::default();
            let dbg_errors = Arc::new(AtomicUsize::new(0));
            let mut res: Result<(), BoxErr>;
            'conn: while Instant::now() < deadline && budget.load(Ordering::Relaxed) > 0 {
                res = async {
                    let conn = endpoint.connect(addr, host.as_str())?.await?;
                    let (_driver, send_req) =
                        h3::client::new(h3_quinn::Connection::new(conn)).await?;
                    let mut req_tasks = Vec::with_capacity(streams);
                    for _ in 0..streams {
                        let (mut send_req, path, host, stats, budget) = (
                            send_req.clone(),
                            path.clone(),
                            host.clone(),
                            stats.clone(),
                            budget.clone(),
                        );
                        req_tasks.push(tokio::spawn(async move {
                            let mut lat = Vec::new();
                            while Instant::now() < deadline && budget.load(Ordering::Relaxed) > 0 {
                                let t0 = Instant::now();
                                let req = http::Request::builder()
                                    .uri(format!("https://{host}{path}"))
                                    .body(())
                                    .unwrap();
                                let mut stream = send_req.send_request(req).await?;
                                stream.finish().await?;
                                let resp = stream.recv_response().await?;
                                if resp.status() != 200 {
                                    return Err(format!("status {}", resp.status()).into());
                                }
                                while let Some(chunk) = stream.recv_data().await? {
                                    let _ = chunk.remaining();
                                }
                                lat.push(t0.elapsed().as_secs_f64() * 1_000_000.0);
                                stats.ok_budgeted(&budget);
                            }
                            Ok::<Vec<f64>, BoxErr>(lat)
                        }));
                    }
                    let mut any_err: Option<BoxErr> = None;
                    for t in req_tasks {
                        match t.await {
                            Ok(Ok(lat)) => local.lat_us.extend(lat),
                            Ok(Err(e)) => any_err = Some(e),
                            Err(e) => any_err = Some(e.to_string().into()),
                        }
                    }
                    any_err.map_or(Ok(()), Err)
                }
                .await;
                if res.is_err() {
                    if std::env::var("H3LOAD_DEBUG").is_ok()
                        && dbg_errors.fetch_add(1, Ordering::Relaxed) < 1
                    {
                        eprintln!("h3 conn/request error: {res:?}");
                    }
                    stats.err();
                    tokio::time::sleep(Duration::from_millis(5)).await;
                } else {
                    break 'conn;
                }
            }
            local
        }));
    }
    for h in handles {
        if let Ok(l) = h.await {
            MERGED.with(|m| m.borrow_mut().lat_us.extend(l.lat_us));
        }
    }
}

thread_local! {
    static MERGED: std::cell::RefCell<Local> = std::cell::RefCell::new(Local::default());
}

#[tokio::main(flavor = "multi_thread")]
async fn main() {
    let args: Vec<String> = std::env::args().collect();
    if args.len() < 7 {
        eprintln!("usage: h3load <h1|h2|h3|h1plain> <host> <port> <path> <conns> <seconds> [streams] [total_requests]");
        std::process::exit(2);
    }
    let mode = args[1].clone();
    let host = args[2].clone();
    let port: u16 = args[3].parse().expect("port");
    let path = args[4].clone();
    let conns: usize = args[5].parse().expect("conns");
    let seconds: f64 = args[6].parse().expect("seconds");
    let streams: usize = if args.len() > 7 {
        args[7].parse().unwrap_or(1)
    } else {
        1
    };
    // Optional cap on total successful requests across all workers: the run
    // ends when EITHER the duration OR the budget is exhausted (or = 0/absent
    // keeps the pure duration behavior). A generous duration still bounds it.
    let total_requests: usize = if args.len() > 8 {
        args[8].parse().unwrap_or(0)
    } else {
        0
    };

    let stats = Stats::new();
    let started = Instant::now();
    let deadline = if total_requests > 0 {
        // 10k rps floor x requested count, hard-capped at 1 hour.
        let est = (total_requests as f64 / 10_000.0).min(3600.0);
        started + Duration::from_secs_f64(seconds.max(est))
    } else {
        started + Duration::from_secs_f64(seconds)
    };

    let budget = Arc::new(AtomicUsize::new(if total_requests > 0 {
        total_requests
    } else {
        usize::MAX
    }));
    match mode.as_str() {
        "h1" => {
            run_h1(
                &host,
                port,
                &path,
                conns,
                deadline,
                stats.clone(),
                budget.clone(),
            )
            .await
        }
        "h1plain" => {
            run_h1plain(
                &host,
                port,
                &path,
                conns,
                deadline,
                stats.clone(),
                budget.clone(),
            )
            .await
        }
        "h2" => {
            run_h2(
                &host,
                port,
                &path,
                conns,
                streams,
                deadline,
                stats.clone(),
                budget.clone(),
            )
            .await
        }
        "h3" => {
            run_h3(
                &host,
                port,
                &path,
                conns,
                streams,
                deadline,
                stats.clone(),
                budget.clone(),
            )
            .await
        }
        other => {
            eprintln!("unknown mode: {other}");
            std::process::exit(2);
        }
    }

    let (ok, err) = stats.counts();
    let elapsed = started.elapsed().as_secs_f64();
    let lat: Vec<f64> = MERGED
        .with(|m| std::mem::take(&mut *m.borrow_mut()))
        .lat_us
        .iter()
        .map(|us| us / 1000.0)
        .collect();
    println!(
        "{{\"mode\":\"{mode}\",\"conns\":{conns},\"streams\":{streams},\"seconds\":{seconds:.1},\"ok\":{ok},\"err\":{err},\"rps\":{:.1},\"p50_ms\":{},\"p99_ms\":{}}}",
        ok as f64 / elapsed,
        percentile(&lat, 50.0),
        percentile(&lat, 99.0)
    );
}
