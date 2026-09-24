<?php
/**
 * Rate Limiter
 * Prevents brute-force attacks on login.
 * Stores attempts in data/login_attempts.json
 * 
 * Usage:
 *   rate_limit_check('admin_login');     // returns true if blocked, false if OK
 *   rate_limit_fail('admin_login');      // register failed attempt
 *   rate_limit_reset('admin_login');     // reset after successful login
 */

function _rate_limit_file() {
    return __DIR__ . '/../../data/login_attempts.json';
}

function _rate_limit_load() {
    $file = _rate_limit_file();
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function _rate_limit_save($data) {
    $file = _rate_limit_file();
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Check if the current IP is rate-limited for the given action.
 * Returns ['blocked' => bool, 'wait_seconds' => int, 'attempts' => int]
 */
function rate_limit_check($action = 'admin_login') {
    $ip = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $max_attempts = 5;
    $block_duration = 15 * 60; // 15 minutes
    
    $data = _rate_limit_load();
    $key = $action . ':' . $ip;
    $entry = $data[$key] ?? null;
    
    if (!$entry) {
        return ['blocked' => false, 'wait_seconds' => 0, 'attempts' => 0];
    }
    
    $now = time();
    $first_attempt = $entry['first_attempt'] ?? $now;
    $attempts = $entry['count'] ?? 0;
    
    // If block period has passed, reset
    if ($now - $first_attempt > $block_duration) {
        unset($data[$key]);
        _rate_limit_save($data);
        return ['blocked' => false, 'wait_seconds' => 0, 'attempts' => 0];
    }
    
    // If max attempts exceeded, blocked
    if ($attempts >= $max_attempts) {
        $wait = $block_duration - ($now - $first_attempt);
        return ['blocked' => true, 'wait_seconds' => max(0, $wait), 'attempts' => $attempts];
    }
    
    return ['blocked' => false, 'wait_seconds' => 0, 'attempts' => $attempts];
}

/**
 * Register a failed attempt.
 */
function rate_limit_fail($action = 'admin_login') {
    $ip = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $data = _rate_limit_load();
    $key = $action . ':' . $ip;
    $now = time();
    $block_duration = 15 * 60;
    
    if (!isset($data[$key])) {
        $data[$key] = ['count' => 1, 'first_attempt' => $now];
    } else {
        // Reset if block period passed
        if ($now - $data[$key]['first_attempt'] > $block_duration) {
            $data[$key] = ['count' => 1, 'first_attempt' => $now];
        } else {
            $data[$key]['count']++;
        }
    }
    _rate_limit_save($data);
}

/**
 * Reset attempts after successful login.
 */
function rate_limit_reset($action = 'admin_login') {
    $ip = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $data = _rate_limit_load();
    $key = $action . ':' . $ip;
    unset($data[$key]);
    _rate_limit_save($data);
}

/**
 * Get remaining attempts for current IP.
 */
function rate_limit_remaining($action = 'admin_login') {
    $check = rate_limit_check($action);
    if ($check['blocked']) return 0;
    return max(0, 5 - $check['attempts']);
}

/**
 * Token-bucket rate limiter for API endpoints.
 * Limits the number of requests per time window from a single IP.
 *
 * Usage:
 *   if (!api_rate_limit('api_media', 60, 60)) {
 *       http_response_code(429);
 *       header('Retry-After: 60');
 *       die(json_encode(['status' => 'error', 'message' => 'Too Many Requests']));
 *   }
 *
 * @param string $bucket  Bucket name (e.g. 'api_media', 'api_filemanager')
 * @param int $max_requests  Maximum requests allowed in the window
 * @param int $window_seconds  Window duration in seconds
 * @return bool  true if request is allowed, false if rate limit exceeded
 */
function api_rate_limit($bucket, $max_requests = 60, $window_seconds = 60) {
    $ip = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    // Anonymous sessions get a per-IP bucket; logged-in users get a per-user bucket
    $user_key = !empty($_SESSION['admin']) ? 'u:' . $_SESSION['admin'] : 'ip:' . $ip;
    $key = $bucket . ':' . $user_key;

    $file = __DIR__ . '/../../data/api_rate_limit.json';
    $data = [];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $data = $decoded;
        }
    }

    $now = time();
    $entry = $data[$key] ?? null;

    if (!$entry || !isset($entry['window_start']) || !isset($entry['count'])) {
        // First request in window
        $data[$key] = ['window_start' => $now, 'count' => 1];
    } else {
        // If window expired, reset
        if ($now - $entry['window_start'] >= $window_seconds) {
            $data[$key] = ['window_start' => $now, 'count' => 1];
        } else {
            $data[$key]['count']++;
            if ($data[$key]['count'] > $max_requests) {
                // Rate limit exceeded — save state and return false
                _api_rate_limit_save($data);
                return false;
            }
        }
    }

    _api_rate_limit_save($data);
    return true;
}

function _api_rate_limit_save($data) {
    $file = __DIR__ . '/../../data/api_rate_limit.json';
    // Garbage-collect entries older than 1 hour to keep file small
    $now = time();
    foreach ($data as $k => $v) {
        if (!isset($v['window_start']) || ($now - $v['window_start']) > 3600) {
            unset($data[$k]);
        }
    }
    file_put_contents($file, json_encode($data), LOCK_EX);
}

/**
 * Get current rate limit status for response headers.
 * Returns ['remaining' => N, 'limit' => M, 'reset' => Unix timestamp]
 */
function api_rate_limit_status($bucket, $max_requests = 60, $window_seconds = 60) {
    $ip = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $user_key = !empty($_SESSION['admin']) ? 'u:' . $_SESSION['admin'] : 'ip:' . $ip;
    $key = $bucket . ':' . $user_key;
    $file = __DIR__ . '/../../data/api_rate_limit.json';
    $data = [];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $data = $decoded;
        }
    }
    $entry = $data[$key] ?? ['window_start' => time(), 'count' => 0];
    return [
        'limit' => $max_requests,
        'remaining' => max(0, $max_requests - ($entry['count'] ?? 0)),
        'reset' => ($entry['window_start'] ?? time()) + $window_seconds,
    ];
}
