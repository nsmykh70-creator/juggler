<?php
// Logout requires POST + valid CSRF token (prevents logout CSRF attacks).
require_once __DIR__ . '/core/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    header('Location: login.php');
    exit;
}

// Clear all session data and regenerate ID to prevent session fixation
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'] ?? '',
        $p['secure'] ?? false, $p['httponly'] ?? false);
}
session_destroy();
session_start();
session_regenerate_id(true);

aicms_clear_authed_cookie(); // снять маркер — вернуть кэш для этого браузера
header('Location: login.php?logged_out=1');
exit;
