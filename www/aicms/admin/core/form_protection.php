<?php
/**
 * Public Form Protection
 * ========================
 * Centralized rate limiting + honeypot anti-spam + time-trap for public-facing forms
 * (comments, booking, contact_form, newsletter, forum).
 *
 * Why a separate helper from admin/core/rate_limit.php?
 *   - Public forms have different limits (per-action, looser)
 *   - Honeypot + time-trap are public-form-specific anti-bot measures
 *   - Centralized file storage avoids scattered rate_limit_*.json files
 *
 * Usage in a module:
 *   require_once __DIR__.'/../admin/core/form_protection.php';
 *   $guard = form_guard_check('newsletter');   // ['ok' => bool, 'message' => string]
 *   if (!$guard['ok']) { $error = $guard['message']; }
 *   else {
 *       // ... process form ...
 *       form_guard_success('newsletter');        // optional: count successful submits too
 *   }
 *
 *   // In the form HTML, add honeypot field:
 *   echo form_honeypot_field();
 *
 *   // And a time-trap (hidden timestamp field, must be > 3s old):
 *   echo form_timetrap_field();
 *
 *   // On submit, verify:
 *   if (!form_honeypot_ok() || !form_timetrap_ok()) {
 *       // silently drop, but show success to confuse bots
 *       $sent = true; return;
 *   }
 */

define('FORM_GUARD_FILE', __DIR__ . '/../../data/form_guard.json');
define('FORM_GUARD_HONEYPOT_FIELD', 'website_url');   // looks legit to bots
define('FORM_GUARD_TIMETRAP_FIELD', 'form_loaded_at');
define('FORM_GUARD_MIN_SUBMIT_SECONDS', 3);           // bots submit too fast

/**
 * Default per-action rate limits (max submissions, window seconds).
 */
function form_guard_defaults() {
    return [
        'comment'    => ['max' => 5,  'window' => 300],   // 5 / 5 min
        'booking'    => ['max' => 3,  'window' => 600],   // 3 / 10 min
        'contact'    => ['max' => 5,  'window' => 600],   // 5 / 10 min
        'newsletter' => ['max' => 3,  'window' => 3600],  // 3 / hour
        'forum'      => ['max' => 10, 'window' => 600],   // 10 / 10 min
        'shop_order' => ['max' => 3,  'window' => 600],   // 3 orders / 10 min
        'shop_review'=> ['max' => 5,  'window' => 600],   // 5 reviews / 10 min
        'default'    => ['max' => 5,  'window' => 600],
    ];
}

function _form_guard_load() {
    if (!file_exists(FORM_GUARD_FILE)) return [];
    $raw = @file_get_contents(FORM_GUARD_FILE);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function _form_guard_save($data) {
    // GC: drop entries older than 1 hour
    $now = time();
    foreach ($data as $k => $v) {
        if (!isset($v['first']) || ($now - $v['first']) > 3600) unset($data[$k]);
    }
    @file_put_contents(FORM_GUARD_FILE, json_encode($data), LOCK_EX);
}

/**
 * Check rate limit for a public form action.
 * Returns ['ok' => bool, 'message' => string, 'remaining' => int, 'retry_after' => int]
 */
function form_guard_check($action) {
    $ip = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $defaults = form_guard_defaults();
    $cfg = $defaults[$action] ?? $defaults['default'];
    $max = $cfg['max'];
    $window = $cfg['window'];

    $data = _form_guard_load();
    $key = $action . ':' . $ip;
    $now = time();

    $entry = $data[$key] ?? null;
    if (!$entry || ($now - $entry['first']) > $window) {
        // Fresh window — allowed, but don't increment yet (do that on success/fail)
        return ['ok' => true, 'message' => '', 'remaining' => $max, 'retry_after' => 0, '_key' => $key, '_first' => $now, '_count' => 0];
    }

    $count = $entry['count'] ?? 0;
    if ($count >= $max) {
        $retry = max(1, $window - ($now - $entry['first']));
        return [
            'ok' => false,
            'message' => 'Слишком много отправлений формы. Попробуйте через ' . ceil($retry / 60) . ' мин.',
            'remaining' => 0,
            'retry_after' => $retry,
        ];
    }

    return [
        'ok' => true,
        'message' => '',
        'remaining' => $max - $count,
        'retry_after' => 0,
        '_key' => $key,
        '_first' => $entry['first'],
        '_count' => $count,
    ];
}

/**
 * Register a submission (called BEFORE form processing to count attempts).
 */
function form_guard_register($action) {
    $ip = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $defaults = form_guard_defaults();
    $cfg = $defaults[$action] ?? $defaults['default'];
    $window = $cfg['window'];

    $data = _form_guard_load();
    $key = $action . ':' . $ip;
    $now = time();

    if (!isset($data[$key]) || ($now - $data[$key]['first']) > $window) {
        $data[$key] = ['first' => $now, 'count' => 1];
    } else {
        $data[$key]['count']++;
    }
    _form_guard_save($data);
}

/**
 * Render honeypot field — hidden from humans, visible to bots.
 * Returns HTML.
 */
function form_honeypot_field() {
    $name = FORM_GUARD_HONEYPOT_FIELD;
    return '<div style="position:absolute;left:-9999px;top:-9999px;opacity:0;pointer-events:none;" aria-hidden="true">' .
           '<label>Website (leave empty)<input type="text" name="' . $name . '" value="" tabindex="-1" autocomplete="off"></label>' .
           '</div>';
}

/**
 * Render time-trap field — records when form was loaded.
 * Returns HTML.
 */
function form_timetrap_field() {
    $name = FORM_GUARD_TIMETRAP_FIELD;
    return '<input type="hidden" name="' . $name . '" value="' . time() . '">';
}

/**
 * Check honeypot — if filled, it's a bot.
 * Returns true if OK (empty), false if bot detected.
 */
function form_honeypot_ok() {
    $val = trim($_POST[FORM_GUARD_HONEYPOT_FIELD] ?? '');
    return $val === '';
}

/**
 * Check time-trap — if form submitted too fast, likely a bot.
 * Returns true if OK, false if too fast.
 */
function form_timetrap_ok() {
    $loaded = (int)($_POST[FORM_GUARD_TIMETRAP_FIELD] ?? 0);
    if ($loaded <= 0) return true;  // not enabled, allow
    return (time() - $loaded) >= FORM_GUARD_MIN_SUBMIT_SECONDS;
}

/**
 * Combined check: rate limit + honeypot + time-trap.
 *
 * Returns ['ok' => bool, 'message' => string, 'silent' => bool]
 *   - 'silent' = true means: pretend success but don't process (honeypot/time-trap caught a bot)
 */
function form_guard_full_check($action) {
    // 1. Honeypot — silently drop
    if (!form_honeypot_ok()) {
        return ['ok' => false, 'message' => '', 'silent' => true];
    }
    // 2. Time-trap — silently drop
    if (!form_timetrap_ok()) {
        return ['ok' => false, 'message' => '', 'silent' => true];
    }
    // 3. Rate limit — show message
    $rl = form_guard_check($action);
    if (!$rl['ok']) {
        // Send 429 header if possible
        if (!headers_sent()) {
            http_response_code(429);
            header('Retry-After: ' . $rl['retry_after']);
        }
        return ['ok' => false, 'message' => $rl['message'], 'silent' => false];
    }
    // Register attempt and proceed
    form_guard_register($action);
    return ['ok' => true, 'message' => '', 'silent' => false];
}
