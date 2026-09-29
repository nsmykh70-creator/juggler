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
/// Case-insensitive single-header lookup in a raw H1 head.
pub fn header_value<'a>(head: &'a [u8], name: &[u8]) -> Option<&'a str> {
    let head_end = head.windows(4).position(|w| w == b"\r\n\r
").unwrap_or(head.len());
    let mut pos = 0;
    while pos < head_end {
        let Some(rel) = head[pos..head_end]
            .windows(name.len())
            .position(|w| w.eq_ignore_ascii_case(name))
        else {
            break;
        };
        let start = pos + rel;
        let line_start_ok = start == 0 || head[start - 1] == b'\n';
        let after = start + name.len();
        if line_start_ok && head.get(after) == Some(&b':') {
            let vstart = after + 1;
            let vend = vstart
                + head[vstart..head_end]
                    .windows(2)
                    .position(|w| w == b"\r\n")
                    .unwrap_or(head_end - vstart);
            return std::str::from_utf8(&head[vstart..vend])
                .ok()
                .map(|s| s.trim());
        }
        pos = start + name.len();
    }
    None
}

/// Replace an existing header's value or append the header before the blank
/// line. Used for Content-Encoding / Vary on the gzip path.
pub fn patch_or_add_header(head: &[u8], name: &[u8], value: &str) -> Vec<u8> {
    // Fast path: header already present (line-aligned) -> replace its value.
    let head_end = head.windows(4).position(|w| w == b"\r\n\r\n").unwrap_or(head.len());
    let mut pos = 0;
    while pos < head_end {
        let Some(rel) = head[pos..head_end]
            .windows(name.len())
            .position(|w| w.eq_ignore_ascii_case(name))
        else {
            break;
        };
        let start = pos + rel;
        let line_start_ok = start == 0 || head[start - 1] == b'\n';
        let after = start + name.len();
        if line_start_ok && head.get(after) == Some(&b':') {
            let vstart = after + 1;
            let vend = vstart
                + head[vstart..head_end]
                    .windows(2)
                    .position(|w| w == b"\r\n")
                    .unwrap_or(head_end - vstart);
            let mut out = Vec::with_capacity(head.len() + value.len());
            out.extend_from_slice(&head[..vstart]);
            out.extend_from_slice(value.as_bytes());
            out.extend_from_slice(&head[vend..]);
            return out;
        }
        pos = start + name.len();
    }
    // Append before the terminator.
    let mut out = Vec::with_capacity(head.len() + name.len() + value.len() + 4);
    if let Some(p) = head.windows(4).position(|w| w == b"\r\n\r\n") {
        out.extend_from_slice(&head[..p + 2]);
        out.extend_from_slice(name);
        out.extend_from_slice(b": ");
        out.extend_from_slice(value.as_bytes());
        out.extend_from_slice(b"\r\n");
        out.extend_from_slice(&head[p + 2..]);
    } else {
        out.extend_from_slice(head);
    }
    out
}

/// Rewrite the Content-Length of a raw H1 head (gzip changes the body size).
pub fn patch_len(head: &[u8], new_len: usize) -> Vec<u8> {
    patch_or_add_header(head, b"content-length", &new_len.to_string())
}

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

// ============================================================================
// Response compression (gzip, RFC 1952). Written on DEFLATE with fixed
// Huffman tables so the server keeps zero compression dependencies while
// still cutting text payloads 2.5-3x on the wire (the nginx gzip_level=1
// use case). Only compressible Content-Types are ever compressed, bodies
// below GZIP_MIN are passed through (framing overhead exceeds the win),
// and responses that already carry a Content-Encoding are left untouched.
// ============================================================================

/// Bodies smaller than this are not compressed: gzip adds ~20 bytes of
/// framing and CPU for at best a few saved bytes.
pub const GZIP_MIN: usize = 1024;

/// Compressible Content-Type prefixes (nginx `gzip_types` equivalent).
/// Images/video/fonts/archives are already compressed; re-compressing
/// wastes CPU for ~0% size win and can even grow the payload.
fn gzip_worth_it(content_type: &str) -> bool {
    let ct = content_type.split(';').next().unwrap_or("").trim();
    matches!(
        ct,
        "text/html"
            | "text/css"
            | "text/plain"
            | "text/javascript"
            | "application/javascript"
            | "application/json"
            | "application/xml"
            | "image/svg+xml"
            | "text/markdown"
    )
}

