//! Standalone Juggler load generator: h1 / h2 / h3 against the same TLS
//! endpoint (:8443), N worker tasks each driving its own connection,
//! sequential requests per connection, duration-limited.
//!
//! Purpose: remove the Python event-loop bottleneck from bench/h3_load.py
//! and measure the server-side ceiling of each protocol plane.
//!
//! Usage (from the project root, cert.pem/key.pem in cwd):
//!   cargo run --release --bin h3load -- h3 127.0.0.1 8443 /healthz 256 8
//!
//! Output (single JSON line):
//!   {"mode","conns","seconds","ok","err","rps","p50_ms","p99_ms"}

use std::sync::Arc;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::time::{Duration, Instant};

use bytes::Buf;
use rustls_pki_types::{pem::PemObject, CertificateDer, PrivateKeyDer, ServerName};

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
    fn ok(&self) {
        self.0.ok.fetch_add(1, Ordering::Relaxed);
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

type BoxErr = Box<dyn std::error::Error + Send + Sync>;

#[allow(dead_code)]
fn percentile(mut v: Vec<f64>, p: f64) -> f64 {
    if v.is_empty() {
        return 0.0;
    }
    v.sort_by(|a, b| a.partial_cmp(b).unwrap());
    let idx = ((v.len() as f64) * p / 100.0).min((v.len() - 1) as f64) as usize;
    (v[idx] * 100_000.0).round() / 100.0 // ms, 2 decimals
}

// ---------------------------------------------------------------- TLS setup --
fn client_tls(alpn: Option<&[u8]>) -> rustls::ClientConfig {
    let certs = CertificateDer::pem_file_iter("cert.pem")
        .expect("cert.pem in cwd (run from project root)")
        .collect::<Result<Vec<_>, _>>()
        .expect("valid cert.pem");
    let key = PrivateKeyDer::from_pem_file("key.pem").expect("key.pem");
    let mut roots = rustls::RootCertStore::empty();
    for c in &certs {
        roots.add(c.clone()).ok();
    }
    let mut cfg = rustls::ClientConfig::builder()
        .with_root_certificates(roots)
        .with_client_auth_cert(certs, key)
        .expect("client tls config");
    if let Some(a) = alpn {
        cfg.alpn_protocols = vec![a.to_vec()];
    }
    cfg
}

fn server_name(host: &str) -> ServerName<'static> {
    ServerName::try_from(host.to_string()).expect("server name")
}

// ---------------------------------------------------------------------- H1 --
async fn run_h1(host: &str, port: u16, path: &str, conns: usize, deadline: Instant, stats: Stats) {
    let tls = Arc::new(client_tls(None));
    let addr = format!("{host}:{port}");
    let host = host.to_string();
    let path = path.to_string();
    let mut tasks = Vec::with_capacity(conns);
    for _ in 0..conns {
        let (tls, host, path, addr, stats) =
            (tls.clone(), host.clone(), path.clone(), addr.clone(), stats.clone());
        tasks.push(tokio::spawn(async move {
            loop {
                if Instant::now() >= deadline {
                    break;
                }
                use tokio::io::{AsyncReadExt, AsyncWriteExt};
                let res: Result<(), BoxErr> = async {
                    let tcp = tokio::net::TcpStream::connect(&addr).await?;
                    let connector = tokio_rustls::TlsConnector::from(tls.clone());
                    let mut s = connector.connect(server_name(&host), tcp).await?;
                    let req = format!("GET {path} HTTP/1.1\r\nHost: {host}\r\nConnection: close\r\n\r\n");
                    s.write_all(req.as_bytes()).await?;
                    let mut buf = Vec::with_capacity(256);
                    s.read_to_end(&mut buf).await?;
                    if buf.starts_with(b"HTTP/1.1 200") || buf.starts_with(b"HTTP/1.0 200") {
                        Ok(())
                    } else {
                        Err("non-200".into())
                    }
                }
                .await;
                match res {
                    Ok(()) => stats.ok(),
                    Err(_) => stats.err(),
                }
            }
        }));
    }
    for t in tasks {
        let _ = t.await;
    }
}

