<?php
/**
 * Module: Contact Form
 * =====================
 * Displays a configurable contact form.
 * Settings are stored in data/module_settings/contact_form.json
 *
 * All submissions are saved to data/contact_submissions.json —
 * view them in admin at contact_submissions.php.
 * Email via PHP mail() is also attempted on production (skipped on localhost).
 *
 * Settings (editable in admin via ?module=contact_form&action=settings):
 *   - email      : recipient email (default: from config)
 *   - title      : form title
 *   - button_text: submit button label
 *   - show_phone : whether to show phone field
 *   - success_msg: message shown after submit
 *
 * Usage in pages: {{module:contact_form}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';
require_once __DIR__ . '/../admin/core/form_protection.php';

$defaults = [
    'email'       => $config['site_title'] ?? 'admin@example.com',
    'title'       => 'Свяжитесь с нами',
    'button_text' => 'Отправить сообщение',
    'show_phone'  => true,
    'success_msg' => 'Спасибо! Ваше сообщение отправлено.',
];
$settings = module_get_settings('contact_form', $defaults);

// Public-site CSRF token (same session key as user.php / shop.php / forum / auth_block)
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['site_csrf'])) {
    $_SESSION['site_csrf'] = bin2hex(random_bytes(32));
}
$contact_csrf = $_SESSION['site_csrf'];

if (!function_exists('contact_csrf_ok')) {
    function contact_csrf_ok() {
        return isset($_POST['csrf_token']) && hash_equals($_SESSION['site_csrf'] ?? '', (string)$_POST['csrf_token']);
    }
}

// Handle POST submission (the module is included in the page rendering)
$sent = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['module_contact_form'])) {
    // CSRF protection: POST + valid site token required
    if (!contact_csrf_ok()) {
        $error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    } else {
    // Form protection: honeypot + time-trap (rate limit handled by inline limiter below)
    if (!form_honeypot_ok() || !form_timetrap_ok()) {
        // Bot detected — pretend success
        $sent = true;
    } else {
    // Rate limiting: 3 submissions per 10 minutes per IP
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rl_file = __DIR__ . '/../data/rate_limit_' . md5('contact_' . $ip) . '.json';
    $rl_data = file_exists($rl_file) ? json_decode(@file_get_contents($rl_file), true) : ['count' => 0, 'first' => time()];
    if (time() - $rl_data['first'] > 600) { $rl_data = ['count' => 0, 'first' => time()]; }
    if ($rl_data['count'] >= 3) {
        $error = 'Слишком много сообщений. Попробуйте позже.';
    } else {
    $rl_data['count']++;
    @file_put_contents($rl_file, json_encode($rl_data), LOCK_EX);
    $name = trim($_POST['cf_name'] ?? '');
    $email = trim($_POST['cf_email'] ?? '');
    $phone = trim($_POST['cf_phone'] ?? '');
    $message = trim($_POST['cf_message'] ?? '');

    // Sanitize name/phone before persisting: strip CR/LF/NUL (header-injection
    // defense for the mail subject) and cap length so stored JSON stays clean.
    $name = mb_substr(str_replace(["\r", "\n", "\0"], '', $name), 0, 100);
    $phone = mb_substr(str_replace(["\r", "\n", "\0"], '', $phone), 0, 40);

    if (empty($name) || empty($email) || empty($message)) {
        $error = 'Пожалуйста, заполните обязательные поля.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Неверный email адрес.';
    } else {
        // --- 1. Save submission to JSON (PRIMARY — always works) ---
        $submissions_file = __DIR__ . '/../data/contact_submissions.json';
        $submissions = [];
        if (file_exists($submissions_file)) {
            $submissions = json_decode(file_get_contents($submissions_file), true) ?: [];
        }
        $new_submission = [
            'id'       => count($submissions) > 0 ? max(array_column($submissions, 'id')) + 1 : 1,
            'name'     => $name,
            'email'    => $email,
            'phone'    => $phone,
            'message'  => $message,
            'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
            'page'     => $_GET['page'] ?? '',
            'lang'     => $GLOBALS['current_lang'] ?? 'ru',
            'status'   => 'new',  // new | read
            'created'  => date('Y-m-d H:i:s'),
        ];
        $submissions[] = $new_submission;
        file_put_contents($submissions_file, json_encode($submissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        $saved = true;
        
        // Webhook + Telegram notification
        require_once __DIR__ . '/../admin/core/webhooks.php';
        send_webhook('new_contact_submission', ['name' => $name, 'email' => $email, 'message' => mb_substr($message, 0, 200)]);
        send_telegram("📧 <b>Новое сообщение с сайта</b>\nИмя: " . htmlspecialchars($name) . "\nEmail: " . htmlspecialchars($email) . "\nСообщение: " . htmlspecialchars(mb_substr($message, 0, 200)));

        // --- 2. Send email via SMTP (or PHP mail() fallback) ---
        $to = $settings['email'];
        $subject = 'Новое сообщение с сайта от ' . $name;
        $email_body = "<h3>Новое сообщение с сайта</h3>";
        $email_body .= "<p><strong>Имя:</strong> " . htmlspecialchars($name) . "<br><strong>Email:</strong> " . htmlspecialchars($email) . "</p>";
        if (!empty($phone)) $email_body .= "<p><strong>Телефон:</strong> " . htmlspecialchars($phone) . "</p>";
        $email_body .= "<p><strong>Сообщение:</strong><br>" . nl2br(htmlspecialchars($message)) . "</p>";
        $email_body .= '<p><a href="' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/admin/contact_submissions.php">Просмотреть в админке</a></p>';
        // Use SMTP if configured, otherwise PHP mail()
        require_once __DIR__ . '/../admin/core/email.php';
        send_email($to, $subject, $email_body, true);

        // --- 3. Send webhook + Telegram notification ---
        require_once __DIR__ . '/../admin/core/notifications.php';
        notify('new_contact_submission', [
            'name'    => $name,
            'email'   => $email,
            'phone'   => $phone,
            'message' => mb_substr($message, 0, 200),
            'page'    => $_GET['page'] ?? '',
        ]);

        // Success is based on JSON save, NOT mail()
        $sent = $saved;

        // --- 4. PRG (Post/Redirect/Get) — redirect to avoid re-POST on refresh ---
        // Without this, pressing F5 after submit re-sends the same POST data,
        // creating duplicate submissions. The redirect uses a query param
        // to show the success message on the GET request.
        //
        // IMPORTANT: This module is included inside ob_start()/ob_get_clean()
        // in index.php → process_shortcodes(). The header('Location:') may
        // not work reliably inside output buffering, so we use a dual approach:
        // 1. Try header() + exit (works if no output sent yet)
        // 2. If headers already sent, use JavaScript redirect + die
        $redirect_url = $_SERVER['REQUEST_URI'] ?? '';
        // Strip any existing cf_success param
        $redirect_url = preg_replace('/[?&]cf_success=1/', '', $redirect_url);
        $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . 'cf_success=1';

        // Clean any output buffers that might have been started
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header('Location: ' . $redirect_url, true, 303);
            exit;
        } else {
            // Fallback: JavaScript redirect
            echo '<script>window.location.href = ' . json_encode($redirect_url) . ';</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirect_url) . '"></noscript>';
            die();
        }
    }
    } // end rate limit else
    } // end honeypot/time-trap else
    } // end CSRF else
}

// Check for PRG success flag (set by the redirect above)
if (!empty($_GET['cf_success'])) {
    $sent = true;
}
?>
<style>
.contact-form-module { box-sizing: border-box; max-width: 600px; margin: 2rem auto; padding: 0 1rem; overflow-x: hidden; }
.contact-form-module input[type="text"],
.contact-form-module input[type="email"],
.contact-form-module input[type="tel"],
.contact-form-module textarea { box-sizing: border-box; max-width: 100%; }
.contact-form-submit-btn { padding: 0.7rem 1.5rem; background: #0d6efd; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; align-self: flex-start; }
@media (max-width: 640px) {
    .contact-form-module { padding: 0 0.5rem; margin: 1rem auto; }
    .contact-form-submit-btn { width: 100%; padding: 0.8rem; font-size: 15px; align-self: stretch; }
    .contact-form-module h3 { font-size: 1.25rem; }
}
</style>
<div class="contact-form-module">
    <h3 style="margin-bottom:1.5rem;"><?= htmlspecialchars($settings['title']) ?></h3>

    <?php if ($sent): ?>
        <div class="alert alert-success" style="padding:1rem 1.25rem; border-radius:8px; background:#d1e7dd; color:#0f5132; border:1px solid #badbcc;">
            ✓ <?= htmlspecialchars($settings['success_msg']) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="padding:1rem 1.25rem; border-radius:8px; background:#f8d7da; color:#842029; border:1px solid #f5c2c7;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" style="display:flex; flex-direction:column; gap:1rem; position:relative;">
        <input type="hidden" name="module_contact_form" value="1">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($contact_csrf) ?>">
        <?= form_honeypot_field() ?>
        <?= form_timetrap_field() ?>
        <div>
            <label style="display:block; margin-bottom:0.4rem; font-weight:600;">Имя *</label>
            <input type="text" name="cf_name" required style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;">
        </div>
        <div>
            <label style="display:block; margin-bottom:0.4rem; font-weight:600;">Email *</label>
            <input type="email" name="cf_email" required style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;">
        </div>
        <?php if (!empty($settings['show_phone'])): ?>
        <div>
            <label style="display:block; margin-bottom:0.4rem; font-weight:600;">Телефон</label>
            <input type="tel" name="cf_phone" style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;">
        </div>
        <?php endif; ?>
        <div>
            <label style="display:block; margin-bottom:0.4rem; font-weight:600;">Сообщение *</label>
            <textarea name="cf_message" required rows="5" style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;"></textarea>
        </div>
        <button type="submit" class="contact-form-submit-btn">
            <?= htmlspecialchars($settings['button_text']) ?>
        </button>
    </form>
</div>