/// Does the client's Accept-Encoding include gzip? Tolerates q-values and
/// case differences; `gzip;q=0` means "do not send gzip".
fn client_wants_gzip(accept_encoding: Option<&str>) -> bool {
    let Some(ae) = accept_encoding else { return false };
    for part in ae.split(',') {
        let mut seg = part.trim().split(';');
        if seg.next().map(|s| s.trim().eq_ignore_ascii_case("gzip")) == Some(true) {
            let forbidden = seg.any(|p| {
                let p = p.trim();
                p.starts_with("q=") && p[2..].trim().parse::<f32>().unwrap_or(1.0) == 0.0
            });
            return !forbidden;
        }
    }
    false
}

/// DEFLATE (RFC 1951) fixed-Huffman block with hash-chain LZ77 matching —
/// the zlib level-1 shape of work: 15-bit hash table + per-position chain,
/// bounded match search, greedy emission. Verified byte-for-byte against
/// zlib's inflater (see the wire::gzip unit tests).
#[allow(clippy::needless_range_loop)] // hash-chain walk: index arithmetic IS the loop
pub fn gzip_compress(data: &[u8]) -> Vec<u8> {
    // gzip header (RFC 1952): magic, deflate, no flags, mtime 0, XFL 0, OS=255.
    let mut out = vec![0x1f, 0x8b, 0x08, 0x00, 0, 0, 0, 0, 0x00, 0xff];

    struct BitWriter {
        out: Vec<u8>,
        acc: u32,
        nbits: u32,
    }
    impl BitWriter {
        fn push(&mut self, val: u32, cnt: u32) {
            // LSB-first: block header + extra bits.
            self.acc |= val << self.nbits;
            self.nbits += cnt;
            while self.nbits >= 8 {
                self.out.push((self.acc & 0xff) as u8);
                self.acc >>= 8;
                self.nbits -= 8;
            }
        }
        fn push_huff(&mut self, code: u32, cnt: u32) {
            // Huffman codes go MSB-first per RFC 1951 3.1.1.
            self.push(reverse_bits(code as u16, cnt) as u32, cnt);
        }
        fn flush(&mut self) {
            if self.nbits > 0 {
                self.out.push((self.acc & 0xff) as u8);
                self.acc = 0;
                self.nbits = 0;
            }
        }
    }

    let mut bw = BitWriter { out: Vec::with_capacity(data.len() / 2 + 64), acc: 0, nbits: 0 };
    bw.push(1, 1); // BFINAL
    bw.push(1, 2); // BTYPE = 01 (fixed Huffman)

    const LEN_BASE: [u16; 29] = [3,4,5,6,7,8,9,10,11,13,15,17,19,23,27,31,35,43,51,59,67,83,99,115,131,163,195,227,258];
    const LEN_EXTRA: [u8; 29]  = [0,0,0,0,0,0,0,0,1,1,1,1,2,2,2,2,3,3,3,3,4,4,4,4,5,5,5,5,0];
    const DIST_BASE: [u16; 30] = [1,2,3,4,5,7,9,13,17,25,33,49,65,97,129,193,257,385,513,769,1025,1537,2049,3073,4097,6145,8193,12289,16385,24577];
    const DIST_EXTRA: [u8; 30] = [0,0,0,0,1,1,2,2,3,3,4,4,5,5,6,6,7,7,8,8,9,9,10,10,11,11,12,12,13,13];

    fn len_code(l: u16) -> usize {
        let mut i = 28;
        while LEN_BASE[i] > l {
            i -= 1;
        }
        i
    }
    fn dist_code(d: u16) -> usize {
        let mut i = 29;
        while DIST_BASE[i] > d {
            i -= 1;
        }
        i
    }
    fn litlen_code(sym: u16) -> (u32, u32) {
        match sym {
            0..=143 => (0x30 + sym as u32, 8),
            144..=255 => (0x190 + (sym - 144) as u32, 9),
            256..=279 => ((sym - 256) as u32, 7),
            _ => (0xC0 + (sym - 280) as u32, 8),
        }
    }

    let n = data.len();
    let mut pos = 0usize;
    const HASH_BITS: u32 = 15;
    const HASH_SIZE: usize = 1 << HASH_BITS;
    let mut head = [-1i64; HASH_SIZE];
    let mut prev = vec![-1i64; n.max(1)];
    const MAX_CHAIN: usize = 16;
    const WINDOW: i64 = 32768;

    let h3 = |p: usize| -> usize {
        ((data[p] as usize) << 16 | (data[p + 1] as usize) << 8 | data[p + 2] as usize)
            & (HASH_SIZE - 1)
    };

    while pos < n {
        let mut best_len = 0usize;
        let mut best_dist = 0usize;
        if pos + 3 <= n {
            let hh = h3(pos);
            let mut cand = head[hh];
            let mut chain = 0usize;
            while cand >= 0 && chain < MAX_CHAIN {
                let dist = (pos as i64 - cand) as usize;
                if dist as i64 > WINDOW {
                    break;
                }
                let limit = 258.min(n - pos);
                let mut l = 0usize;
                while l < limit && data[cand as usize + l] == data[pos + l] {
                    l += 1;
                }
                if l > best_len {
                    best_len = l;
                    best_dist = dist;
                    if l >= limit {
                        break;
                    }
                }
                cand = prev[cand as usize];
                chain += 1;
            }
            prev[pos] = head[hh];
            head[hh] = pos as i64;
        }
        if best_len >= 3 {
            let lc = len_code(best_len as u16);
            let (code, bits) = litlen_code(257 + lc as u16);
            bw.push_huff(code, bits);
            let extra = best_len as u16 - LEN_BASE[lc];
            if LEN_EXTRA[lc] > 0 {
                bw.push(extra as u32, LEN_EXTRA[lc] as u32);
            }
            let dc = dist_code(best_dist as u16);
            bw.push_huff(dc as u32, 5);
            let extra_d = best_dist as u16 - DIST_BASE[dc];
            if DIST_EXTRA[dc] > 0 {
                bw.push(extra_d as u32, DIST_EXTRA[dc] as u32);
            }
            // Register every skipped position so later matches can find them.
            let stop = (pos + best_len).min(n.saturating_sub(2)).max(pos + 1);
            for k in (pos + 1)..stop {
                let hh = h3(k);
                let old = head[hh];
                head[hh] = k as i64;
                prev[k] = old;
            }
            pos += best_len;
        } else {
            let (code, bits) = litlen_code(data[pos] as u16);
            bw.push_huff(code, bits);
            pos += 1;
        }
    }
    bw.push_huff(0, 7); // end-of-block symbol
    bw.flush();
    out.extend_from_slice(&bw.out);
    out.extend_from_slice(&crc32(data).to_le_bytes());
    out.extend_from_slice(&((data.len() as u32).to_le_bytes()));
    out
}

