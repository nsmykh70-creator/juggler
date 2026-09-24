<?php
/**
 * Module: Cafe Reservation Form
 * Booking form for cafe tables with date, time, guests.
 * Saves to data/cafe_bookings.json
 *
 * Usage: {{module:cafe}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';
require_once __DIR__ . '/../admin/core/extensions.php';
require_once __DIR__ . '/../admin/core/form_protection.php';

if (!extension_enabled('cafe')) { echo ''; return; }

$defaults = [
    'title' => 'Бронирование столика', 'email' => 'admin@example.com',
    'button_text' => 'Забронировать', 'show_phone' => true, 'show_guests' => true,
    'success_msg' => 'Спасибо! Ваш столик забронирован, мы свяжемся с вами.',
];
$s = module_get_settings('cafe', $defaults);
$min_date_val = date('Y-m-d');

// Public-site CSRF token (same session key as user.php / shop.php / forum / auth_block)
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['site_csrf'])) {
    $_SESSION['site_csrf'] = bin2hex(random_bytes(32));
}
$cafe_csrf = $_SESSION['site_csrf'];

if (!function_exists('cafe_csrf_ok')) {
    function cafe_csrf_ok() {
        return isset($_POST['csrf_token']) && hash_equals($_SESSION['site_csrf'] ?? '', (string)$_POST['csrf_token']);
    }
}

$sent = false; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['module_cafe'])) {
    // CSRF protection: POST + valid site token required
    if (!cafe_csrf_ok()) {
        $error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    } else {
    if (!form_honeypot_ok() || !form_timetrap_ok()) {
        $sent = true;
    } else {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rl_file = __DIR__ . '/../data/rate_limit_' . md5('cafe_' . $ip) . '.json';
    $rl_data = file_exists($rl_file) ? json_decode(@file_get_contents($rl_file), true) : ['count' => 0, 'first' => time()];
    if (time() - $rl_data['first'] > 600) { $rl_data = ['count' => 0, 'first' => time()]; }
    if ($rl_data['count'] >= 3) {
        $error = 'Слишком много заявок. Попробуйте позже.';
    } else {
    $rl_data['count']++; @file_put_contents($rl_file, json_encode($rl_data), LOCK_EX);
    $name = trim($_POST['cafe_name'] ?? '');
    $email = trim($_POST['cafe_email'] ?? '');
    $phone = trim($_POST['cafe_phone'] ?? '');
    $date = trim($_POST['cafe_date'] ?? '');
    $time = trim($_POST['cafe_time'] ?? '');
    $guests = (int)($_POST['cafe_guests'] ?? 2);
    $table_id = (int)($_POST['cafe_table_id'] ?? 0);
    $notes = trim($_POST['cafe_notes'] ?? '');

    // Sanitize name/phone before persisting: strip CR/LF/NUL (header-injection
    // defense for the mail subject) and cap length so stored JSON stays clean.
    $name  = mb_substr(str_replace(["\r", "\n", "\0"], '', $name), 0, 100);
    $phone = mb_substr(str_replace(["\r", "\n", "\0"], '', $phone), 0, 40);

    if (empty($name) || empty($email) || empty($date) || empty($time)) {
        $error = 'Заполните обязательные поля: имя, email, дата, время.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Неверный email.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))
        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
        $error = 'Неверный формат даты или времени.';
    } else {
        // === Check table availability — prevent double booking ===
        // If a specific table was selected, check cafe_reservations for
        // any active reservation at the same date and time (±90 minutes).
        $table_unavailable = false;
        if ($table_id > 0 && function_exists('db_available') && db_available()) {
            try {
                // We treat reservations as overlapping if they fall within
                // [time - 90min, time + 90min] on the same date — the default
                // seating duration. This is generous but avoids accidental
                // double-booking of the same physical table.
                $overlap = db_row(
                    'SELECT id FROM ' . db_table('cafe_reservations') .
                    ' WHERE table_id = ? AND status IN (?, ?) AND reserve_date = ? AND ABS((julianday(reserve_time) - julianday(?)) * 24 * 60) < duration_min LIMIT 1',
                    [$table_id, 'reserved', 'confirmed', $date, $time]
                );
                // SQLite-specific ABS(julianday()) math. For MySQL, fall back
                // to a simpler equality check on (date, time) — same table at
                // the exact same time is unconditionally a conflict.
                if (!$overlap) {
                    $overlap = db_row(
                        'SELECT id FROM ' . db_table('cafe_reservations') .
                        ' WHERE table_id = ? AND status IN (?, ?) AND reserve_date = ? AND reserve_time = ? LIMIT 1',
                        [$table_id, 'reserved', 'confirmed', $date, $time]
                    );
                }
                if ($overlap) $table_unavailable = true;
            } catch (Exception $e) {}
        }

        if ($table_unavailable) {
            $error = 'К сожалению, этот столик уже забронирован на ' . htmlspecialchars($date) . ' в ' . htmlspecialchars($time) . '. Выберите другое время или другой столик.';
        } else {
        // Get table info
        $table_title = '';
        if ($table_id > 0 && function_exists('db_available') && db_available()) {
            try { $t_info = db_find('cafe_tables', $table_id); if ($t_info) { $table_title = $t_info['title']; } } catch (Exception $e) {}
        }

        $file = __DIR__ . '/../data/cafe_bookings.json';
        $all = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        $max_id = 0; foreach ($all as $b) { if (($b['id'] ?? 0) > $max_id) $max_id = $b['id']; }
        $booking = [
            'id' => $max_id + 1, 'name' => $name, 'email' => $email, 'phone' => $phone,
            'date' => $date, 'time' => $time, 'guests' => $guests,
            'table_id' => $table_id, 'table_title' => $table_title,
            'notes' => $notes, 'created' => date('Y-m-d H:i:s'), 'status' => 'new',
        ];
        $all[] = $booking;
        file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        // === Record reservation in DB for future overlap checks ===
        if ($table_id > 0 && function_exists('db_available') && db_available()) {
            try {
                db_insert('cafe_reservations', [
                    'table_id'      => $table_id,
                    'booking_id'    => $booking['id'],
                    'reserve_date'  => $date,
                    'reserve_time'  => $time,
                    'duration_min'  => 90,
                    'status'        => 'reserved',
                ]);
            } catch (Exception $e) {}
        }

        // Email notification
        $email_body = "<h3>Новая заявка на бронирование столика</h3>";
        $email_body .= "<p><strong>Имя:</strong> " . htmlspecialchars($name) . "<br><strong>Email:</strong> " . htmlspecialchars($email) . "<br><strong>Телефон:</strong> " . htmlspecialchars($phone) . "</p>";
        $email_body .= "<p><strong>Дата:</strong> " . htmlspecialchars($date) . "<br><strong>Время:</strong> " . htmlspecialchars($time) . "<br><strong>Гостей:</strong> {$guests}</p>";
        if ($table_title) $email_body .= "<p><strong>Столик:</strong> " . htmlspecialchars($table_title) . "</p>";
        if ($notes) $email_body .= "<p><strong>Комментарий:</strong><br>" . nl2br(htmlspecialchars($notes)) . "</p>";
        require_once __DIR__ . '/../admin/core/email.php';
        send_email($s['email'], 'Бронирование столика — ' . htmlspecialchars($name), $email_body, true);

        // Notification (webhook + Telegram) — centralized via notify()
        require_once __DIR__ . '/../admin/core/notifications.php';
        notify('new_cafe_booking', [
            'name'   => $name,
            'email'  => $email,
            'phone'  => $phone,
            'date'   => $date,
            'time'   => $time,
            'guests' => $guests,
            'table'  => $table_title,
        ]);

        $sent = true;
    } // end table_unavailable else
    } // end else (validation ok)
    }
    }
    }
}
?>
<div class="cafe-module" style="max-width:600px; margin:2rem auto;">
    <h3 style="margin-bottom:1.5rem;"><?= htmlspecialchars($s['title']) ?></h3>
    <?php if ($sent): ?>
        <div style="padding:1rem 1.25rem; border-radius:8px; background:#d1e7dd; color:#0f5132; border:1px solid #badbcc;">✓ <?= htmlspecialchars($s['success_msg']) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div style="padding:1rem 1.25rem; border-radius:8px; background:#f8d7da; color:#842029; border:1px solid #f5c2c7;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (!$sent): ?>
    <form method="POST" style="display:flex; flex-direction:column; gap:1rem;">
        <input type="hidden" name="module_cafe" value="1">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($cafe_csrf) ?>">
        <?= form_honeypot_field() ?> <?= form_timetrap_field() ?>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
            <div><label style="display:block; margin-bottom:0.4rem; font-weight:600;">Имя *</label><input type="text" name="cafe_name" required style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;"></div>
            <div><label style="display:block; margin-bottom:0.4rem; font-weight:600;">Email *</label><input type="email" name="cafe_email" required style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;"></div>
        </div>
        <?php if (!empty($s['show_phone'])): ?>
        <div><label style="display:block; margin-bottom:0.4rem; font-weight:600;">Телефон</label><input type="tel" name="cafe_phone" style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;"></div>
        <?php endif; ?>
        <?php
        $cafe_tables = [];
        if (function_exists('db_available') && db_available()) { try { $cafe_tables = db_select('cafe_tables', '*', 'status = ?', ['active'], 'position ASC'); } catch (Exception $e) {} }
        if (!empty($cafe_tables)):
        ?>
        <div><label style="display:block; margin-bottom:0.4rem; font-weight:600;">Столик</label>
        <select name="cafe_table_id" style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;">
            <option value="">— Не выбран —</option>
            <?php foreach ($cafe_tables as $ct): ?>
            <option value="<?= (int)$ct['id'] ?>"><?= h($ct['title']) ?> — <?= (int)$ct['capacity'] ?> чел.<?php if (!empty($ct['location'])): ?> (<?= h($ct['location']) ?>)<?php endif; ?></option>
            <?php endforeach; ?>
        </select></div>
        <?php endif; ?>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
            <div><label style="display:block; margin-bottom:0.4rem; font-weight:600;">Дата *</label><input type="date" name="cafe_date" required min="<?= htmlspecialchars($min_date_val) ?>" style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;"></div>
            <div><label style="display:block; margin-bottom:0.4rem; font-weight:600;">Время *</label><input type="time" name="cafe_time" required style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;"></div>
        </div>
        <?php if (!empty($s['show_guests'])): ?>
        <div><label style="display:block; margin-bottom:0.4rem; font-weight:600;">Количество гостей</label><input type="number" name="cafe_guests" value="2" min="1" max="50" style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;"></div>
        <?php endif; ?>
        <div><label style="display:block; margin-bottom:0.4rem; font-weight:600;">Комментарий</label><textarea name="cafe_notes" rows="3" style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;"></textarea></div>
        <button type="submit" style="padding:0.7rem 1.5rem; background:#16a34a; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600; align-self:flex-start;">🍽 <?= htmlspecialchars($s['button_text']) ?></button>
    </form>
    <?php endif; ?>
</div>
