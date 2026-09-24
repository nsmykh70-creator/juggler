<?php
/**
 * AICMS Page Cache System
 * ========================
 * Simple file-based HTML cache for frontend pages.
 *
 * How it works:
 *   - Before rendering a page, check if a cached HTML file exists.
 *   - If valid (not expired), serve it directly — skip all PHP rendering.
 *   - If not, render normally and save the output to cache.
 *
 * Cache invalidation:
 *   - cache_clear() clears everything (called on page save, theme change, etc.)
 *   - cache_clear_page($slug) clears a specific page
 *   - TTL-based expiry (default 1 hour)
 *
 * Bypass conditions (never cache):
 *   - Authenticated users (marker cookie aicms_authed set at login)
 *   - POST requests
 *   - Query param ?nocache=1
 *   - Preview mode (?theme_preview=)
 */

define('AICMS_CACHE_DIR', __DIR__ . '/../cache/');
define('AICMS_CACHE_TTL', 3600); // 1 hour

/**
 * Returns the cache file path for the current request.
 */
function cache_file_path() {
    $lang = $_COOKIE['site_lang'] ?? 'ru';
    $slug = $_GET['page'] ?? '';
    if ($slug === '') {
        // Clean-URL mode (no ?page=): derive a stable key from the request path,
        // so /about, /news and /news/article don't collide in one cache slot.
        $path = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        $slug = preg_replace('/[^a-z0-9_-]/i', '-', $path);
        $slug = trim($slug, '-');
        if ($slug === '') $slug = 'home';
    }
    $key = $lang . '_' . preg_replace('/[^a-z0-9_-]/i', '', $slug);
    return AICMS_CACHE_DIR . 'page_' . $key . '.html';
}

/**
 * Check if the current page can be served from cache.
 * Returns the cached HTML or null if cache miss / bypassed.
 */
function cache_get() {
    // Bypass conditions
    // Authenticated users (marker cookie set on login) always get a live page.
    // Session check is kept as a fallback for sessions started elsewhere.
    if (!empty($_COOKIE['aicms_authed'])) return null;
    if (session_status() === PHP_SESSION_ACTIVE && (!empty($_SESSION['admin']) || !empty($_SESSION['site_user_id']))) return null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') return null;
    if (isset($_GET['nocache']) || isset($_GET['theme_preview'])) return null;

    $file = cache_file_path();
    if (!file_exists($file)) return null;

    // Check TTL
    if (time() - filemtime($file) > AICMS_CACHE_TTL) {
        @unlink($file);
        return null;
    }
    return file_get_contents($file);
}

/**
 * Save HTML to cache.
 */
function cache_put($html) {
    // Don't cache authenticated users or bypass conditions
    if (!empty($_COOKIE['aicms_authed'])) return;
    if (session_status() === PHP_SESSION_ACTIVE && (!empty($_SESSION['admin']) || !empty($_SESSION['site_user_id']))) return;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') return; // never cache POST responses (cart, forms)
    if (isset($_GET['nocache']) || isset($_GET['theme_preview'])) return;

    if (!is_dir(AICMS_CACHE_DIR)) {
        @mkdir(AICMS_CACHE_DIR, 0755, true);
    }
    $file = cache_file_path();
    // Cache stampede guard: a concurrent request may have just written a fresh
    // copy while this one was rendering — don't overwrite it needlessly.
    if (file_exists($file) && (time() - @filemtime($file)) < AICMS_CACHE_TTL) return;
    @file_put_contents($file, $html, LOCK_EX);
}

/**
 * Clear the entire cache.
 */
function cache_clear() {
    if (!is_dir(AICMS_CACHE_DIR)) return;
    $files = glob(AICMS_CACHE_DIR . '*.html');
    foreach ($files as $f) {
        @unlink($f);
    }
}

/**
 * Clear a specific page from cache.
 */
function cache_clear_page($slug, $lang = null) {
    if (!is_dir(AICMS_CACHE_DIR)) return;
    // Clear all languages for this slug
    $pattern = AICMS_CACHE_DIR . 'page_*_' . preg_replace('/[^a-z0-9_-]/i', '', $slug) . '.html';
    foreach (glob($pattern) as $f) {
        @unlink($f);
    }
}