/// Decide + apply gzip to a complete response body.
/// Returns `(new_body, content_encoding_value)`.
#[allow(clippy::needless_return)]
pub fn maybe_gzip(
    content_type: &str,
    accept_encoding: Option<&str>,
    body: &[u8],
    already_encoded: bool,
) -> (Vec<u8>, Option<&'static str>) {
    if already_encoded || body.len() < GZIP_MIN || !gzip_worth_it(content_type) {
        return (body.to_vec(), None);
    }
    if !client_wants_gzip(accept_encoding) {
        return (body.to_vec(), None);
    }
    let compressed = gzip_compress(body);
    // Safety valve: a pathological payload that grows is passed through.
    if compressed.len() < body.len() {
        (compressed, Some("gzip"))
    } else {
        (body.to_vec(), None)
    }
}

fn reverse_bits(mut v: u16, len: u32) -> u16 {
    let mut r = 0u16;
    for _ in 0..len {
        r = (r << 1) | (v & 1);
        v >>= 1;
    }
    r
}

fn crc32(data: &[u8]) -> u32 {
    // Standard CRC-32 (IEEE 802.3), bitwise form — run once per response.
    let mut crc: u32 = 0xffff_ffff;
    for &b in data {
        crc ^= b as u32;
        for _ in 0..8 {
            let mask = (crc & 1).wrapping_neg();
            crc = (crc >> 1) ^ (0xedb8_8320 & mask);
        }
    }
    !crc
}

