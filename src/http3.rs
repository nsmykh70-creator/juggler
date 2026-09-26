//! HTTP/3 data plane (RFC 9114) over QUIC.
//!
//! Binds one UDP endpoint (default :8443, `http3_port` knob) using the same
//! certificate material as the TLS TCP plane, and serves each h3 request
//! stream through the SAME routing/dispatch engine the H1/H2 frontends use
//! (`resolve_route` + `dispatch_to_upstreams`). Responses are translated
//! from the upstream's raw HTTP/1.1 wire format into h3 headers + data
//! frames, mirroring the h2 bridge.
//!
//! Controlled by the `http3_enabled` knob (boot-time): when off, no UDP
//! socket is bound and H1/H2 behavior is byte-identical to before.

use std::net::SocketAddr;
use std::sync::Arc;

use arc_swap::ArcSwap;
use bytes::{Buf, Bytes};
use http::Response;

use crate::metrics::PROTO_H3;
use crate::telemetry::record_response_class;
use crate::{
    dispatch_to_upstreams, parse_h1_response_head, request_signature_hash, resolve_route,
    status_label, title_case_header, DispatchError, RouteDecision, ServerConfig,
    H3_CONNECTIONS_TOTAL, HTTP_REQUESTS_TOTAL, HTTP_RESPONSE_SECONDS,
};

/// UDP payload / QUIC settings for a local reverse proxy: moderate buffers,
/// no deep queues — the datapath is CPU-bound, not BW-bound.
fn quinn_server_config(
    certs: Vec<rustls_pki_types::CertificateDer<'static>>,
    key: rustls_pki_types::PrivateKeyDer<'static>,
) -> Result<quinn::ServerConfig, String> {
    let mut server_crypto =
        crate::build_quic_tls_config(certs, key).map_err(|e| format!("quic tls config: {e}"))?;
    // h3 requires ALPN on the QUIC handshake.
    server_crypto.alpn_protocols = vec![b"h3".to_vec()];
    let server_crypto = quinn::crypto::rustls::QuicServerConfig::try_from(Arc::new(server_crypto))
        .map_err(|e| format!("quic crypto: {e}"))?;

    let mut transport = quinn::TransportConfig::default();
    transport.max_concurrent_bidi_streams(quinn::VarInt::from_u32(256));
    transport.max_concurrent_uni_streams(quinn::VarInt::from_u32(64));
    // Keep idle QUIC connections short-lived: browsers re-handshake cheaply.
    transport.max_idle_timeout(Some(
        quinn::IdleTimeout::try_from(std::time::Duration::from_secs(30)).expect("valid timeout"),
    ));

    let mut server_cfg = quinn::ServerConfig::with_crypto(Arc::new(server_crypto));
    server_cfg.transport_config(Arc::new(transport));
    Ok(server_cfg)
}

/// Serve HTTP/3 on `addr` forever. Runs on its own single-threaded tokio
/// runtime thread: the QUIC plane must stay alive even if a shard stalls,
/// and h3 request handling is cheap relative to TLS-TCP frontends.
pub fn spawn_http3_server(
    addr: SocketAddr,
    certs: Vec<rustls_pki_types::CertificateDer<'static>>,
    key: rustls_pki_types::PrivateKeyDer<'static>,
    config: Arc<ArcSwap<ServerConfig>>,
) -> Result<(), String> {
    let server_cfg =
        quinn_server_config(certs, key).map_err(|e| format!("http3 server config: {e}"))?;

    std::thread::Builder::new()
        .name("h3-plane".into())
        .spawn(move || {
            let rt = tokio::runtime::Builder::new_current_thread()
                .enable_all()
                .build()
                .expect("h3 runtime");
            // Endpoint::server needs a tokio context for its IO driver, so
            // the UDP bind happens inside block_on, on this dedicated thread.
            rt.block_on(async move {
                let endpoint = match quinn::Endpoint::server(server_cfg, addr) {
                    Ok(e) => e,
                    Err(e) => {
                        eprintln!("⚠️ HTTP/3 disabled: cannot bind UDP {addr}: {e}");
                        return;
                    }
                };
                println!("🚀 HTTP/3 data plane listening on https://{addr} (quinn, ALPN h3)");
                while let Some(incoming) = endpoint.accept().await {
                    let cfg = config.clone();
                    tokio::spawn(async move {
                        match incoming.await {
                            Ok(conn) => {
                                H3_CONNECTIONS_TOTAL.inc();
                                let peer = conn.remote_address();
                                if let Err(
                                    HandleError::Connection(
                                        h3::error::ConnectionError::Local { error, .. },
                                    ),
                                ) = handle_quic_connection(conn, peer, cfg).await
                                {
                                    // Client-side closes/aborts are routine noise;
                                    // only local failures deserve a log line.
                                    eprintln!("⚠️ h3 connection error: {error:?}");
                                }
                            }
                            Err(e) => eprintln!("⚠️ QUIC handshake failed: {e}"),
                        }
                    });
                }
            });
        })
        .map_err(|e| format!("spawn h3 thread: {e}"))?;
    Ok(())
}

