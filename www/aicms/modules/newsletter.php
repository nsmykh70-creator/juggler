<?php
/**
 * Module: Newsletter Subscription
 * ================================
 * Displays a newsletter signup form. Subscribers are saved to
 * data/newsletter_subscribers.json and viewable in admin
 * (admin/contact_submissions.php → "Подписчики" tab).
 *
 * Usage: {{module:newsletter}}
 *
 * Settings (in admin/modules.php → ⚙️):
 *   - title       : heading text
 *   - desc        : description text
 *   - button_text : submit button label
 *   - placeholder : email input placeholder
 *   - success_msg : message after subscribe
 *
 * Protection:
 *   - Rate limit: 3 subscribes / hour / IP (admin/core/form_protection.php)
 *   - Honeypot field (hidden from humans, traps bots)
 *   - Time-trap: form must take ≥ 3s to submit (bots submit instantly)
 */
require_once __DIR__ . '/../admin/core/module_settings.php';
require_once __DIR__ . '/../admin/core/form_protection.php';

$defaults = [
    'title'       => 'Подпишитесь на рассылку',
    'desc'        => 'Получайте последние новости прямо на почту',
    'button_text' => 'Подписаться',
    'placeholder' => 'Введите ваш email',
    'success_msg' => 'Спасибо! Вы подписаны на рассылку.',
];
$s = module_get_settings('newsletter', $defaults);

// Public-site CSRF token (same session key as user.php / shop.php / forum / auth_block)
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['site_csrf'])) {
    $_SESSION['site_csrf'] = bin2hex(random_bytes(32));
}
$newsletter_csrf = $_SESSION['site_csrf'];

if (!function_exists('newsletter_csrf_ok')) {
    function newsletter_csrf_ok() {
        return isset($_POST['csrf_token']) && hash_equals($_SESSION['site_csrf'] ?? '', (string)$_POST['csrf_token']);
    }
}

// Handle POST
$sent = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['module_newsletter'])) {
    // CSRF protection: POST + valid site token required
    if (!newsletter_csrf_ok()) {
        $error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    } else {
    // Form protection: rate limit + honeypot + time-trap
    $guard = form_guard_full_check('newsletter');
    if (!$guard['ok']) {
        if ($guard['silent']) {
            // Bot detected — pretend success to confuse them, don't process
            $sent = true;
        } else {
            $error = $guard['message'];
        }
    } else {
        $email = trim($_POST['nl_email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Введите корректный email адрес.';
        } else {
            $file = __DIR__ . '/../data/newsletter_subscribers.json';
            $subs = [];
            if (file_exists($file)) {
                $subs = json_decode(file_get_contents($file), true) ?: [];
            }

            // Check if already subscribed
            $already = false;
            foreach ($subs as $sub) {
                if (strtolower($sub['email']) === strtolower($email)) {
                    $already = true;
                    break;
                }
            }

            if ($already) {
                $sent = true;  // treat as success — already subscribed
            } else {
                $subs[] = [
                    'id'      => count($subs) > 0 ? max(array_column($subs, 'id')) + 1 : 1,
                    'email'   => $email,
                    'ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
                    'lang'    => $GLOBALS['current_lang'] ?? 'ru',
                    'status'  => 'active',  // active | unsubscribed
                    'created' => date('Y-m-d H:i:s'),
                ];
                file_put_contents($file, json_encode($subs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $sent = true;

                // Send webhook/Telegram notification
                require_once __DIR__ . '/../admin/core/notifications.php';
                notify('new_newsletter_subscriber', ['email' => $email]);
            }

            // PRG (Post/Redirect/Get) — redirect to avoid re-POST on refresh
            $redirect_url = $_SERVER['REQUEST_URI'] ?? '';
            $redirect_url = preg_replace('/[?&]nl_success=1/', '', $redirect_url);
            $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . 'nl_success=1';

            // Clean any output buffers
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            if (!headers_sent()) {
                header('Location: ' . $redirect_url, true, 303);
                exit;
            } else {
                echo '<script>window.location.href = ' . json_encode($redirect_url) . ';</script>';
                echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirect_url) . '"></noscript>';
                die();
            }
        }
    }
    }
}

// Check for PRG success flag
if (!empty($_GET['nl_success'])) {
    $sent = true;
}
?>
<style>
.newsletter-module { box-sizing: border-box; max-width: 600px; margin: 2rem auto; padding: 2rem; background: linear-gradient(135deg, #eff6ff, #f0fdf4); border-radius: 16px; text-align: center; overflow-x: hidden; }
.newsletter-module input[type="email"] { box-sizing: border-box; max-width: 100%; }
.newsletter-form { display: flex; gap: 8px; max-width: 400px; margin: 0 auto; }
.newsletter-form input { flex: 1; padding: 0.75rem 1rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; outline: none; }
.newsletter-form input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
.newsletter-submit-btn { padding: 0.75rem 1.5rem; background: #2563eb; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; white-space: nowrap; transition: background 0.15s; }
.newsletter-submit-btn:hover { background: #1d4ed8; }
@media (max-width: 640px) {
    .newsletter-module { padding: 1.5rem 1rem; margin: 1rem auto; }
    .newsletter-form { flex-direction: column; }
    .newsletter-submit-btn { width: 100%; }
}
</style>
<div class="newsletter-module">
    <h3 style="font-size:1.5rem; font-weight:700; color:#1e293b; margin:0 0 8px;"><?= htmlspecialchars($s['title']) ?></h3>
    <p style="color:#64748b; margin:0 0 1.5rem;"><?= htmlspecialchars($s['desc']) ?></p>

    <?php if ($sent): ?>
    <div style="padding:12px 16px; background:#d1e7dd; color:#0f5132; border-radius:8px; margin-bottom:16px;">
        ✓ <?= htmlspecialchars($s['success_msg']) ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div style="padding:12px 16px; background:#f8d7da; color:#842029; border-radius:8px; margin-bottom:16px;">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="newsletter-form" style="position:relative;">
        <input type="hidden" name="module_newsletter" value="1">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($newsletter_csrf) ?>">
        <?= form_honeypot_field() ?>
        <?= form_timetrap_field() ?>
        <input type="email" name="nl_email" placeholder="<?= htmlspecialchars($s['placeholder']) ?>" required>
        <button type="submit" class="newsletter-submit-btn"><?= htmlspecialchars($s['button_text']) ?></button>
    </form>
</div>
