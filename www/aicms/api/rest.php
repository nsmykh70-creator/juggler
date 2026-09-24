<?php
/**
 * AICMS REST API
 * ==============
 * Public JSON API for managing content (pages, news) via HTTP requests.
 * 
 * Authentication: Bearer token in Authorization header.
 * Token is set in Settings → API.
 *
 * Endpoints:
 *   GET    /api/rest.php/pages          — list all pages
 *   GET    /api/rest.php/pages/{id}     — get single page
 *   POST   /api/rest.php/pages          — create page
 *   PUT    /api/rest.php/pages/{id}     — update page
 *   DELETE /api/rest.php/pages/{id}     — delete page
 *   GET    /api/rest.php/news           — list all news
 *   GET    /api/rest.php/news/{id}      — get single news
 *   POST   /api/rest.php/news           — create news
 *   PUT    /api/rest.php/news/{id}      — update news
 *   DELETE /api/rest.php/news/{id}      — delete news
 *
 * Response format: { "status": "success", "data": ... }
 * Error format: { "status": "error", "message": "..." }
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Load config
$config = json_decode(@file_get_contents(__DIR__ . '/../data/config.json'), true) ?: [];
$api_token = $config['api_token'] ?? '';

if (empty($api_token)) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'API is disabled. Set API token in Settings.']);
    exit;
}

// Authenticate — constant-time comparison prevents timing attacks
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = '';
if (preg_match('/Bearer\s+(.+)/i', $auth_header, $m)) {
    $token = trim($m[1]);
}
if (!hash_equals($api_token, $token)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid API token.']);
    exit;
}

// Rate limit: 120 requests per minute per IP
require_once __DIR__ . '/../admin/core/rate_limit.php';
if (!api_rate_limit('api_rest', 120, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['status' => 'error', 'message' => 'Too Many Requests']);
    exit;
}

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['endpoint'] ?? '';
$id = (int)($_GET['id'] ?? 0);

// Get request body for POST/PUT — limit to 1 MB to prevent DoS
$raw_body = file_get_contents('php://input', false, null, 0, 1024 * 1024);
$body = json_decode($raw_body, true) ?: [];

// Load functions
require_once __DIR__ . '/../admin/core/functions.php';
require_once __DIR__ . '/../admin/core/cache.php';

$lang = $_GET['lang'] ?? ($config['default_lang'] ?? 'ru');

function send_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// === PAGES ===
if ($path === 'pages') {
    if ($method === 'GET' && $id === 0) {
        // List all pages
        $pages = read_content('pages.json', [], $lang);
        send_response(['status' => 'success', 'data' => $pages]);
    }
    if ($method === 'GET' && $id > 0) {
        // Get single page
        $pages = read_content('pages.json', [], $lang);
        foreach ($pages as $p) {
            if ($p['id'] === $id) send_response(['status' => 'success', 'data' => $p]);
        }
        send_response(['status' => 'error', 'message' => 'Page not found'], 404);
    }
    if ($method === 'POST') {
        // Create page
        $pages = read_content('pages.json', [], $lang);
        $max_id = empty($pages) ? 0 : max(array_column($pages, 'id'));
        $new_page = [
            'id' => $max_id + 1,
            'slug' => $body['slug'] ?? 'new-page',
            'title' => $body['title'] ?? 'New Page',
            'content' => $body['content'] ?? '',
            'template' => $body['template'] ?? '',
            'published' => $body['published'] ?? true,
            'status' => $body['status'] ?? 'published',
            'created' => date('Y-m-d H:i:s'),
            'meta_title' => $body['meta_title'] ?? '',
            'meta_description' => $body['meta_description'] ?? '',
            'custom' => $body['custom'] ?? [],
        ];
        $pages[] = $new_page;
        write_content('pages.json', $pages, $lang);
        cache_clear();
        send_response(['status' => 'success', 'data' => $new_page], 201);
    }
    if ($method === 'PUT' && $id > 0) {
        // Update page
        $pages = read_content('pages.json', [], $lang);
        $found = false;
        foreach ($pages as &$p) {
            if ($p['id'] === $id) {
                $p = array_merge($p, $body);
                $p['id'] = $id;
                $found = true;
                break;
            }
        }
        unset($p);
        if (!$found) send_response(['status' => 'error', 'message' => 'Page not found'], 404);
        write_content('pages.json', $pages, $lang);
        cache_clear();
        send_response(['status' => 'success', 'data' => $pages]);
    }
    if ($method === 'DELETE' && $id > 0) {
        // Delete page
        $pages = read_content('pages.json', [], $lang);
        $pages = array_values(array_filter($pages, fn($p) => $p['id'] !== $id));
        write_content('pages.json', $pages, $lang);
        cache_clear();
        send_response(['status' => 'success', 'message' => 'Page deleted']);
    }
}

// === NEWS ===
if ($path === 'news') {
    if ($method === 'GET' && $id === 0) {
        $news = read_content('news.json', [], $lang);
        send_response(['status' => 'success', 'data' => $news]);
    }
    if ($method === 'GET' && $id > 0) {
        $news = read_content('news.json', [], $lang);
        foreach ($news as $n) {
            if ($n['id'] === $id) send_response(['status' => 'success', 'data' => $n]);
        }
        send_response(['status' => 'error', 'message' => 'News not found'], 404);
    }
    if ($method === 'POST') {
        $news = read_content('news.json', [], $lang);
        $max_id = empty($news) ? 0 : max(array_column($news, 'id'));
        $new_news = [
            'id' => $max_id + 1,
            'title' => $body['title'] ?? 'New News',
            'content' => $body['content'] ?? '',
            'date' => $body['date'] ?? date('Y-m-d'),
            'published' => $body['published'] ?? true,
            'status' => $body['status'] ?? 'published',
            'category' => $body['category'] ?? '',
            'tags' => $body['tags'] ?? '',
            'created' => date('Y-m-d H:i:s'),
        ];
        $news[] = $new_news;
        write_content('news.json', $news, $lang);
        cache_clear();
        send_response(['status' => 'success', 'data' => $new_news], 201);
    }
    if ($method === 'PUT' && $id > 0) {
        $news = read_content('news.json', [], $lang);
        $found = false;
        foreach ($news as &$n) {
            if ($n['id'] === $id) {
                $n = array_merge($n, $body);
                $n['id'] = $id;
                $found = true;
                break;
            }
        }
        unset($n);
        if (!$found) send_response(['status' => 'error', 'message' => 'News not found'], 404);
        write_content('news.json', $news, $lang);
        cache_clear();
        send_response(['status' => 'success', 'message' => 'News updated']);
    }
    if ($method === 'DELETE' && $id > 0) {
        $news = read_content('news.json', [], $lang);
        $news = array_values(array_filter($news, fn($n) => $n['id'] !== $id));
        write_content('news.json', $news, $lang);
        cache_clear();
        send_response(['status' => 'success', 'message' => 'News deleted']);
    }
}

send_response(['status' => 'error', 'message' => 'Invalid endpoint'], 400);
