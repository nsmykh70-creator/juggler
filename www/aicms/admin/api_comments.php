<?php
/**
 * Comments Poll API
 * Returns JSON with current count of pending comments.
 * Used by aicms-ui.js for real-time polling on all admin pages.
 *
 * GET: ?csrf_token=...
 * Returns: { new_count, total_count, has_new: bool, latest: {...}|null }
 */
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/extensions.php';
require_auth();
header('Content-Type: application/json; charset=utf-8');

$submitted_token = $_GET['csrf_token'] ?? '';
if (!verify_csrf_token($submitted_token)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF error']);
    exit;
}

$new_count = 0;
$total_count = 0;
$latest = null;

if (extension_enabled('comments') && db_available()) {
    try {
        $new_count = db_count('comments', 'status = ?', ['pending']);
        $total_count = db_count('comments', '', []);
        if ($new_count > 0) {
            $latest = db_row('SELECT * FROM ' . db_table('comments') . ' WHERE status = ? ORDER BY created_at DESC LIMIT 1', ['pending']);
        }
    } catch (Exception $e) {}
}

echo json_encode([
    'new_count'   => (int)$new_count,
    'total_count' => (int)$total_count,
    'has_new'     => $new_count > 0,
    'latest'      => $latest,
], JSON_UNESCAPED_UNICODE);
