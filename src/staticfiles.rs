//! Static file serving (nginx `root` equivalent): safe path mapping,
//! script-source blocking, symlink containment, MIME table, and the
//! docroot layout / auto-route merging used at boot and on reload.

use std::collections::HashMap;

use crate::{env_or, RouteConfig, RouteDecision};

// ============================================================================
// Static file serving (nginx `root` equivalent)
// ============================================================================

/// Largest file served from a `static_dir`. Larger files get 413 — static
/// sites are made of assets, not ISOs; streaming large bodies through the
/// single-buffer response path would starve the shard.
pub(crate) const MAX_STATIC_FILE: u64 = 32 * 1024 * 1024;

/// Extension -> Content-Type. Intentionally table-driven, no dependency:
/// covers everything a static site / CMS frontend ships. Unknown extensions
/// default to `application/octet-stream` (browsers download, never sniff).
pub(crate) fn mime_type_of(path: &str) -> &'static str {
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
pub(crate) fn static_path_for(root: &str, suffix: &str) -> Option<String> {
    let suffix = suffix.trim_start_matches('/');
    let rel = if suffix.is_empty() || suffix.ends_with('/') {
        "index.html"
    } else {
        suffix
    };
    // Dotfiles (.htaccess, .env, .git/...) are configuration/secrets, not
    // content; a static route must never hand them out. `.well-known` stays
    // reachable (ACME http-01 and friends live there).
    if rel.is_empty()
        || rel.contains('\0')
        || rel.split('/').any(|seg| {
            seg == ".." || seg.is_empty() || (seg.starts_with('.') && seg != ".well-known")
        })
    {
        return None;
    }
    Some(format!("{}/{}", root.trim_end_matches('/'), rel))
}

/// Extensions that are executed server-side somewhere else (mod_php, CGI,
/// FastCGI, servlet containers). A static route has no interpreter, so
/// serving the raw bytes would disclose source and whatever secrets it
/// embeds (DB credentials in config.php) — the classic "PHP handler
/// missing" misconfiguration. Refuse these outright.
pub(crate) const SCRIPT_SOURCE_EXT: &[&str] = &[
    "php", "phtml", "php3", "php4", "php5", "php7", "php8", "phps", "pht", "asp", "aspx", "ascx",
    "jsp", "jspx", "cgi", "pl", "pm", "py", "rb", "sh", "bash", "zsh", "ps1", "bat", "cmd",
];

pub(crate) fn is_script_source(path: &str) -> bool {
    std::path::Path::new(path)
        .extension()
        .and_then(|e| e.to_str())
        .map(|e| e.to_ascii_lowercase())
        .is_some_and(|e| SCRIPT_SOURCE_EXT.contains(&e.as_str()))
}

/// Build a raw HTTP/1.1 head for a static response. H2 requests get the head
/// stripped by the caller; H1 ships it as-is.
pub(crate) fn static_http_head(
    status_line: &str,
    content_type: &str,
    len: usize,
    keep_alive: bool,
) -> Vec<u8> {
    format!(
        "HTTP/1.1 {}\r\nContent-Type: {}\r\nContent-Length: {}\r\nConnection: {}\r\n\r\n",
        status_line,
        content_type,
        len,
        if keep_alive { "keep-alive" } else { "close" }
    )
    .into_bytes()
}

/// Cache of canonicalized static roots.
///
/// `canonicalize` is a metadata-heavy syscall (~0.3 ms on Windows) and the
/// root changes only when routes are re-applied, so it is memoized per root
/// string. Entries hold no file handles; a stale entry after a root swap is
/// corrected by the per-request containment prefix check.
pub(crate) fn canonical_root(root: &str) -> Option<std::path::PathBuf> {
    use std::collections::HashMap;
    use std::sync::Mutex;
    static CACHE: std::sync::OnceLock<Mutex<HashMap<String, Option<std::path::PathBuf>>>> =
        std::sync::OnceLock::new();
    let cache = CACHE.get_or_init(|| Mutex::new(HashMap::new()));
    let mut map = cache.lock().unwrap_or_else(|p| p.into_inner());
    if map.len() > 64 {
        map.clear();
    }
    map.entry(root.to_string()).or_insert_with(|| std::fs::canonicalize(root).ok()).clone()
}

/// Serve one file from a `static_dir`. Ordering matters for security:
/// 1. resolve the logical path and reject traversal syntactically;
/// 2. canonicalize — the OS resolves symlinks, so a link pointing outside
///    the root also fails the prefix check (nginx open-at()/chroot levels);
/// 3. prefix-check the canonical path against the canonical root.
pub(crate) fn serve_static_file(root: &str, suffix: &str, keep_alive: bool) -> RouteDecision {
    let Some(path) = static_path_for(root, suffix) else {
        return RouteDecision::StaticFile(
            static_http_head("403 Forbidden", "text/plain; charset=utf-8", 9, keep_alive),
            b"forbidden".to_vec(),
        );
    };
    // No interpreter behind a static route → no source download either.
    if is_script_source(&path) {
        return RouteDecision::StaticFile(
            static_http_head("403 Forbidden", "text/plain; charset=utf-8", 9, keep_alive),
            b"forbidden".to_vec(),
        );
    }
    let canon_root = canonical_root(root);
    match std::fs::read(&path) {
        Ok(body) => {
            // Containment re-check on the real path (symlink escape). One
            // canonicalize covers both jobs: if the file resolves to a link
            // outside the (canonical) root, the prefix check fails. If the
            // file itself vanished mid-flight, fall back to the pre-open
            // syntactic containment from static_path_for.
            let contained = canon_root
                .as_ref()
                .and_then(|r| std::fs::canonicalize(&path).ok().map(|p| (p, r.clone())))
                .map(|(p, r)| {
                    p.as_os_str().as_encoded_bytes().starts_with(r.as_os_str().as_encoded_bytes())
                })
                .unwrap_or(false);
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
            RouteDecision::StaticFile(static_http_head("200 OK", ct, body.len(), keep_alive), body)
        }
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => RouteDecision::NotFound,
        Err(e) if e.kind() == std::io::ErrorKind::PermissionDenied => RouteDecision::StaticFile(
            static_http_head("403 Forbidden", "text/plain; charset=utf-8", 9, keep_alive),
            b"forbidden".to_vec(),
        ),
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
            static_http_head(
                "500 Internal Server Error",
                "text/plain; charset=utf-8",
                15,
                keep_alive,
            ),
            b"internal error".to_vec(),
        ),
    }
}

/// Default document root: `www/` next to the binary's working directory,
/// with one sample site per `sample_sitesN` directory so a fresh install
/// has something to serve immediately (nginx/htdocs convention).
pub(crate) fn default_static_root() -> String {
    env_or("JUGGLER_WWW_DIR", "www")
}

/// Idempotently create the document root with `sample_sitesN` folders, each
/// containing an index.html. Returns how many sample dirs were created.
pub(crate) fn ensure_www_layout(root: &str) -> usize {
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
pub(crate) fn merge_static_routes(
    root: &str,
    mut routes: HashMap<String, RouteConfig>,
) -> HashMap<String, RouteConfig> {
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
        // Only *add* docroot folders that the config does not already define;
        // an explicit admin/gRPC route (e.g. a proxy to a PHP container) must
        // win over the implicit "this directory name is a docroot" guess.
        if let std::collections::hash_map::Entry::Vacant(e) = routes.entry(path) {
            e.insert(RouteConfig {
                static_dir: Some(entry.path().to_string_lossy().into_owned()),
                ..RouteConfig::default()
            });
        }
    }
    routes
}

