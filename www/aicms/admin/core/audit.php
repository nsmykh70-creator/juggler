<?php
/**
 * AICMS Audit Log
 * Enhanced with JSON storage, pagination, filtering.
 */
function audit_log($action, $details = '') {
    $logDir = __DIR__ . '/../../logs';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    $logFile = $logDir . '/audit.json';
    $entry = [
        'ts' => date('Y-m-d H:i:s'),
        'user' => $_SESSION['admin'] ?? ($_SESSION['site_user_id'] ? 'user:'.$_SESSION['site_user_id'] : 'guest'),
        'ip' => function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? 'cli'),
        'action' => $action,
        'details' => $details,
    ];
    $logs = [];
    if (file_exists($logFile)) {
        $logs = json_decode(file_get_contents($logFile), true) ?: [];
    }
    $logs[] = $entry;
    // Keep last 1000 entries
    if (count($logs) > 1000) $logs = array_slice($logs, -1000);
    file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    // Also write to text log (backward compat)
    @file_put_contents($logDir . '/audit.log', "[{$entry['ts']}] [{$entry['user']}] [{$entry['ip']}] [{$action}] {$details}\n", FILE_APPEND | LOCK_EX);
}

function audit_log_read($limit = 100, $offset = 0) {
    $logFile = __DIR__ . '/../../logs/audit.json';
    if (!file_exists($logFile)) return [];
    $logs = json_decode(file_get_contents($logFile), true) ?: [];
    $logs = array_reverse($logs); // newest first
    return array_slice($logs, $offset, $limit);
}

function audit_log_count() {
    $logFile = __DIR__ . '/../../logs/audit.json';
    if (!file_exists($logFile)) return 0;
    $logs = json_decode(file_get_contents($logFile), true) ?: [];
    return count($logs);
}

/**
 * Clear all audit log entries.
 * Returns true on success, false on failure.
 */
function audit_log_clear() {
    $logFile = __DIR__ . '/../../logs/audit.json';
    $logTextFile = __DIR__ . '/../../logs/audit.log';
    $r1 = file_put_contents($logFile, '[]', LOCK_EX);
    // Also clear text log
    @file_put_contents($logTextFile, '', LOCK_EX);
    // Log the clear action itself (so admin sees that log was cleared)
    audit_log('audit_clear', 'Журнал очищен администратором');
    return $r1 !== false;
}

/**
 * Delete audit log entries older than N days.
 * @param int $days  Number of days to keep (default 30)
 * @return int  Number of entries deleted
 */
function audit_log_cleanup($days = 30) {
    $logFile = __DIR__ . '/../../logs/audit.json';
    if (!file_exists($logFile)) return 0;
    $logs = json_decode(file_get_contents($logFile), true) ?: [];
    $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
    $original = count($logs);
    $logs = array_filter($logs, function($entry) use ($cutoff) {
        return $entry['ts'] >= $cutoff;
    });
    $logs = array_values($logs);
    file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $original - count($logs);
}

/**
 * Return a Font Awesome icon class for a given audit action.
 * Used by dashboard widget and audit log page.
 */
function audit_action_icon($action) {
    $action = strtolower((string)$action);
    if (strpos($action, 'create') !== false || strpos($action, 'add') !== false) return 'fa-plus';
    if (strpos($action, 'edit') !== false || strpos($action, 'update') !== false || strpos($action, 'save') !== false) return 'fa-pen';
    if (strpos($action, 'delete') !== false || strpos($action, 'remove') !== false || strpos($action, 'trash') !== false) return 'fa-trash';
    if (strpos($action, 'login') !== false || strpos($action, 'logout') !== false) return 'fa-sign-in-alt';
    if (strpos($action, 'upload') !== false) return 'fa-upload';
    if (strpos($action, 'download') !== false) return 'fa-download';
    if (strpos($action, 'settings') !== false || strpos($action, 'config') !== false) return 'fa-cog';
    if (strpos($action, 'theme') !== false) return 'fa-palette';
    if (strpos($action, 'user') !== false || strpos($action, 'admin') !== false) return 'fa-user';
    if (strpos($action, 'backup') !== false) return 'fa-history';
    if (strpos($action, 'cache') !== false) return 'fa-broom';
    if (strpos($action, 'audit') !== false) return 'fa-clipboard-list';
    return 'fa-info-circle';
}
