<?php
/**
 * AICMS Trash (Recycle Bin) System
 * =================================
 * When a page or news item is deleted, it's moved to trash/ instead of being
 * permanently removed. Admins can restore or permanently delete from trash.
 *
 * Trash structure:
 *   data/trash/pages_<lang>/  — JSON files named <id>_<timestamp>.json
 *   data/trash/news_<lang>/   — JSON files named <id>_<timestamp>.json
 *
 * Each trash file contains the full item JSON + metadata:
 *   { "__trash_meta": { "type":"page", "lang":"ru", "deleted_at":"...", "original_id":5 },
 *     ...original fields... }
 */

function trash_dir() {
    return __DIR__ . '/../data/trash/';
}

function trash_move($type, $item, $lang) {
    $dir = trash_dir();
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $item['__trash_meta'] = [
        'type' => $type,
        'lang' => $lang,
        'deleted_at' => date('Y-m-d H:i:s'),
        'original_id' => $item['id'] ?? 0,
        'title' => $item['title'] ?? '',
    ];
    $filename = $type . '_' . $lang . '_' . ($item['id'] ?? 0) . '_' . time() . '.json';
    file_put_contents($dir . $filename, json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $filename;
}

function trash_list() {
    $dir = trash_dir();
    if (!is_dir($dir)) return [];
    $files = glob($dir . '*.json');
    $items = [];
    foreach ($files as $f) {
        $data = json_decode(file_get_contents($f), true);
        if ($data && isset($data['__trash_meta'])) {
            $data['__trash_meta']['filename'] = basename($f);
            $items[] = $data;
        }
    }
    // Sort by deletion date, newest first
    usort($items, function($a, $b) {
        return strtotime($b['__trash_meta']['deleted_at']) <=> strtotime($a['__trash_meta']['deleted_at']);
    });
    return $items;
}

function trash_restore($filename) {
    $path = trash_dir() . basename($filename);
    if (!file_exists($path)) return false;
    $data = json_decode(file_get_contents($path), true);
    if (!$data || !isset($data['__trash_meta'])) return false;

    $meta = $data['__trash_meta'];
    unset($data['__trash_meta']);

    // Determine content path
    require_once __DIR__ . '/functions.php';
    $content_file = ($meta['type'] === 'page') ? 'pages.json' : 'news.json';
    $items = read_content($content_file, [], $meta['lang']);

    // Check if ID still exists (assign new ID if taken)
    $existing_ids = array_column($items, 'id');
    if (in_array($meta['original_id'], $existing_ids)) {
        $data['id'] = empty($items) ? 1 : (max($existing_ids) + 1);
    } else {
        $data['id'] = $meta['original_id'];
    }
    $items[] = $data;
    write_content($content_file, $items, $meta['lang']);

    unlink($path);
    return $data['id'];
}

function trash_delete_permanent($filename) {
    $path = trash_dir() . basename($filename);
    if (file_exists($path)) {
        unlink($path);
        return true;
    }
    return false;
}

function trash_empty() {
    $dir = trash_dir();
    if (!is_dir($dir)) return;
    foreach (glob($dir . '*.json') as $f) {
        unlink($f);
    }
}
