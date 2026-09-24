<?php
/**
 * Auth Block Widget
 * ==================
 * Compact login/profile widget for site themes.
 * Shows "Login/Register" buttons for guests, or user avatar + menu for logged-in users.
 *
 * Usage in theme: {{module:auth_block}}
 */
require_once __DIR__ . '/../admin/core/user_auth.php';

// Ensure a public-site CSRF token exists for the logout form (same as user.php)
if (empty($_SESSION['site_csrf'])) {
    $_SESSION['site_csrf'] = bin2hex(random_bytes(32));
}
$_auth_csrf = $_SESSION['site_csrf'];

$auth_cu = current_user();

// Compute site root URL (avoid double-slash bug)
$_script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$_script_dir = rtrim($_script_dir, '/');
if ($_script_dir === '' || $_script_dir === '.') $_script_dir = '';
$_auth_base = $_script_dir; // e.g. "" or "/subdir"
$_auth_redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
?>
<div class="auth-block" style="display:flex; align-items:center; gap:8px; font-size:14px;">
    <?php if ($auth_cu): ?>
        <a href="<?= $_auth_base ?>/user.php?action=profile"
           style="display:flex; align-items:center; gap:6px; padding:6px 10px; background:rgba(37,99,235,0.1); border-radius:8px; text-decoration:none; color:#1e293b; font-weight:600;">
            <span style="width:24px; height:24px; border-radius:50%; background:linear-gradient(135deg,#2563eb,#7c3aed); color:#fff; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700;">
                <?= htmlspecialchars(mb_substr(user_display_name($auth_cu), 0, 1, 'UTF-8')) ?>
            </span>
            <span><?= htmlspecialchars(user_display_name($auth_cu)) ?></span>
        </a>
        <form method="POST" action="<?= $_auth_base ?>/user.php?action=logout" style="display:inline; margin:0;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_auth_csrf) ?>">
            <button type="submit" style="background:none;border:none;cursor:pointer;padding:6px 10px;color:#64748b;font-size:13px;" title="Выйти">
                <i class="fas fa-sign-out-alt"></i>
            </button>
        </form>
    <?php else: ?>
        <a href="<?= $_auth_base ?>/user.php?action=login&redirect=<?= $_auth_redirect ?>"
           style="padding:6px 12px; color:#475569; text-decoration:none; font-weight:500; border-radius:8px;">
            <i class="fas fa-sign-in-alt" style="margin-right:4px;"></i>Войти
        </a>
        <a href="<?= $_auth_base ?>/user.php?action=register&redirect=<?= $_auth_redirect ?>"
           style="padding:6px 12px; background:#2563eb; color:#fff; text-decoration:none; font-weight:600; border-radius:8px;">
            <i class="fas fa-user-plus" style="margin-right:4px;"></i>Регистрация
        </a>
    <?php endif; ?>
</div>