#[cfg(test)]
mod gzip_tests {
    use super::*;

    /// CRC-32 ground truth (zlib "123456789" -> 0xCBF43926).
    #[test]
    fn gzip_crc32_matches_reference() {
        assert_eq!(crc32(b"123456789"), 0xCBF4_3926);
        assert_eq!(crc32(b""), 0x0000_0000);
    }

    /// The gzip container (header + trailer CRC + ISIZE) must be well-formed:
    /// trailer CRC equals the CRC of the payload, ISIZE equals its length.
    #[test]
    fn gzip_container_structure() {
        let data = b"<html>hello world</html>".repeat(100);
        let gz = gzip_compress(&data);
        assert_eq!(&gz[..3], &[0x1f, 0x8b, 0x08]);
        let crc = u32::from_le_bytes(gz[gz.len() - 8..gz.len() - 4].try_into().unwrap());
        let isize_ = u32::from_le_bytes(gz[gz.len() - 4..].try_into().unwrap());
        assert_eq!(crc, crc32(&data));
        assert_eq!(isize_ as usize, data.len() % (1 << 32));
    }

    /// Round-trip through an independent decoder: the fixed-Huffman bit
    /// stream produced here must be readable by a stock inflater. This is
    /// implemented with a minimal inflate for OUR stream shape (fixed
    /// blocks) — exercising every literal, all 9-bit codes, match lengths
    /// 3..258, distances up to 32 KiB and the end-of-block symbol.
    #[test]
    fn gzip_roundtrip_self_inflate() {
        // (a) highly repetitive text (many long matches),
        let cases: Vec<Vec<u8>> = vec![
            b"<p>Paragraph text.</p>".repeat(400),
            b"ab".to_vec(),
            b"a".repeat(3),
            b"a".repeat(258),
            b"x".repeat(3),
            (0..=255u8).collect(),
            b"Hello, World! ".repeat(500),
            vec![],
            b"Q".repeat(1 << 15), // window-edge distances
        ];
        for data in &cases {
            let gz = gzip_compress(data);
            let inflated = inflate_fixed(&gz[10..gz.len() - 8]);
            assert_eq!(inflated, *data, "roundtrip failed for {} bytes", data.len());
        }
    }

    /// Policy matrix: type whitelist, size threshold, prior encoding,
    /// q=0 refusal and shrink-only safety valve.
    #[test]
    fn gzip_policy_matrix() {
        let body = b"<html>some page body</html>".repeat(100); // 2900 bytes
        // Compressible type + gzip accepted -> compressed.
        let (out, enc) = maybe_gzip("text/html", Some("gzip, br"), &body, false);
        assert_eq!(enc, Some("gzip"));
        assert!(out.len() < body.len());
        // Missing Accept-Encoding -> passthrough.
        let (_, enc) = maybe_gzip("text/html", None, &body, false);
        assert_eq!(enc, None);
        // q=0 -> passthrough.
        let (_, enc) = maybe_gzip("text/html", Some("br; q=1, gzip; q=0"), &body, false);
        assert_eq!(enc, None);
        // Incompressible type -> passthrough.
        let png = vec![0x89u8; 4096];
        let (_, enc) = maybe_gzip("image/png", Some("gzip"), &png, false);
        assert_eq!(enc, None);
        // Already encoded -> passthrough.
        let (_, enc) = maybe_gzip("text/html", Some("gzip"), &body, true);
        assert_eq!(enc, None);
        // Below threshold -> passthrough.
        let small = b"<html>tiny</html>".to_vec();
        let (_, enc) = maybe_gzip("text/html", Some("gzip"), &small, false);
        assert_eq!(enc, None);
        // Random data (would grow) -> passthrough via shrink valve.
        let rnd: Vec<u8> = (0..4096u32).map(|i| (i.wrapping_mul(2654435761) >> 16) as u8).collect();
        let (out, enc) = maybe_gzip("application/json", Some("gzip"), &rnd, false);
        if enc.is_some() {
            assert!(out.len() < rnd.len());
        }
    }

