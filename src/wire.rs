//! HTTP wire helpers: building and normalizing HTTP/1.1 responses for
//! the client (keep-alive, Connection patching, alt-svc advertisement,
//! security-header injection) and encoding h2 requests as h1 for upstreams.

use std::net::IpAddr;

use bytes::{BufMut, BytesMut};
use http::HeaderMap;

use crate::settings;

/// Render a complete HTTP/1.1 response with an explicit Connection header.
/// Value of the `alt-svc` advertisement header, or None when HTTP/3 is
/// disabled. Port mirrors the `http3_port` knob.
pub fn alt_svc_header() -> Option<(&'static str, String)> {
    if settings().http3_enabled.get() == 1 {
        Some((
            "alt-svc",
            format!("h3=\":{}\"; ma=86400", settings().http3_port.get()),
        ))
    } else {
        None
    }
}

/// Append `alt-svc` to a raw H1 head (before the final \r\n\r\n) when h3 is
/// enabled. No-op otherwise. Used by the response normalizers so every
/// H1/H2-bridged response advertises the QUIC endpoint.
pub fn with_alt_svc(mut raw: Vec<u8>) -> Vec<u8> {
    let Some((name, value)) = alt_svc_header() else {
        return raw;
    };
    // Insert before the BLANK line: pos+2 skips the CRLF of the last
    // header, so alt-svc becomes its own line instead of gluing onto the
    // "Connection: keep-alive" value.
    if let Some(pos) = raw.windows(4).position(|w| w == b"\r\n\r\n") {
        let line = format!("{}: {}\r\n", title_case_header(name), value);
        raw.splice(pos + 2..pos + 2, line.as_bytes().iter().copied());
    }
    raw
}

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
    with_alt_svc(out.to_vec())
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
        (true, None) => {
            // Same insertion point as the close branch: before the head
            // terminator, after the LAST header's own CRLF.
            if let Some(p) = raw.windows(4).position(|w| w == b"

") {
                raw.splice(p + 2..p + 2, b"Connection: keep-alive
".iter().copied());
            }
        }
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
pub fn title_case_header(name: &str) -> String {
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
    client_ip: IpAddr,
) -> Vec<u8> {
    let mut out = Vec::with_capacity(body.len() + 512);
    out.extend_from_slice(format!("{} {} HTTP/1.1\r\n", method.as_str(), target).as_bytes());

    let mut host: Option<&str> = None;
    for (name, value) in headers {
        let n = name.as_str();
        // Hop-by-hop and framing headers must not cross the h2 -> h1 bridge.
        if matches!(
            n,
            "connection"
                | "te"
                | "upgrade"
                | "transfer-encoding"
                | "keep-alive"
                | "proxy-connection"
                | "content-length"
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
            out.extend_from_slice(format!("{}: {}\r\n", title_case_header(n), v).as_bytes());
        }
    }
    out.extend_from_slice(format!("Host: {}\r\n", host.unwrap_or("localhost")).as_bytes());
    out.extend_from_slice(b"Connection: keep-alive\r\n");
    // Real peer IP (see build_forwarded_request: "client" stalls mod_remoteip).
    out.extend_from_slice(format!("X-Forwarded-For: {client_ip}\r\n").as_bytes());
    out.extend_from_slice(format!("X-Request-Id: req-{:x}\r\n", rand::random::<u64>()).as_bytes());
    for (k, v) in extra_headers {
        out.extend_from_slice(format!("{k}: {v}\r\n").as_bytes());
    }
    out.extend_from_slice(format!("Content-Length: {}\r\n", body.len()).as_bytes());
    out.extend_from_slice(b"\r\n");
    out.extend_from_slice(body);
    out
}
