<?php
/**
 * Global search API for admin topbar + advanced search page.
 * Returns JSON results across pages, news, products, users, media, templates, modules.
 *
 * Each result includes:
 *   - url: admin URL to edit/view the item
 *   - title: item title
 *   - type: localized type label
 *   - icon: FontAwesome class
 *   - snippet: 1-line excerpt showing the matched context (HTML stripped, term highlighted)
 *   - lang: language code (for pages/news)
 */
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/db.php';
require_auth();
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) { echo '[]'; exit; }

// Rate limit: 30 searches per minute per admin
if (!api_rate_limit('admin_search', 30, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['error' => 'Too many search requests']);
    exit;
}

$results = [];
$q_lower = mb_strtolower($q);
$limit = 30;  // overall cap

/**
 * Build a snippet around the match (200 chars max, with highlighted term).
 */
function _search_snippet($content, $q) {
    if (empty($content)) return '';
    // Strip HTML tags
    $text = trim(strip_tags($content));
    // Collapse whitespace
    $text = preg_replace('/\s+/', ' ', $text);
    if (empty($text)) return '';
    $pos = mb_stripos($text, $q);
    if ($pos === false) {
        // No match in content — return first 160 chars
        return mb_substr($text, 0, 160) . (mb_strlen($text) > 160 ? '…' : '');
    }
    // Show 80 chars before, term, 80 chars after
    $start = max(0, $pos - 80);
    $snippet = ($start > 0 ? '… ' : '') . mb_substr($text, $start, 200) . (mb_strlen($text) > $start + 200 ? '…' : '');
    // Highlight the matched term (case-insensitive)
    $snippet = preg_replace('/(' . preg_quote($q, '/') . ')/i', '<mark>$1</mark>', htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8'));
    return $snippet;
}

// ===== 1. Search pages (title, slug, content) =====
foreach (SUPPORTED_LANGS as $lang_info) {
    $pages = read_content('pages.json', [], $lang_info['code']);
    foreach ($pages as $p) {
        $title_match = mb_strpos(mb_strtolower($p['title'] ?? ''), $q_lower) !== false;
        $slug_match = mb_strpos(mb_strtolower($p['slug'] ?? ''), $q_lower) !== false;
        $content_match = mb_strpos(mb_strtolower(strip_tags($p['content'] ?? '')), $q_lower) !== false;
        if ($title_match || $slug_match || $content_match) {
            $results[] = [
                'url' => 'edit_page.php?id=' . $p['id'] . '&lang=' . $lang_info['code'],
                'title' => $p['title'] ?: $p['slug'],
                'type' => 'Страница (' . $lang_info['code'] . ')',
                'icon' => 'fa-file-alt',
                'snippet' => _search_snippet($p['content'] ?? '', $q),
                'lang' => $lang_info['code'],
            ];
            if (count($results) >= $limit) break 2;
        }
    }
}

// ===== 2. Search news (title, tags, content) =====
foreach (SUPPORTED_LANGS as $lang_info) {
    $news = read_content('news.json', [], $lang_info['code']);
    foreach ($news as $n) {
        $title_match = mb_strpos(mb_strtolower($n['title'] ?? ''), $q_lower) !== false;
        $tags_match = mb_strpos(mb_strtolower($n['tags'] ?? ''), $q_lower) !== false;
        $content_match = mb_strpos(mb_strtolower(strip_tags($n['content'] ?? '')), $q_lower) !== false;
        if ($title_match || $tags_match || $content_match) {
            $results[] = [
                'url' => 'edit_news.php?id=' . $n['id'],
                'title' => $n['title'],
                'type' => 'Новость (' . $lang_info['code'] . ')',
                'icon' => 'fa-newspaper',
                'snippet' => _search_snippet($n['content'] ?? '', $q),
                'lang' => $lang_info['code'],
                'date' => $n['date'] ?? '',
            ];
            if (count($results) >= $limit) break 2;
        }
    }
}

// ===== 3. Search media files (filename, alt) =====
$uploads_dir = __DIR__ . '/../uploads';
if (is_dir($uploads_dir)) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads_dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    $media_count = 0;
    foreach ($rii as $file) {
        if ($file->isFile() && mb_strpos(mb_strtolower($file->getFilename()), $q_lower) !== false) {
            $rel = 'uploads/' . ltrim(str_replace($uploads_dir, '', $file->getPathname()), '/');
            $results[] = [
                'url' => 'media.php#file=' . urlencode($file->getFilename()),
                'title' => $file->getFilename(),
                'type' => 'Файл · ' . formatBytes($file->getSize()),
                'icon' => 'fa-image',
                'snippet' => 'Path: ' . $rel,
            ];
            $media_count++;
            if ($media_count >= 5 || count($results) >= $limit) break;
        }
    }
}