#[derive(Debug)]
enum HandleError {
    Connection(h3::error::ConnectionError),
    Stream(h3::error::StreamError),
}

async fn handle_quic_connection(
    conn: quinn::Connection,
    peer_addr: SocketAddr,
    config: Arc<ArcSwap<ServerConfig>>,
) -> Result<(), HandleError> {
    let mut h3_conn = h3::server::builder()
        // Do not send the GREASE SETTINGS / GREASE frames this crate emits by
        // default (RFC 9114 §7.2.8 allows them, clients MUST ignore them).
        // aioquic-based clients have a frame-decoding bug: an unknown frame
        // immediately before FIN swallows the end-of-stream signal, so the
        // FIRST request on every connection hangs. Browsers tolerate it, but
        // Python/QUIC clients are common enough to keep the plane interop-
        // friendly; grease brings no value to a same-version fleet.
        .send_grease(false)
        .build(h3_quinn::Connection::new(conn))
        .await
        .map_err(HandleError::Connection)?;

    loop {
        match h3_conn.accept().await {
            Ok(Some(resolver)) => {
                let cfg = config.clone();
                tokio::spawn(async move {
                    if let Err(e) = serve_h3_request(resolver, peer_addr.ip(), cfg).await {
                        match e {
                            // Stream-level errors carry the failing stream's
                            // details; debug-format the whole error.
                            HandleError::Stream(err) => {
                                eprintln!("⚠️ h3 request error: {err:?}")
                            }
                            other => eprintln!("⚠️ h3 request error: {other:?}"),
                        }
                    }
                });
            }
            Ok(None) => break,
            Err(e) => return Err(HandleError::Connection(e)),
        }
    }
    Ok(())
}