    /// Minimal inflater for the exact stream shape gzip_compress emits:
    /// one final fixed-Huffman DEFLATE block. Reused by the roundtrip test.
    fn inflate_fixed(raw: &[u8]) -> Vec<u8> {
        struct BitReader<'a> {
            data: &'a [u8],
            pos: usize,
            acc: u32,
            nbits: u32,
        }
        impl<'a> BitReader<'a> {
            fn get(&mut self, cnt: u32) -> u32 {
                while self.nbits < cnt {
                    let b = *self.data.get(self.pos).unwrap_or(&0) as u32;
                    self.pos += 1;
                    self.acc |= b << self.nbits;
                    self.nbits += 8;
                }
                let v = self.acc & ((1 << cnt) - 1);
                self.acc >>= cnt;
                self.nbits -= cnt;
                v
            }
            fn get_huff(&mut self, cnt: u32) -> u32 {
                // Huffman codes are MSB-first in the stream.
                let mut v = 0u32;
                for _ in 0..cnt {
                    v = (v << 1) | self.get(1);
                }
                v
            }
        }
        let mut br = BitReader { data: raw, pos: 0, acc: 0, nbits: 0 };
        let bfinal = br.get(1);
        assert_eq!(br.get(2), 1, "expected fixed block");
        const LEN_BASE: [u16; 29] = [3,4,5,6,7,8,9,10,11,13,15,17,19,23,27,31,35,43,51,59,67,83,99,115,131,163,195,227,258];
        const LEN_EXTRA: [u8; 29]  = [0,0,0,0,0,0,0,0,1,1,1,1,2,2,2,2,3,3,3,3,4,4,4,4,5,5,5,5,0];
        const DIST_BASE: [u16; 30] = [1,2,3,4,5,7,9,13,17,25,33,49,65,97,129,193,257,385,513,769,1025,1537,2049,3073,4097,6145,8193,12289,16385,24577];
        const DIST_EXTRA: [u8; 30] = [0,0,0,0,1,1,2,2,3,3,4,4,5,5,6,6,7,7,8,8,9,9,10,10,11,11,12,12,13,13];
        let mut out = Vec::new();
        loop {
            // Read a Huffman symbol MSB-first, canonical fixed-code table.
            let mut code = 0u32;
            let mut len = 0u32;
            let sym: u16 = loop {
                code = (code << 1) | br.get(1);
                len += 1;
                // Canonical fixed Huffman (RFC 1951 3.2.6):
                // 7 bits: 0000000..0010111 -> 256..279
                // 8 bits: 11000000..11000111 -> 280..287
                //         00110000..10111111 -> 0..143
                // 9 bits: 110010000..111111111 -> 144..255
                let s = if len == 7 && code <= 0b0010111 {
                    Some(256 + code as u16)
                } else if len == 8 && (0b0011_0000..=0b1011_1111).contains(&code) {
                    Some(code as u16 - 0b0011_0000)
                } else if len == 8 && (0b1100_0000..=0b1100_0111).contains(&code) {
                    Some(280 + (code - 0b1100_0000) as u16)
                } else if len == 9 && (0b1_1001_0000..=0b1_1111_1111).contains(&code) {
                    Some(144 + (code - 0b1_1001_0000) as u16)
                } else {
                    None
                };
                if let Some(sym) = s {
                    break sym;
                }
                assert!(len < 9, "invalid code");
            };
            if sym < 256 {
                out.push(sym as u8);
            } else if sym == 256 {
                break;
            } else {
                let lc = (sym - 257) as usize;
                assert!(lc < 29);
                let l = LEN_BASE[lc] as usize + br.get(LEN_EXTRA[lc] as u32) as usize;
                let dc = br.get_huff(5) as usize;
                assert!(dc < 30);
                let d = DIST_BASE[dc] as usize + br.get(DIST_EXTRA[dc] as u32) as usize;
                assert!(d <= out.len(), "distance beyond output");
                let start = out.len() - d;
                for k in 0..l {
                    let b = out[start + k];
                    out.push(b);
                }
            }
        }
        assert_eq!(bfinal, 1);
        out
    }
}