// ===== 4. Search templates (filename + content) =====
$themes_dir = __DIR__ . '/../themes';
if (is_dir($themes_dir)) {
    foreach (glob($themes_dir . '/*') as $theme_dir) {
        if (!is_dir($theme_dir)) continue;
        $theme_name = basename($theme_dir);
        foreach (glob($theme_dir . '/*.{html,css,js,php}', GLOB_BRACE) as $tpl_file) {
            $tpl_name = basename($tpl_file);
            $name_match = mb_strpos(mb_strtolower($tpl_name), $q_lower) !== false;
            $content = @file_get_contents($tpl_file);
            $content_match = $content !== false && mb_strpos(mb_strtolower($content), $q_lower) !== false;
            if ($name_match || $content_match) {
                $results[] = [
                    'url' => 'templates.php?theme=' . urlencode($theme_name) . '&file=' . urlencode($tpl_name),
                    'title' => $tpl_name,
                    'type' => 'Шаблон · ' . $theme_name,
                    'icon' => 'fa-code',
                    'snippet' => _search_snippet($content, $q),
                ];
                if (count($results) >= $limit) break 2;
            }
        }
    }
}

// ===== 5. Search DB-backed items (products, users, comments) =====
if (db_available()) {
    // Products
    try {
        $products = db_select('shop_products', 'id, title, sku, description', '', [], 'title ASC', 100);
        foreach ($products as $p) {
            $title_match = mb_strpos(mb_strtolower($p['title']), $q_lower) !== false;
            $sku_match = mb_strpos(mb_strtolower($p['sku'] ?? ''), $q_lower) !== false;
            $desc_match = mb_strpos(mb_strtolower($p['description'] ?? ''), $q_lower) !== false;
            if ($title_match || $sku_match || $desc_match) {
                $results[] = [
                    'url' => 'shop.php?tab=products&edit=' . $p['id'],
                    'title' => $p['title'],
                    'type' => 'Товар' . (!empty($p['sku']) ? ' · ' . $p['sku'] : ''),
                    'icon' => 'fa-box',
                    'snippet' => _search_snippet($p['description'] ?? '', $q),
                ];
                if (count($results) >= $limit) break;
            }
        }
    } catch (Exception $e) {}

    // Users
    try {
        $users = db_select('users', 'id, username, email, display_name', '', [], 'username ASC', 100);
        foreach ($users as $u) {
            if (mb_strpos(mb_strtolower($u['username']), $q_lower) !== false ||
                mb_strpos(mb_strtolower($u['email']), $q_lower) !== false ||
                mb_strpos(mb_strtolower($u['display_name'] ?? ''), $q_lower) !== false) {
                $results[] = [
                    'url' => 'users.php?edit=' . $u['id'],
                    'title' => $u['display_name'] ?: $u['username'],
                    'type' => 'Пользователь · ' . $u['email'],
                    'icon' => 'fa-user',
                    'snippet' => '@' . $u['username'] . ' · ' . $u['email'],
                ];
                if (count($results) >= $limit) break;
            }
        }
    } catch (Exception $e) {}

    // Comments
    try {
        $comments = db_select('comments', 'id, target_id, author_name, content, status', '', [], 'id DESC', 200);
        foreach ($comments as $c) {
            if (mb_strpos(mb_strtolower($c['content'] ?? ''), $q_lower) !== false ||
                mb_strpos(mb_strtolower($c['author_name'] ?? ''), $q_lower) !== false) {
                $results[] = [
                    'url' => 'comments.php?highlight=' . $c['id'],
                    'title' => $c['author_name'] ?: 'Гость',
                    'type' => 'Комментарий · ' . ($c['status'] ?? 'pending'),
                    'icon' => 'fa-comment',
                    'snippet' => _search_snippet($c['content'], $q),
                ];
                if (count($results) >= $limit) break;
            }
        }
    } catch (Exception $e) {}

    // Contact submissions
    try {
        $subs = db_select('contact_submissions', 'id, name, email, message, status', '', [], 'id DESC', 200);
        foreach ($subs as $s) {
            if (mb_strpos(mb_strtolower($s['message'] ?? ''), $q_lower) !== false ||
                mb_strpos(mb_strtolower($s['name']), $q_lower) !== false) {
                $results[] = [
                    'url' => 'contact_submissions.php?highlight=' . $s['id'],
                    'title' => $s['name'],
                    'type' => 'Сообщение · ' . $s['email'],
                    'icon' => 'fa-envelope',
                    'snippet' => _search_snippet($s['message'], $q),
                ];
                if (count($results) >= $limit) break;
            }
        }
    } catch (Exception $e) {}
}

// Sort: title matches first, then content matches
usort($results, function($a, $b) use ($q_lower) {
    $a_title = mb_strpos(mb_strtolower($a['title']), $q_lower) !== false;
    $b_title = mb_strpos(mb_strtolower($b['title']), $q_lower) !== false;
    if ($a_title && !$b_title) return -1;
    if (!$a_title && $b_title) return 1;
    return strcmp($a['title'], $b['title']);
});

echo json_encode($results, JSON_UNESCAPED_UNICODE);

/**
 * Format bytes as human-readable.
 */
function formatBytes($bytes, $precision = 1) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}
