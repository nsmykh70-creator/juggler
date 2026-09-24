<?php
/**
 * AICMS Notifications
 * ====================
 * Sends webhook and Telegram notifications on events.
 * 
 * Usage:
 *   require_once 'core/notifications.php';
 *   notify('new_order', ['order_id' => 123, 'total' => 5000]);
 *   notify('new_comment', ['comment_id' => 456, 'author' => 'Ivan']);
 */

function notify($event, $data = []) {
    $config_file = __DIR__ . '/../../data/config.json';
    if (!file_exists($config_file)) return;
    $config = json_decode(file_get_contents($config_file), true);
    if (!is_array($config)) return;
    $features = $config['features'] ?? [];

    // Webhooks
    if (!empty($features['webhooks']) && !empty($config['webhook_url'])) {
        _notify_webhook($config['webhook_url'], $event, $data);
    }

    // Telegram
    if (!empty($features['telegram_bot']) && !empty($config['telegram_bot_token']) && !empty($config['telegram_chat_id'])) {
        _notify_telegram($config['telegram_bot_token'], $config['telegram_chat_id'], $event, $data);
    }
}

function _notify_webhook($url, $event, $data) {
    $payload = json_encode([
        'event' => $event,
        'data' => $data,
        'timestamp' => date('c'),
        'site' => $GLOBALS['config']['site_title'] ?? 'AICMS',
    ], JSON_UNESCAPED_UNICODE);
    $is_localhost = strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-AICMS-Event: ' . $event],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => !$is_localhost,
        CURLOPT_SSL_VERIFYHOST => $is_localhost ? 0 : 2,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

function _notify_telegram($token, $chat_id, $event, $data) {
    // Escape user-controlled string values before interpolating into the
    // parse_mode=HTML message: raw < > & from user data would otherwise
    // inject formatting or make Telegram reject the message (HTTP 400 →
    // notification silently lost). Numbers (rating, total) stay intact.
    foreach ($data as $k => $v) {
        if (is_string($v)) {
            $data[$k] = htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        }
    }

    $messages = [
        'new_order' => "🛒 <b>Новый заказ!</b>\n\nНомер: <code>{$data['order_number']}</code>\nСумма: <b>{$data['total']}</b> ₽\nКлиент: {$data['customer_name']}\nEmail: {$data['customer_email']}",
        'new_comment' => "💬 <b>Новый комментарий</b>\n\nАвтор: <b>{$data['author']}</b>\nТекст: " . mb_substr($data['content'] ?? '', 0, 200),
        'new_booking' => "📅 <b>Новая заявка на бронирование</b>\n\nИмя: <b>{$data['name']}</b>\nЗаезд: {$data['check_in']}\nВыезд: {$data['check_out']}\nНочей: {$data['nights']}\nГостей: {$data['guests']}" . (!empty($data['phone']) ? "\nТелефон: {$data['phone']}" : ""),
        'new_cafe_booking' => "🍽 <b>Новая заявка на бронирование столика</b>\n\nИмя: <b>{$data['name']}</b>\nДата: {$data['date']}\nВремя: {$data['time']}\nГостей: {$data['guests']}" . (!empty($data['phone']) ? "\nТелефон: {$data['phone']}" : "") . (!empty($data['table']) ? "\nСтолик: {$data['table']}" : ""),
        'new_review' => "⭐ <b>Новый отзыв о товаре</b>\n\nТовар: {$data['product']}\nОценка: " . str_repeat('⭐', $data['rating'] ?? 5) . "\nАвтор: {$data['author']}",
        'new_user' => "👤 <b>Новый пользователь</b>\n\nИмя: {$data['username']}\nEmail: {$data['email']}",
        'new_contact_submission' => "📧 <b>Новое сообщение с сайта</b>\n\nОт: <b>{$data['name']}</b>\nEmail: {$data['email']}" . (!empty($data['phone']) ? "\nТелефон: {$data['phone']}" : "") . "\n\nСообщение:\n" . mb_substr($data['message'] ?? '', 0, 500),
        'new_newsletter_subscriber' => "📬 <b>Новый подписчик на рассылку</b>\n\nEmail: <code>{$data['email']}</code>",
    ];
    $text = $messages[$event] ?? "🔔 Событие: <b>{$event}</b>\n\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $is_localhost = strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => !$is_localhost,
        CURLOPT_SSL_VERIFYHOST => $is_localhost ? 0 : 2,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}