// ---------------------------------------------------------------------- H2 --
async fn run_h2(host: &str, port: u16, path: &str, conns: usize, deadline: Instant, stats: Stats) {
    let tls = Arc::new(client_tls(Some(b"h2")));
    let addr = format!("{host}:{port}");
    let host = host.to_string();
    let path = path.to_string();
    let mut tasks = Vec::with_capacity(conns);
    for _ in 0..conns {
        let (tls, host, path, addr, stats) =
            (tls.clone(), host.clone(), path.clone(), addr.clone(), stats.clone());
        tasks.push(tokio::spawn(async move {
            loop {
                if Instant::now() >= deadline {
                    break;
                }
                let res: Result<(), BoxErr> = async {
                    let tcp = tokio::net::TcpStream::connect(&addr).await?;
                    let connector = tokio_rustls::TlsConnector::from(tls.clone());
                    let tls_stream = connector.connect(server_name(&host), tcp).await?;
                    let (mut client, conn) = h2::client::handshake(tls_stream).await?;
                    tokio::spawn(async move {
                        let _ = conn.await;
                    });
                    while Instant::now() < deadline {
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
                        stats.ok();
                        let _t = t0.elapsed();
                    }
                    Ok(())
                }
                .await;
                if res.is_err() {
                    stats.err();
                    tokio::time::sleep(Duration::from_millis(5)).await;
                }
            }
        }));
    }
    for t in tasks {
        let _ = t.await;
    }
}

// ---------------------------------------------------------------------- H3 --
async fn run_h3(host: &str, port: u16, path: &str, conns: usize, deadline: Instant, stats: Stats) {
    let mut client_cfg = quinn::ClientConfig::new(Arc::new(
        quinn::crypto::rustls::QuicClientConfig::try_from(Arc::new(client_tls(Some(b"h3"))))
            .expect("quic client crypto"),
    ));
    let mut transport = quinn::TransportConfig::default();
    transport.max_concurrent_bidi_streams(quinn::VarInt::from_u32(512));
    client_cfg.transport_config(Arc::new(transport));

    let mut endpoint = quinn::Endpoint::client("0.0.0.0:0".parse().unwrap()).expect("client udp");
    endpoint.set_default_client_config(client_cfg);

    let addr: std::net::SocketAddr = format!("{host}:{port}").parse().expect("socket addr");
    let host = host.to_string();
    let path = path.to_string();
    let mut tasks = Vec::with_capacity(conns);
    for _ in 0..conns {
        let (endpoint, host, path, stats) =
            (endpoint.clone(), host.clone(), path.clone(), stats.clone());
        let dbg_errors = Arc::new(AtomicUsize::new(0));
        tasks.push(tokio::spawn(async move {
            loop {
                if Instant::now() >= deadline {
                    break;
                }
                let res: Result<(), BoxErr> = async {
                    let conn = endpoint.connect(addr, host.as_str())?.await?;
                    let (_driver, mut send_req) =
                        h3::client::new(h3_quinn::Connection::new(conn)).await?;
                    while Instant::now() < deadline {
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
                        stats.ok();
                    }
                    Ok(())
                }
                .await;
                if res.is_err() {
                    if std::env::var("H3LOAD_DEBUG").is_ok() && dbg_errors.fetch_add(1, Ordering::Relaxed) < 1 {
                        eprintln!("h3 conn/request error: {res:?}");
                    }
                    stats.err();
                    tokio::time::sleep(Duration::from_millis(5)).await;
                }
            }
        }));
    }
    for t in tasks {
        let _ = t.await;
    }
}

#[tokio::main(flavor = "multi_thread")]
async fn main() {
    let args: Vec<String> = std::env::args().collect();
    if args.len() < 7 {
        eprintln!("usage: h3load <h1|h2|h3> <host> <port> <path> <conns> <seconds>");
        std::process::exit(2);
    }
    let mode = args[1].clone();
    let host = args[2].clone();
    let port: u16 = args[3].parse().expect("port");
    let path = args[4].clone();
    let conns: usize = args[5].parse().expect("conns");
    let seconds: f64 = args[6].parse().expect("seconds");

    let stats = Stats::new();
    let started = Instant::now();
    let deadline = started + Duration::from_secs_f64(seconds);

    match mode.as_str() {
        "h1" => run_h1(&host, port, &path, conns, deadline, stats.clone()).await,
        "h2" => run_h2(&host, port, &path, conns, deadline, stats.clone()).await,
        "h3" => run_h3(&host, port, &path, conns, deadline, stats.clone()).await,
        other => {
            eprintln!("unknown mode: {other}");
            std::process::exit(2);
        }
    }

    let (ok, err) = stats.counts();
    let elapsed = started.elapsed().as_secs_f64();
    println!(
        "{{\"mode\":\"{mode}\",\"conns\":{conns},\"seconds\":{seconds:.1},\"ok\":{ok},\"err\":{err},\"rps\":{:.1}}}",
        ok as f64 / elapsed
    );
}
