<?php
/**
 * Contact Submissions Poll API
 * Returns JSON with current count of new submissions.
 * Used by admin/contact_submissions.php for auto-refresh polling.
 *
 * GET: ?csrf_token=...&last_seen=N
 * Returns: { new_count, total_count, has_new_since: bool, latest: {...}|null }
 */
require_once __DIR__ . '/core/functions.php';
require_auth();
header('Content-Type: application/json; charset=utf-8');

$submitted_token = $_GET['csrf_token'] ?? '';
if (!verify_csrf_token($submitted_token)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF error']);
    exit;
}

$last_seen = (int)($_GET['last_seen'] ?? 0);

$submissions_file = __DIR__ . '/../data/contact_submissions.json';
$submissions = [];
if (file_exists($submissions_file)) {
    $submissions = json_decode(file_get_contents($submissions_file), true) ?: [];
}

$new_count = 0;
$total_count = count($submissions);
$has_new = false;
$latest = null;

foreach ($submissions as $s) {
    if (($s['status'] ?? '') === 'new') $new_count++;
    $sid = (int)($s['id'] ?? 0);
    if ($sid > $last_seen) {
        $has_new = true;
        if (!$latest || $sid > (int)($latest['id'] ?? 0)) {
            $latest = $s;
        }
    }
}

echo json_encode([
    'new_count'   => $new_count,
    'total_count' => $total_count,
    'has_new'     => $has_new,
    'latest'      => $latest,
], JSON_UNESCAPED_UNICODE);
