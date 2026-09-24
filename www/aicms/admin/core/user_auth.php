<?php
/**
 * AICMS User Authentication
 * ==========================
 * Public-side user accounts for comments and forum.
 * Admin accounts use a separate system (data/users.json + $_SESSION['admin']).
 *
 * Usage:
 *   require_once 'core/user_auth.php';
 *   $user = current_user();          // array|null
 *   if (is_logged_in()) { ... }
 *   user_register($username, $email, $password);
 *   user_login($login, $password);   // returns true/false
 *   user_logout();
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';

// Маркерная cookie для обхода фронт-кэша авторизованными пользователями
// (определены в admin/core/functions.php, если он уже подключён — не дублируем).
// Локальный fallback только если functions.php ещё не загружен (изолированный вызов).
if (!function_exists('aicms_set_authed_cookie')) {
    function aicms_cookie_secure() {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
        $cfg_file = __DIR__ . '/../../data/config.json';
        $cfg = file_exists($cfg_file) ? json_decode(@file_get_contents($cfg_file), true) : [];
        $trusted = is_array($cfg) ? ($cfg['security']['trusted_proxies'] ?? []) : [];
        if (!empty($trusted) && !empty($_SERVER['REMOTE_ADDR']) && in_array($_SERVER['REMOTE_ADDR'], $trusted, true)) {
            return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        }
        return false;
    }
    function aicms_set_authed_cookie() {
        setcookie('aicms_authed', '1', [
            'expires'  => time() + 30 * 86400,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => aicms_cookie_secure(),
        ]);
    }
    function aicms_clear_authed_cookie() {
        setcookie('aicms_authed', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => aicms_cookie_secure(),
        ]);
    }
}

/**
 * Get the currently logged-in site user (or null).
 */
function current_user() {
    if (empty($_SESSION['site_user_id'])) return null;
    static $cached = null;
    if ($cached !== null) return $cached;
    if (!db_available()) { $cached = null; return null; }
    $user = db_find('users', (int)$_SESSION['site_user_id']);
    if (!$user || !empty($user['is_banned'])) {
        unset($_SESSION['site_user_id']);
        $cached = null;
        return null;
    }
    $cached = $user;
    return $user;
}

function is_logged_in() {
    return current_user() !== null;
}

/**
 * Register a new user.
 * Returns ['ok'=>true] on success, ['ok'=>false,'error'=>'msg'] on failure.
 */
function user_register($username, $email, $password, $display_name = '') {
    if (!db_available()) return ['ok' => false, 'error' => 'База данных недоступна. Регистрация невозможна без БД.'];
    $username = trim($username);
    $email = trim(strtolower($email));
    $display_name = trim($display_name) ?: $username;

    // Validate
    if (strlen($username) < 3) return ['ok' => false, 'error' => 'Имя пользователя должно быть не короче 3 символов.'];
    if (strlen($username) > 50) return ['ok' => false, 'error' => 'Имя пользователя слишком длинное (макс. 50 символов).'];
    if (!preg_match('/^[a-zA-Z0-9_\-а-яА-ЯёЁ]+$/u', $username)) return ['ok' => false, 'error' => 'Имя пользователя может содержать только буквы, цифры, дефис и подчёркивание.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Неверный формат email.'];
    if (strlen($password) < 6) return ['ok' => false, 'error' => 'Пароль должен быть не короче 6 символов.'];

    // Check duplicates
    $exists = db_row('SELECT id FROM ' . db_table('users') . ' WHERE username = ? OR email = ? LIMIT 1', [$username, $email]);
    if ($exists) return ['ok' => false, 'error' => 'Пользователь с таким именем или email уже существует.'];

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $id = db_insert('users', [
        'username'     => $username,
        'email'        => $email,
        'password'     => $hash,
        'display_name' => $display_name,
        'role'         => 'user',
        'is_banned'    => 0,
        'posts_count'  => 0,
        'comments_count' => 0,
    ]);
    if (!$id) return ['ok' => false, 'error' => 'Не удалось создать пользователя.'];

    $_SESSION['site_user_id'] = (int)$id;
    aicms_set_authed_cookie();
    return ['ok' => true, 'id' => (int)$id];
}

