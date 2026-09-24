<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

header('Content-Type: application/json; charset=utf-8');

function send_json_error($message) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

// Файл приходит из нашего кастомного загрузчика
if (!isset($_FILES['asset_file'])) {
    send_json_error('Файл для загрузки не найден.');
}

$file = $_FILES['asset_file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    send_json_error('Ошибка загрузки на сервер. Код: ' . $file['error']);
}

// Безопасность: Проверяем тип файла на сервере
$allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime_type, $allowed_mime_types)) {
    send_json_error('Недопустимый тип файла. Разрешены только изображения.');
}

// Создаем директорию для загрузок, если ее нет
$upload_dir_relative = '/uploads/images/';
$upload_dir_absolute = realpath('..') . $upload_dir_relative;
if (!is_dir($upload_dir_absolute)) {
    if (!mkdir($upload_dir_absolute, 0755, true)) {
        send_json_error('Не удалось создать директорию для загрузок.');
    }
}

// Генерируем уникальное имя файла
$file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$unique_filename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $file_extension;
$destination_path = $upload_dir_absolute . $unique_filename;

// Перемещаем файл
if (move_uploaded_file($file['tmp_name'], $destination_path)) {
    // Формируем URL относительно корня сайта
    $url = $upload_dir_relative . $unique_filename;
    echo json_encode(['status' => 'success', 'url' => $url]);
} else {
    send_json_error('Не удалось переместить загруженный файл.');
}
exit;