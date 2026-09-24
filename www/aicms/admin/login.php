<?php
session_start();
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/rate_limit.php';
require_once __DIR__ . '/core/lang.php';

// Ensure a CSRF token exists BEFORE any form renders or any check runs.
// Without this, hash_equals('', '') passes trivially and CSRF protection is void.
generate_csrf_token();

// Load config to check if 2FA is enabled
$_login_config = read_json(__DIR__ . '/../data/config.json', []);
$_2fa_enabled = !empty($_login_config['features']['2fa']);

// === Admin IP allowlist ===
// If config.security.admin_ip_allowlist is set, only those IPs may even
// see the login form. Everyone else gets a generic 403.
$_ip_allow = $_login_config['security']['admin_ip_allowlist'] ?? [];
if (!empty($_ip_allow)) {
    $_client_ip = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
    $_allowed = false;
    foreach ($_ip_allow as $_rule) {
        $_rule = trim($_rule);
        if ($_rule === '') continue;
        if (function_exists('ip_in_range')) {
            if (ip_in_range($_client_ip, $_rule)) { $_allowed = true; break; }
        } elseif ($_client_ip === $_rule) {
            $_allowed = true; break;
        }
    }
    if (!$_allowed) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>403</title>'
           . '<style>body{font-family:system-ui,sans-serif;background:#f8fafc;display:flex;'
           . 'min-height:100vh;align-items:center;justify-content:center;margin:0;color:#475569}'
           . '.box{text-align:center;max-width:480px;padding:40px}</style></head>'
           . '<body><div class="box"><h1 style="font-size:64px;margin:0;color:#dc2626">403</h1>'
           . '<p>Доступ к панели управления с вашего IP-адреса ограничен.</p>'
           . '<p style="font-size:13px;opacity:0.7">IP: ' . htmlspecialchars($_client_ip) . '</p>'
           . '</div></body></html>';
        exit;
    }
}

if (isset($_SESSION['admin'])) {
    header('Location: index.php');
    exit;
}

// Check rate limit
$rl = rate_limit_check('admin_login');
$is_blocked = $rl['blocked'];
$remaining_block = $rl['blocked'] ? ceil($rl['wait_seconds'] / 60) : 0;
$remaining_attempts = rate_limit_remaining('admin_login');

$error = '';
$show_2fa = false;

// ===== Step 1: Login + password =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_blocked && !isset($_POST['totp_code'])) {
    // CSRF check
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = "CSRF error. Please reload the page.";
    } else {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $usersFile = __DIR__ . '/../data/users.json';
    
    if (file_exists($usersFile)) {
        $users = json_decode(file_get_contents($usersFile), true);
        if ($users && $username === $users['username'] && password_verify($password, $users['password'])) {
            // Check if 2FA is enabled (both globally in config AND user has secret)
            $totp_secret = $users['totp_secret'] ?? '';
            if ($_2fa_enabled && !empty($totp_secret)) {
                // Store username in session for step 2
                $_SESSION['_2fa_pending'] = $username;
                $show_2fa = true;
            } else {
                // No 2FA — login directly
                rate_limit_reset('admin_login');
                $_SESSION['admin'] = $username;
                aicms_set_authed_cookie(); // маркер для обхода фронт-кэша
                header('Location: index.php');
                exit;
            }
        } else {
            rate_limit_fail('admin_login');
            $left = rate_limit_remaining('admin_login');
            $error = $left > 0
                ? str_replace('{$left}', $left, t('login_attempts_left'))
                : t('login_blocked');
        }
    } else {
        $error = t('users_file_missing');
    }
    } // end CSRF else
}

