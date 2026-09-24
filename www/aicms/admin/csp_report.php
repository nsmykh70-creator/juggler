<?php
/**
 * AICMS CSP Violation Report Endpoint
 * ================================
 * Receives Content-Security-Policy violation reports via the
 * Reporting-Endpoints / report-uri directive, and logs them to
 * data/logs/csp_violations.log (truncated daily).
 *
 * The endpoint accepts POST with Content-Type: application/json
 * OR application/csp-report (the legacy Chrome format). It does NOT
 * require authentication — CSP reports are sent by the browser, not
 * the user, so they have no session. To prevent abuse:
 *   - Only POST is allowed (everything else → 405).
 *   - Body size capped at 8 KB.
 *   - Rate limited to 60 reports / minute / IP (via the same
 *     api_rate_limit bucket used by the public API).
 *
 * To enable reporting, set in data/config.json:
 *   { "security": { "csp_report": true } }
 *
 * Then add to your CSP header:
 *   report-to csp-endpoint
 *   OR
 *   report-uri /admin/csp_report.php
 */

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// Load config to check if reporting is enabled
$cfg_file = __DIR__ . '/../data/config.json';
$cfg = file_exists($cfg_file) ? json_decode(@file_get_contents($cfg_file), true) : [];
$enabled = !empty($cfg['security']['csp_report']);
if (!$enabled) {
    // Silently accept and discard — avoids tipping off attackers that
    // the endpoint exists but is disabled.
    http_response_code(204);
    exit;
}

// Rate limit (60 reports / minute / IP)
require_once __DIR__ . '/core/rate_limit.php';
if (function_exists('api_rate_limit') && !api_rate_limit('csp_report', 60, 60)) {
    http_response_code(429);
    exit;
}

// Read body (max 8 KB)
$raw = file_get_contents('php://input', false, null, 0, 8192);
if ($raw === false || $raw === '') {
    http_response_code(204);
    exit;
}

$report = json_decode($raw, true);
if (!is_array($report)) {
    // Try legacy format: { "csp-report": { ... } }
    $report = ['csp-report' => json_decode($raw, true) ?: []];
}

// Normalise both new (Reporting API) and legacy (CSP-Report) formats
$violated = $report['csp-report']['violated-directive']
    ?? $report['body']['violated-directive']
    ?? $report['violated-directive']
    ?? 'unknown';
$blocked_uri = $report['csp-report']['blocked-uri']
    ?? $report['body']['blockedURI']
    ?? $report['blockedURI']
    ?? $report['blocked-uri']
    ?? '';
$source_file = $report['csp-report']['source-file']
    ?? $report['body']['sourceFile']
    ?? $report['sourceFile']
    ?? '';
$line_number = $report['csp-report']['line-number']
    ?? $report['body']['lineNumber']
    ?? $report['lineNumber']
    ?? 0;
$document_uri = $report['csp-report']['document-uri']
    ?? $report['body']['documentURL']
    ?? $report['documentURL']
    ?? '';

// Filter out obviously noisy reports (browser extensions, etc.)
// — anything from a chrome-extension:// or moz-extension:// origin
if (preg_match('#^https?://[^/]*extension#i', $blocked_uri)) {
    http_response_code(204);
    exit;
}

// Log to file (truncated daily)
$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
$log_file = $log_dir . '/csp_violations.log';

// Build a single CSV-like log line
$ip = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '-');
$line = sprintf(
    "[%s] %s | directive=%s | blocked=%s | source=%s:%s | document=%s | ua=%s\n",
    date('Y-m-d H:i:s'),
    $ip,
    $violated,
    substr($blocked_uri, 0, 500),
    substr($source_file, 0, 300),
    (int)$line_number,
    substr($document_uri, 0, 300),
    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200)
);

// Append (with file locking) and rotate if file > 5 MB
if (file_exists($log_file) && filesize($log_file) > 5 * 1024 * 1024) {
    @rename($log_file, $log_file . '.' . date('YmdHis'));
}
@file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);

http_response_code(204);