/// One h3 request stream -> resolve route -> answer (same decisions as h2).
async fn serve_h3_request(
    resolver: h3::server::RequestResolver<h3_quinn::Connection, Bytes>,
    peer_ip: std::net::IpAddr,
    config: Arc<ArcSwap<ServerConfig>>,
) -> Result<(), HandleError> {
    let (request, mut stream) = resolver
        .resolve_request()
        .await
        .map_err(HandleError::Stream)?;
    let (head, _) = request.into_parts();
    let path = head.uri.path().to_string();

    // Request body (if any) must be drained before the response is sent;
    // bounded like the other frontends. GET carries none.
    let mut body_bytes: Vec<u8> = Vec::new();
    while let Some(chunk) = stream.recv_data().await.map_err(HandleError::Stream)? {
        body_bytes.extend_from_slice(chunk.chunk());
        if body_bytes.len() > crate::MAX_REQUEST_HEAD {
            send_h3_response(
                &mut stream,
                431,
                &[("content-type", "text/plain; charset=utf-8")],
                Bytes::from_static(b"payload too large"),
            )
            .await?;
            return Ok(());
        }
    }

    match resolve_route(&path, &mut body_bytes, peer_ip, PROTO_H3, &config) {
        RouteDecision::RateLimited => {
            send_h3_response(
                &mut stream,
                429,
                &[("content-type", "text/plain; charset=utf-8")],
                Bytes::from_static(b"Rate limit exceeded. Slow down."),
            )
            .await?;
            record_response_class(429);
        }
        RouteDecision::Builtin(status, text) => {
            let ct = if status == 200 && text.starts_with("<!DOCTYPE html>") {
                "text/html; charset=utf-8"
            } else {
                "text/plain; charset=utf-8"
            };
            send_h3_response(
                &mut stream,
                status,
                &[("content-type", ct)],
                Bytes::from(text),
            )
            .await?;
            record_response_class(status);
        }
        RouteDecision::NotFound => {
            send_h3_response(
                &mut stream,
                404,
                &[("content-type", "text/plain; charset=utf-8")],
                Bytes::from_static(b"route not found"),
            )
            .await?;
            record_response_class(404);
        }
        RouteDecision::Redirect301(loc) => {
            send_h3_response(
                &mut stream,
                301,
                &[("location", loc.as_str())],
                Bytes::from_static(b""),
            )
            .await?;
            record_response_class(301);
        }
        RouteDecision::StaticFile(raw_head, file_body) => {
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
            send_h3_response(
                &mut stream,
                status,
                &[("content-type", ct.as_str())],
                Bytes::from(file_body),
            )
            .await?;
            record_response_class(status);
        }
        RouteDecision::Proxy(upstreams, body, extra_headers, security_headers, strip) => {
            let started = std::time::Instant::now();
            let query = head
                .uri
                .query()
                .map(|q| format!("?{q}"))
                .unwrap_or_default();
            let forwarded_target: String = if strip > 0 {
                format!("/{}{}", path[strip..].trim_start_matches('/'), query)
            } else {
                format!("/{}{}", path.trim_start_matches('/'), query)
            };
            let forwarded = crate::build_h1_request_from_parts(
                &head.method,
                &forwarded_target,
                &head.headers,
                &body,
                &extra_headers,
                peer_ip,
            );
            let sig = request_signature_hash(head.method.as_str().as_bytes(), &path, body.len());
            let (result, _used) = dispatch_to_upstreams(&forwarded, &upstreams, sig).await;
            HTTP_RESPONSE_SECONDS
                .with_label_values(&[&path])
                .observe(started.elapsed().as_secs_f64());

            let final_status = match result {
                Ok(outcome) => {
                    if let Some((code, headers, body_start)) =
                        parse_h1_response_head(&outcome.response)
                    {
                        let mut hdrs: Vec<(String, String)> = Vec::new();
                        for (k, v) in &security_headers {
                            let exists = headers.iter().any(|(hk, _)| hk.eq_ignore_ascii_case(k));
                            if !exists {
                                hdrs.push((title_case_header(k), v.clone()));
                            }
                        }
                        for (k, v) in &headers {
                            let kl = k.to_ascii_lowercase();
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
                        let hdr_refs: Vec<(&str, &str)> =
                            hdrs.iter().map(|(k, v)| (k.as_str(), v.as_str())).collect();
                        send_h3_response(
                            &mut stream,
                            code,
                            &hdr_refs,
                            Bytes::copy_from_slice(&outcome.response[body_start..]),
                        )
                        .await?;
                        record_response_class(code);
                        status_label(code)
                    } else {
                        send_h3_response(
                            &mut stream,
                            502,
                            &[("content-type", "text/plain; charset=utf-8")],
                            Bytes::from_static(b"upstream sent a non-HTTP response"),
                        )
                        .await?;
                        record_response_class(502);
                        "502"
                    }
                }
                Err(DispatchError::NoUpstreams) => {
                    send_h3_response(
                        &mut stream,
                        503,
                        &[("content-type", "text/plain; charset=utf-8")],
                        Bytes::from_static(b"no upstreams available"),
                    )
                    .await?;
                    record_response_class(503);
                    "503"
                }
                Err(DispatchError::AllFailed(e)) => {
                    eprintln!("⚠️ all upstreams failed for {path}: {e}");
                    send_h3_response(
                        &mut stream,
                        502,
                        &[("content-type", "text/plain; charset=utf-8")],
                        Bytes::from_static(b"upstream unreachable"),
                    )
                    .await?;
                    record_response_class(502);
                    "502"
                }
            };
            HTTP_REQUESTS_TOTAL
                .with_label_values(&[&path, final_status, PROTO_H3])
                .inc();
        }
    }
    Ok(())
}

async fn send_h3_response(
    stream: &mut h3::server::RequestStream<h3_quinn::BidiStream<Bytes>, Bytes>,
    status: u16,
    headers: &[(&str, &str)],
    body: Bytes,
) -> Result<(), HandleError> {
    let mut builder = Response::builder().status(status);
    for (k, v) in headers {
        builder = builder.header(*k, *v);
    }
    // Header values are compile-time constants here; a build failure would
    // be a programmer error, not an input-driven one.
    let response = builder
        .body(())
        .expect("static h3 response headers are valid");
    stream
        .send_response(response)
        .await
        .map_err(HandleError::Stream)?;
    if !body.is_empty() {
        stream.send_data(body).await.map_err(HandleError::Stream)?;
    }
    stream.finish().await.map_err(HandleError::Stream)?;
    Ok(())
}
