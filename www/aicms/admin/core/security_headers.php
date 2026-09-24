<?php
/**
 * Security Headers
 * =================
 * Sends standard security HTTP headers to harden the site against
 * XSS, clickjacking, MIME-sniffing, and protocol downgrade attacks.
 *
 * Content-Security-Policy is generated dynamically to allow:
 *   - Same-origin scripts/styles/images/fonts
 *   - Common CDNs used by the CMS (Tailwind, FontAwesome, Google Fonts, Monaco, YouTube, Yandex Maps)
 *   - Inline styles (required for theme templates + many modules)
 *   - 'unsafe-eval' for Monaco editor (admin only — disabled on public site)
 *
 * Usage:
 *   require_once __DIR__.'/security_headers.php';
 *   send_security_headers(['admin' => false]);
 *
 * The function MUST be called BEFORE any output (headers_sent() check).
 */

/**
 * Send security headers.
 *
 * @param array $opts  Options: 'admin' (bool), 'csp_enabled' (bool, default true)
 */
function send_security_headers($opts = []) {
    if (headers_sent()) return;

    $is_admin = !empty($opts['admin']);
    $csp_enabled = $opts['csp_enabled'] ?? true;

    // --- Always-on basic security headers ---

    // Prevent MIME-sniffing — browser must respect declared Content-Type
    header('X-Content-Type-Options: nosniff');

    // Prevent clickjacking — site must not be framed
    // (Admin allows same-origin framing for theme preview iframes; public site denies all)
    if ($is_admin) {
        header('X-Frame-Options: SAMEORIGIN');
    } else {
        header('X-Frame-Options: SAMEORIGIN');  // keep SAMEORIGIN everywhere to allow own embeds
    }

    // HSTS — force HTTPS for 1 year (only sent over HTTPS in practice)
    // Disable in dev (HTTP) by checking if HTTPS is on
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }

    // Referrer-Policy: send origin only on cross-origin requests, full URL same-origin
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Permissions-Policy: lock down sensitive browser features
    header('Permissions-Policy: geolocation=(self), microphone=(), camera=(), payment=(), usb=()');

    // --- Content-Security-Policy ---
    if (!$csp_enabled) return;

    if ($is_admin) {
        // Admin needs:
        //   - 'unsafe-eval' for Monaco editor
        //   - 'unsafe-inline' for styles (Tailwind + admin styles)
        //   - blob: for Monaco workers + image previews
        //   - data: for inline images in editor
        $csp_directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://unpkg.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net",
            "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com",
            "img-src 'self' data: blob: https: http:",
            "media-src 'self' data: blob: https:",
            "frame-src 'self' https://www.youtube.com https://player.vimeo.com https://maps.google.com https://yandex.ru",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "connect-src 'self' https:",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
        ];
    } else {
        // Public site: stricter policy
        //   - No 'unsafe-eval' (Monaco not loaded)
        //   - Inline styles allowed (theme templates rely on them)
        //   - Inline scripts restricted — but themes/modules may use them, so allow 'unsafe-inline' for now
        //     TODO: replace inline scripts with nonces for full CSP hardening
        $csp_directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://unpkg.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net",
            "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com",
            "img-src 'self' data: blob: https: http:",
            "media-src 'self' data: blob: https:",
            "frame-src 'self' https://www.youtube.com https://player.vimeo.com https://maps.google.com https://yandex.ru",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "connect-src 'self' https:",
            "worker-src 'self'",
            "manifest-src 'self'",
        ];
    }

    // Allow admins to see violations in console (Report-Only mode) during rollout
    // Set REPORT_ONLY=true in data/config.json to enable
    static $_cfg_cache = null;
    if ($_cfg_cache === null) {
        $cfg_file = __DIR__ . '/../../data/config.json';
        if (file_exists($cfg_file)) {
            $raw = @file_get_contents($cfg_file);
            $_cfg_cache = $raw ? json_decode($raw, true) : [];
        } else {
            $_cfg_cache = [];
        }
    }
    $csp_report_only = !empty($_cfg_cache['csp_report_only']);
    $csp_report_enabled = !empty($_cfg_cache['security']['csp_report']);

    // If CSP reporting is enabled, append report-uri / report-to directives
    if ($csp_report_enabled) {
        $csp_directives[] = "report-uri /admin/csp_report.php";
        $csp_directives[] = "report-to csp-endpoint";
        // Reporting-Endpoints header (newer spec — single endpoint named "csp-endpoint")
        if (!headers_sent()) {
            header('Reporting-Endpoints: csp-endpoint="/admin/csp_report.php"');
        }
    }

    $csp_header_value = implode('; ', $csp_directives);
    $csp_header_name = $csp_report_only ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
    header($csp_header_name . ': ' . $csp_header_value);
}
