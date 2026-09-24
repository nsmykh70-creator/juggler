<?php
/**
 * AICMS Maintenance Mode
 * =======================
 * Returns true if the site is currently in maintenance mode.
 *
 * Maintenance mode is enabled in Settings → General OR by creating
 * a file data/.maintenance (one-touch kill switch for emergencies).
 *
 * Authenticated admins always bypass maintenance mode so they can
 * preview the site and disable it from the admin panel.
 */
function aicms_is_maintenance() {
    // Emergency kill switch — file-based, no DB needed
    $kill_file = __DIR__ . '/../../data/.maintenance';
    $file_mode = file_exists($kill_file);

    // Config-based mode
    static $cfg_mode = null;
    if ($cfg_mode === null) {
        $cfg_file = __DIR__ . '/../../data/config.json';
        $cfg = file_exists($cfg_file) ? json_decode(@file_get_contents($cfg_file), true) : [];
        $cfg_mode = is_array($cfg) && !empty($cfg['maintenance']['enabled']);
    }

    if (!$file_mode && !$cfg_mode) return false;

    // Admins bypass maintenance
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['admin'])) return false;

    // Site users (logged in) also bypass — they should still see their content
    if (!empty($_SESSION['site_user_id'])) return false;

    // Allow access to admin login + admin area
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === 'login.php' || strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false) {
        return false;
    }

    return true;
}

/**
 * Render the 503 Service Unavailable page and exit.
 */
function aicms_render_maintenance_page() {
    $cfg_file = __DIR__ . '/../../data/config.json';
    $cfg = file_exists($cfg_file) ? json_decode(@file_get_contents($cfg_file), true) : [];
    $site_title = $cfg['site_title'] ?? 'AICMS';
    $message = $cfg['maintenance']['message'] ?? 'Сайт временно на техническом обслуживании. Скоро вернёмся!';
    $retry_after = (int)($cfg['maintenance']['retry_after'] ?? 300);

    http_response_code(503);
    header('Retry-After: ' . $retry_after);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1.0">'
       . '<meta name="robots" content="noindex">'
       . '<title>' . htmlspecialchars($site_title) . ' — Техническое обслуживание</title>'
       . '<style>'
       . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
       . 'background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);'
       . 'min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0;color:#fff}'
       . '.box{background:rgba(255,255,255,0.1);backdrop-filter:blur(10px);'
       . 'border:1px solid rgba(255,255,255,0.2);border-radius:24px;padding:48px 40px;'
       . 'max-width:480px;text-align:center;box-shadow:0 20px 40px rgba(0,0,0,0.2)}'
       . '.icon{font-size:64px;margin-bottom:20px;opacity:0.9}'
       . 'h1{font-size:28px;margin:0 0 12px;font-weight:700}'
       . 'p{font-size:16px;line-height:1.6;margin:0 0 24px;opacity:0.95}'
       . '.retry{font-size:13px;opacity:0.7}'
       . '</style></head><body>'
       . '<div class="box">'
       . '<div class="icon">⚙️</div>'
       . '<h1>' . htmlspecialchars($site_title) . '</h1>'
       . '<p>' . htmlspecialchars($message) . '</p>'
       . '<div class="retry">Retry-After: ' . $retry_after . 's</div>'
       . '</div></body></html>';
    exit;
}

// If we're in maintenance mode and the current request is for the public site,
// render the maintenance page immediately.
if (aicms_is_maintenance()) {
    aicms_render_maintenance_page();
}