// ===== Step 2: 2FA verification =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_code']) && !empty($_SESSION['_2fa_pending'])) {
    // CSRF check on the 2FA step too (same session token as step 1)
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = "CSRF error. Please reload the page.";
        $show_2fa = true;
    } elseif ($is_blocked) {
        // Rate limited — refuse 2FA attempts as well (prevents TOTP brute-force)
        $error = str_replace('{$remaining_block}', $remaining_block, t('login_blocked_remaining'));
        $show_2fa = true;
    } else {
        $totp_code = trim($_POST['totp_code'] ?? '');
        $usersFile = __DIR__ . '/../data/users.json';
        $users = json_decode(file_get_contents($usersFile), true);
        $secret = $users['totp_secret'] ?? '';
        $backup_codes = $users['totp_backup_codes'] ?? [];

        // Try TOTP code first
        $totp_ok = _verify_totp($secret, $totp_code);
        // Then try backup code (matches XXXX-XXXX format)
        $backup_idx = -1;
        if (!$totp_ok && preg_match('/^[A-Z2-7]{4}-[A-Z2-7]{4}$/i', $totp_code)) {
            require_once __DIR__ . '/core/totp.php';
            $backup_idx = totp_verify_backup_code($totp_code, $backup_codes);
        }

        if ($totp_ok || $backup_idx >= 0) {
            // If backup code was used, invalidate it
            if ($backup_idx >= 0) {
                $users['totp_backup_codes'] = totp_invalidate_backup_code($backup_codes, $backup_idx);
                file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
            // 2FA success
            rate_limit_reset('admin_login');
            $_SESSION['admin'] = $_SESSION['_2fa_pending'];
            $_SESSION['_2fa_verified_at'] = time();
            unset($_SESSION['_2fa_pending']);
            aicms_set_authed_cookie(); // маркер для обхода фронт-кэша
            header('Location: index.php');
            exit;
        } else {
            // Wrong code — register failed attempt (counts toward admin_login block)
            rate_limit_fail('admin_login');
            $show_2fa = true;
            $error = t('two_factor_code_wrong');
        }
    }
} elseif ($is_blocked) {
    $error = str_replace('{$remaining_block}', $remaining_block, t('login_blocked_remaining'));
}
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8">
    <title><?= t('title_login') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="min-h-screen flex items-center justify-center" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
    <div class="bg-white rounded-2xl shadow-2xl p-8 w-full max-w-sm">
        <div class="w-16 h-16 bg-indigo-600 rounded-2xl flex items-center justify-center mx-auto mb-5 text-white text-2xl">
            <?php if ($show_2fa): ?>
            <i class="fas fa-mobile-alt"></i>
            <?php else: ?>
            <i class="fas fa-lock"></i>
            <?php endif; ?>
        </div>
        <h1 class="text-center text-xl font-bold text-slate-800 mb-5">
            <?php if ($show_2fa): ?><?= t('two_factor_page') ?><?php else: ?>AICMS<?php endif; ?>
        </h1>
        
        <?php if ($error): ?>
        <div class="bg-red-50 text-red-700 text-sm py-2 px-3 rounded-lg mb-4 flex items-center gap-2">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($show_2fa): ?>
        <!-- Step 2: 2FA code -->
        <div class="bg-blue-50 text-blue-700 text-sm py-2 px-3 rounded-lg mb-4">
            <i class="fas fa-info-circle"></i> <?= t('two_factor_help') ?>
        </div>
        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('two_factor_code') ?></label>
                <input type="text" name="totp_code" required autofocus maxlength="6" pattern="[0-9]{6}"
                    placeholder="000000"
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition-all text-center text-2xl tracking-widest font-mono"
                    inputmode="numeric" autocomplete="one-time-code">
            </div>
            <button type="submit" class="w-full py-2.5 bg-indigo-600 text-white rounded-lg font-medium hover:bg-indigo-700 transition-all flex items-center justify-center gap-2">
                <i class="fas fa-check"></i> <?= t('confirm_btn_login') ?>
            </button>
            <a href="login.php" class="block text-center text-sm text-slate-500 hover:text-slate-700 mt-2"><?= t('back_link') ?></a>
        </form>
        
        <?php elseif (!$is_blocked): ?>
        <!-- Step 1: Login + password -->
        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('username_label') ?></label>
                <input type="text" name="username" required autofocus
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition-all">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('password') ?></label>
                <input type="password" name="password" required
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition-all">
            </div>
            <?php if ($remaining_attempts <= 3 && $remaining_attempts > 0): ?>
            <div class="text-xs text-amber-600 text-center"><?= t('attempts_left') ?> <?= $remaining_attempts ?></div>
            <?php endif; ?>
            <button type="submit" class="w-full py-2.5 bg-indigo-600 text-white rounded-lg font-medium hover:bg-indigo-700 transition-all flex items-center justify-center gap-2">
                <i class="fas fa-sign-in-alt"></i> <?= t('login_button') ?>
            </button>
        </form>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
// ===== TOTP helper (RFC 6238) =====
function _verify_totp($secret, $code, $window = 2) {
    if (empty($secret) || empty($code)) return false;
    $secret = _base32_decode($secret);
    if (!$secret) return false;
    $time = floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        $t = $time + $i;
        $bin = pack('N*', 0) . pack('N*', $t);
        $hash = hash_hmac('sha1', $bin, $secret, true);
        $offset = ord($hash[19]) & 0xf;
        $otp = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;
        if (str_pad($otp, 6, '0', STR_PAD_LEFT) === $code) return true;
    }
    return false;
}

function _base32_decode($b32) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper($b32);
    $b32 = preg_replace('/[^A-Z2-7]/', '', $b32);
    $bits = '';
    for ($i = 0; $i < strlen($b32); $i++) {
        $pos = strpos($alphabet, $b32[$i]);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $binary = '';
    for ($i = 0; $i < strlen($bits); $i += 8) {
        $byte = substr($bits, $i, 8);
        if (strlen($byte) < 8) break;
        $binary .= chr(bindec($byte));
    }
    return $binary;
}
