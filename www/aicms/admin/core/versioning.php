<?php
/**
 * AICMS Versioning System
 * ========================
 * Saves snapshots of pages/news before each edit, allowing rollback.
 *
 * Storage:
 *   data/versions/pages_<lang>/  — files named <id>_<timestamp>.json
 *   data/versions/news_<lang>/   — files named <id>_<timestamp>.json
 *
 * Each version file contains the full item JSON + metadata:
 *   { "__version_meta": { "type":"page", "lang":"ru", "saved_at":"...", "id":5, "title":"..." },
 *     ...original fields... }
 *
 * Keeps max 10 versions per item (oldest auto-deleted).
 */

define('AICMS_MAX_VERSIONS', 10);

function versions_dir() {
    return __DIR__ . '/../data/versions/';
}

function version_save($type, $item, $lang) {
    $dir = versions_dir() . $type . '_' . $lang . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $snapshot = $item;
    $snapshot['__version_meta'] = [
        'type' => $type,
        'lang' => $lang,
        'saved_at' => date('Y-m-d H:i:s'),
        'id' => $item['id'] ?? 0,
        'title' => $item['title'] ?? '',
    ];
    $filename = ($item['id'] ?? 0) . '_' . time() . '.json';
    file_put_contents($dir . $filename, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // Cleanup old versions (keep only MAX_VERSIONS)
    $pattern = $dir . ($item['id'] ?? 0) . '_*.json';
    $files = glob($pattern);
    if (count($files) > AICMS_MAX_VERSIONS) {
        // Sort by modification time, oldest first
        usort($files, function($a, $b) { return filemtime($a) <=> filemtime($b); });
        $to_delete = count($files) - AICMS_MAX_VERSIONS;
        for ($i = 0; $i < $to_delete; $i++) {
            unlink($files[$i]);
        }
    }
    return $filename;
}

function version_list($type, $id, $lang) {
    $dir = versions_dir() . $type . '_' . $lang . '/';
    if (!is_dir($dir)) return [];
    $pattern = $dir . intval($id) . '_*.json';
    $files = glob($pattern);
    $versions = [];
    foreach ($files as $f) {
        $data = json_decode(file_get_contents($f), true);
        if ($data && isset($data['__version_meta'])) {
            $data['__version_meta']['filename'] = basename($f);
            $versions[] = $data;
        }
    }
    // Sort by saved_at, newest first
    usort($versions, function($a, $b) {
        return strtotime($b['__version_meta']['saved_at']) <=> strtotime($a['__version_meta']['saved_at']);
    });
    return $versions;
}

function version_get($filename) {
    $path = versions_dir() . basename($filename);
    if (!file_exists($path)) return null;
    $data = json_decode(file_get_contents($path), true);
    if ($data && isset($data['__version_meta'])) {
        unset($data['__version_meta']);
        return $data;
    }
    return null;
}