/**
 * Login user by username or email + password.
 * Returns true on success, false on failure.
 */
function user_login($login, $password, $remember = false) {
    if (!db_available()) return false;
    $login = trim($login);
    $user = db_row('SELECT * FROM ' . db_table('users') . ' WHERE username = ? OR email = ? LIMIT 1', [$login, strtolower($login)]);
    if (!$user) return false;
    if (!empty($user['is_banned'])) return false;
    if (!password_verify($password, $user['password'])) return false;

    $_SESSION['site_user_id'] = (int)$user['id'];
    aicms_set_authed_cookie();
    db_update('users', ['last_login_at' => date('Y-m-d H:i:s')], ['id' => $user['id']]);

    if ($remember) {
        _user_set_remember_token($user['id']);
    }
    return true;
}

/**
 * Logout current user.
 */
function user_logout() {
    unset($_SESSION['site_user_id']);
    aicms_clear_authed_cookie();
    if (!empty($_COOKIE['aicms_remember'])) {
        $token = $_COOKIE['aicms_remember'];
        if (db_available()) {
            db_query('DELETE FROM ' . db_table('user_sessions') . ' WHERE token = ?', [$token]);
        }
        setcookie('aicms_remember', '', time() - 3600, '/');
    }
}

/**
 * Set "remember me" cookie + DB token.
 */
function _user_set_remember_token($user_id) {
    if (!db_available()) return;
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 30 * 86400); // 30 days
    db_insert('user_sessions', [
        'user_id'    => $user_id,
        'token'      => hash('sha256', $token),
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        'expires_at' => $expires,
    ]);
    setcookie('aicms_remember', $token, [
        'expires'  => time() + 30 * 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => function_exists('aicms_cookie_secure') ? aicms_cookie_secure() : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
}

/**
 * Try to auto-login from "remember me" cookie.
 * Call this on every page load before current_user().
 */
function user_try_auto_login() {
    if (!empty($_SESSION['site_user_id'])) return;
    if (empty($_COOKIE['aicms_remember'])) return;
    if (!db_available()) return;
    $token = $_COOKIE['aicms_remember'];
    $hash = hash('sha256', $token);
    $row = db_row('SELECT user_id FROM ' . db_table('user_sessions') . ' WHERE token = ? AND expires_at > datetime("now","localtime") LIMIT 1', [$hash]);
    // Note: SQLite uses datetime('now','localtime'); MySQL uses NOW(). The above works for both because MySQL accepts the function call.
    if (!$row) {
        // Try MySQL NOW() syntax
        $row = db_row('SELECT user_id FROM ' . db_table('user_sessions') . ' WHERE token = ? AND expires_at > NOW() LIMIT 1', [$hash]);
    }
    if ($row) {
        $_SESSION['site_user_id'] = (int)$row['user_id'];
        aicms_set_authed_cookie();
    } else {
        // Invalid/expired token — clear cookie
        setcookie('aicms_remember', '', time() - 3600, '/');
    }
}

/**
 * Get user display name (fallback to username).
 */
function user_display_name($user = null) {
    if (!$user) $user = current_user();
    if (!$user) return '';
    return !empty($user['display_name']) ? $user['display_name'] : $user['username'];
}

/**
 * Get user avatar HTML (gravatar fallback).
 */
function user_avatar($user = null, $size = 40) {
    if (!$user) $user = current_user();
    if (!$user) return '';
    if (!empty($user['avatar'])) return '<img src="' . htmlspecialchars($user['avatar']) . '" width="' . $size . '" height="' . $size . '" alt="" style="border-radius:50%;">';
    $email = $user['email'] ?? '';
    $hash = md5(strtolower(trim($email)));
    $url = 'https://www.gravatar.com/avatar/' . $hash . '?s=' . $size . '&d=identicon';
    return '<img src="' . $url . '" width="' . $size . '" height="' . $size . '" alt="" style="border-radius:50%;">';
}

// Auto-login attempt on every page load
user_try_auto_login();
