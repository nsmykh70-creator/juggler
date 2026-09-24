<?php
/**
 * AICMS Cron Task — Scheduled Publication
 * ========================================
 * This script processes scheduled pages and news that have reached
 * their publish_at time and publishes them automatically.
 *
 * Can be run via:
 * - Server cron: * * * * * php /path/to/aicms/cron.php
 * - Web request: https://yoursite.com/cron.php?key=SECRET_KEY
 *
 * The key is stored in data/config.json as 'cron_key'.
 * If no key is set, the script runs without authentication (for CLI).
 */

// CLI detection
$is_cli = php_sapi_name() === 'cli';

// Bootstrap
require_once __DIR__ . '/admin/core/functions.php';
require_once __DIR__ . '/admin/core/cache.php';

// Web request authentication
if (!$is_cli) {
    $config = read_json(__DIR__ . '/data/config.json');
    $cron_key = $config['cron_key'] ?? '';
    $provided_key = $_GET['key'] ?? '';
    if (empty($cron_key) || !hash_equals($cron_key, $provided_key)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$now = time();
$published_count = 0;

// Process scheduled pages for all languages
foreach (SUPPORTED_LANGS as $lang_info) {
    $lang_code = $lang_info['code'];
    $pages = read_content('pages.json', [], $lang_code);
    $modified = false;
    
    foreach ($pages as &$p) {
        if (($p['status'] ?? '') === 'scheduled' && !empty($p['publish_at'])) {
            if (strtotime($p['publish_at']) <= $now) {
                $p['status'] = 'published';
                $p['published'] = true;
                $modified = true;
                $published_count++;
            }
        }
    }
    unset($p);
    
    if ($modified) {
        write_content('pages.json', $pages, $lang_code);
    }
}

// Process scheduled news for all languages
foreach (SUPPORTED_LANGS as $lang_info) {
    $lang_code = $lang_info['code'];
    $news = read_content('news.json', [], $lang_code);
    $modified = false;
    
    foreach ($news as &$n) {
        if (($n['status'] ?? '') === 'scheduled' && !empty($n['publish_at'])) {
            if (strtotime($n['publish_at']) <= $now) {
                $n['status'] = 'published';
                $n['published'] = true;
                $modified = true;
                $published_count++;
            }
        }
    }
    unset($n);
    
    if ($modified) {
        write_content('news.json', $news, $lang_code);
    }
}

// Clear cache if anything was published
if ($published_count > 0) {
    cache_clear();
}

// Output
if ($is_cli) {
    echo "Cron: published {$published_count} items at " . date('Y-m-d H:i:s') . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'published' => $published_count,
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
}
