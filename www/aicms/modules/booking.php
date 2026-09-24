<?php
/**
 * Module: Booking Form
 * =====================
 * A booking/reservation form with check-in date, check-out date, guests, and contact fields.
 * Calculates number of nights automatically.
 * Sends email notifications and saves bookings to data/bookings.json.
 *
 * Settings:
 *   - title, email, button_text, show_phone, show_guests,
 *   - min_date (days from today), max_date (days from today),
 *   - success_msg
 *
 * Usage: {{module:booking}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';
require_once __DIR__ . '/../admin/core/extensions.php';
require_once __DIR__ . '/../admin/core/form_protection.php';

// Check if extension is enabled
if (!extension_enabled('booking')) {
    echo '';
    return;
}

$defaults = [
    'title'       => 'Бронирование',
    'email'       => 'admin@example.com',
    'button_text' => 'Забронировать',
    'show_phone'  => true,
    'show_guests' => true,
    'min_date'    => 0,
    'max_date'    => 90,
    'success_msg' => 'Спасибо! Ваша заявка принята, мы свяжемся с вами.',
];
$s = module_get_settings('booking', $defaults);

// Public-site CSRF token (same session key as user.php / shop.php / forum / auth_block)
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['site_csrf'])) {
    $_SESSION['site_csrf'] = bin2hex(random_bytes(32));
}
$booking_csrf = $_SESSION['site_csrf'];

if (!function_exists('booking_csrf_ok')) {
    function booking_csrf_ok() {
        return isset($_POST['csrf_token']) && hash_equals($_SESSION['site_csrf'] ?? '', (string)$_POST['csrf_token']);
    }
}

// Calculate date constraints
$min_date_val = date('Y-m-d', time() + ((int)$s['min_date'] * 86400));
$max_date_val = date('Y-m-d', time() + ((int)$s['max_date'] * 86400));

// Handle POST submission
$sent = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['module_booking'])) {
    // CSRF protection: POST + valid site token required
    if (!booking_csrf_ok()) {
        $error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    } else {
    // Form protection: honeypot + time-trap
    if (!form_honeypot_ok() || !form_timetrap_ok()) {
        $sent = true; // pretend success to bots
    } else {
    // Rate limiting: 3 bookings per 10 minutes per IP
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rl_file = __DIR__ . '/../data/rate_limit_' . md5('booking_' . $ip) . '.json';
    $rl_data = file_exists($rl_file) ? json_decode(@file_get_contents($rl_file), true) : ['count' => 0, 'first' => time()];
    if (time() - $rl_data['first'] > 600) { $rl_data = ['count' => 0, 'first' => time()]; }
    if ($rl_data['count'] >= 3) {
        $error = 'Слишком много заявок. Попробуйте позже.';
    } else {
    $rl_data['count']++;
    @file_put_contents($rl_file, json_encode($rl_data), LOCK_EX);
    $name       = trim($_POST['bk_name'] ?? '');
    $email      = trim($_POST['bk_email'] ?? '');
    $phone      = trim($_POST['bk_phone'] ?? '');
    $room_id    = (int)($_POST['bk_room_id'] ?? 0);
    $check_in   = trim($_POST['bk_check_in'] ?? '');
    $check_out  = trim($_POST['bk_check_out'] ?? '');
    $guests     = (int)($_POST['bk_guests'] ?? 1);
    $notes      = trim($_POST['bk_notes'] ?? '');

    // Sanitize name/phone before persisting: strip CR/LF/NUL (header-injection
    // defense for the mail subject) and cap length so stored JSON stays clean.
    $name  = mb_substr(str_replace(["\r", "\n", "\0"], '', $name), 0, 100);
    $phone = mb_substr(str_replace(["\r", "\n", "\0"], '', $phone), 0, 40);

    // Get room info if selected
    $room_info = null;
    if ($room_id > 0 && function_exists('db_available') && db_available()) {
        try { $room_info = db_find('rooms', $room_id); } catch (Exception $e) {}
    }

    // Validation
    if (empty($name) || empty($email) || empty($check_in) || empty($check_out)) {
        $error = 'Пожалуйста, заполните обязательные поля (имя, email, дата заезда, дата выезда).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Неверный email адрес.';
    } elseif (strtotime($check_out) <= strtotime($check_in)) {
        $error = 'Дата выезда должна быть позже даты заезда.';
    } else {
        // Calculate nights
        $nights = (int)round((strtotime($check_out) - strtotime($check_in)) / 86400);

        // === Check room availability — prevent double booking ===
        // If a specific room was selected, look up room_reservations table
        // for any confirmed/reserved booking that overlaps with [check_in, check_out).
        $room_unavailable = false;
        if ($room_id > 0 && function_exists('db_available') && db_available()) {
            try {
                $overlap = db_row(
                    'SELECT id FROM ' . db_table('room_reservations') .
                    ' WHERE room_id = ? AND status IN (?, ?) AND check_in < ? AND check_out > ? LIMIT 1',
                    [$room_id, 'reserved', 'confirmed', $check_out, $check_in]
                );
                if ($overlap) $room_unavailable = true;
            } catch (Exception $e) {}
        }

        if ($room_unavailable) {
            $error = 'К сожалению, этот номер уже забронирован на выбранные даты. Пожалуйста, выберите другой номер или измените даты.';
        } else {
        // Save booking to JSON
        $bookings_file = __DIR__ . '/../data/bookings.json';
        $bookings = file_exists($bookings_file) ? (json_decode(file_get_contents($bookings_file), true) ?: []) : [];
        // Generate unique ID
        $max_id = 0;
        foreach ($bookings as $b) { if (($b['id'] ?? 0) > $max_id) $max_id = $b['id']; }
        $booking = [
            'id'         => $max_id + 1,
            'name'       => $name,
            'email'      => $email,
            'phone'      => $phone,
            'room_id'    => $room_id,
            'room_title' => $room_info ? $room_info['title'] : '',
            'date'       => $check_in,
            'check_in'   => $check_in,
            'check_out'  => $check_out,
            'nights'     => $nights,
            'guests'     => $guests,
            'notes'      => $notes,
            'created'    => date('Y-m-d H:i:s'),
            'status'     => 'new',
        ];
        $bookings[] = $booking;
        file_put_contents($bookings_file, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        // === Record reservation in DB for future overlap checks ===
        if ($room_id > 0 && function_exists('db_available') && db_available()) {
            try {
                db_insert('room_reservations', [
                    'room_id'   => $room_id,
                    'booking_id'=> $booking['id'],
                    'check_in'  => $check_in,
                    'check_out' => $check_out,
                    'status'    => 'reserved',
                ]);
            } catch (Exception $e) {}
        }

        // Webhook notification (Discord/Slack formatting)
        require_once __DIR__ . '/../admin/core/webhooks.php';
        send_webhook('new_booking', ['name' => $name, 'check_in' => $check_in, 'check_out' => $check_out, 'nights' => $nights, 'guests' => $guests, 'phone' => $phone]);

        // Telegram — sent centrally via notify('new_booking') below

        // Send email notification
        $to = $s['email'];
        $subject = 'Новая заявка на бронирование от ' . htmlspecialchars($name);
        $email_body = "<h3>Новая заявка на бронирование</h3>";
        $email_body .= "<p><strong>Имя:</strong> " . htmlspecialchars($name) . "<br><strong>Email:</strong> " . htmlspecialchars($email) . "</p>";
        if (!empty($phone)) $email_body .= "<p><strong>Телефон:</strong> " . htmlspecialchars($phone) . "</p>";
        $email_body .= "<p><strong>Дата заезда:</strong> " . htmlspecialchars($check_in) . "<br><strong>Дата выезда:</strong> " . htmlspecialchars($check_out) . "<br><strong>Ночей:</strong> {$nights}</p>";
        if (!empty($s['show_guests'])) $email_body .= "<p><strong>Гостей:</strong> {$guests}</p>";
        if (!empty($notes)) $email_body .= "<p><strong>Комментарий:</strong><br>" . nl2br(htmlspecialchars($notes)) . "</p>";
        require_once __DIR__ . '/../admin/core/email.php';
        send_email($to, $subject, $email_body, true);

        $sent = true;

        // Send notification (webhook + Telegram) — centralized via notify()
        require_once __DIR__ . '/../admin/core/notifications.php';
        notify('new_booking', ['name' => $name, 'phone' => $phone, 'check_in' => $check_in, 'check_out' => $check_out, 'nights' => $nights, 'guests' => $guests, 'email' => $email]);
    } // end room_unavailable else
    } // end validation else
    } // end rate limit else
    } // end honeypot/time-trap else
    } // end CSRF else
}
?>
<div class="booking-module" style="max-width:600px; margin:2rem auto;">
    <h3 style="margin-bottom:1.5rem;"><?= htmlspecialchars($s['title']) ?></h3>

    <?php if ($sent): ?>
        <div class="alert alert-success" style="padding:1rem 1.25rem; border-radius:8px; background:#d1e7dd; color:#0f5132; border:1px solid #badbcc;">
            ✓ <?= htmlspecialchars($s['success_msg']) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="padding:1rem 1.25rem; border-radius:8px; background:#f8d7da; color:#842029; border:1px solid #f5c2c7;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if (!$sent): ?>
    <form method="POST" style="display:flex; flex-direction:column; gap:1rem; position:relative;">
        <input type="hidden" name="module_booking" value="1">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($booking_csrf) ?>">
        <?= form_honeypot_field() ?>
        <?= form_timetrap_field() ?>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
            <div>
                <label style="display:block; margin-bottom:0.4rem; font-weight:600;">Имя *</label>
                <input type="text" name="bk_name" required style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;">
            </div>
            <div>
                <label style="display:block; margin-bottom:0.4rem; font-weight:600;">Email *</label>
                <input type="email" name="bk_email" required style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;">
            </div>
        </div>

        <?php if (!empty($s['show_phone'])): ?>
        <div>
            <label style="display:block; margin-bottom:0.4rem; font-weight:600;">Телефон</label>
            <input type="tel" name="bk_phone" style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;">
        </div>
        <?php endif; ?>

        <?php
        // Room selection (if DB available and rooms exist)
        $booking_rooms = [];
        if (function_exists('db_available') && db_available()) {
            try { $booking_rooms = db_select('rooms', '*', 'status = ?', ['active'], 'position ASC'); } catch (Exception $e) {}
        }
        if (!empty($booking_rooms)):
        ?>
        <div>
            <label style="display:block; margin-bottom:0.4rem; font-weight:600;">Номер</label>
            <select name="bk_room_id" style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;">
                <option value="">— Не выбран —</option>
                <?php
                $booking_cats = [];
                try { $booking_cats = db_select('room_categories', '*', '', [], 'position ASC'); } catch (Exception $e) {}
                $bc_map = [];
                foreach ($booking_cats as $bc) { $bc_map[$bc['id']] = $bc['title']; }
                ?>
                <?php foreach ($booking_rooms as $br): ?>
                <option value="<?= (int)$br['id'] ?>">
                    <?= h($br['title']) ?>
                    <?php if (isset($bc_map[$br['category_id']]) && $br['category_id']): ?> (<?= h($bc_map[$br['category_id']]) ?>)<?php endif; ?>
                    — <?= number_format($br['price'], 0, ',', ' ') ?> ₽/ночь
                    · <?= (int)$br['capacity'] ?> чел.
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
            <div>
                <label style="display:block; margin-bottom:0.4rem; font-weight:600;">Дата заезда *</label>
                <input type="date" name="bk_check_in" id="bk_check_in" required min="<?= htmlspecialchars($min_date_val) ?>" max="<?= htmlspecialchars($max_date_val) ?>" style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;">
            </div>
            <div>
                <label style="display:block; margin-bottom:0.4rem; font-weight:600;">Дата выезда *</label>
                <input type="date" name="bk_check_out" id="bk_check_out" required min="<?= htmlspecialchars($min_date_val) ?>" max="<?= htmlspecialchars($max_date_val) ?>" style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;">
            </div>
        </div>

        <!-- Nights display (auto-calculated) -->
        <div id="nights_display" style="padding:0.6rem 1rem; background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; font-size:14px; color:#1e40af; display:none;">
            <strong>Ночей:</strong> <span id="nights_count">0</span>
        </div>

        <?php if (!empty($s['show_guests'])): ?>
        <div>
            <label style="display:block; margin-bottom:0.4rem; font-weight:600;">Количество гостей</label>
            <input type="number" name="bk_guests" value="1" min="1" max="50" style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;">
        </div>
        <?php endif; ?>

        <div>
            <label style="display:block; margin-bottom:0.4rem; font-weight:600;">Комментарий</label>
            <textarea name="bk_notes" rows="3" style="width:100%; padding:0.6rem 0.8rem; border:1px solid #ced4da; border-radius:6px;"></textarea>
        </div>

        <button type="submit" style="padding:0.7rem 1.5rem; background:#16a34a; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600; align-self:flex-start;">
            📅 <?= htmlspecialchars($s['button_text']) ?>
        </button>
    </form>

    <script>
    // Auto-calculate nights when dates change
    (function() {
        var checkIn = document.getElementById('bk_check_in');
        var checkOut = document.getElementById('bk_check_out');
        var nightsDisplay = document.getElementById('nights_display');
        var nightsCount = document.getElementById('nights_count');

        function calcNights() {
            if (checkIn.value && checkOut.value) {
                var d1 = new Date(checkIn.value);
                var d2 = new Date(checkOut.value);
                var diff = Math.round((d2 - d1) / (1000 * 60 * 60 * 24));
                if (diff > 0) {
                    nightsCount.textContent = diff;
                    nightsDisplay.style.display = 'block';
                    // Set min date for checkout = checkin + 1 day
                    var nextDay = new Date(d1);
                    nextDay.setDate(nextDay.getDate() + 1);
                    var yyyy = nextDay.getFullYear();
                    var mm = String(nextDay.getMonth() + 1).padStart(2, '0');
                    var dd = String(nextDay.getDate()).padStart(2, '0');
                    checkOut.min = yyyy + '-' + mm + '-' + dd;
                } else {
                    nightsDisplay.style.display = 'none';
                }
            } else {
                nightsDisplay.style.display = 'none';
            }
        }

        checkIn.addEventListener('change', calcNights);
        checkOut.addEventListener('change', calcNights);
    })();
    </script>
    <?php endif; ?>
</div>
