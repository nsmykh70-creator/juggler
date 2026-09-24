<?php
// Запускаем сессию, чтобы получить доступ к секретному ключу
session_start();

// Включаем буферизацию, чтобы случайные ошибки PHP не сломали JSON-ответ
ob_start();

// Подключаем наши функции ПОСЛЕ session_start, чтобы избежать конфликтов
require_once __DIR__ . '/core/functions.php';

// Устанавливаем заголовок в самом начале
header('Content-Type: application/json; charset=utf-8');

// Функция для чистого завершения с ошибкой
function send_froala_error($message, $code = 400) {
    ob_end_clean(); // Очищаем буфер, чтобы отдать чистый JSON
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

// --- НОВЫЙ МЕХАНИЗМ АУТЕНТИФИКАЦИИ ПО ТОКЕНУ ---

// 1. Получаем секретный ключ из сессии (он есть только у залогиненного админа)
$secret_key_from_session = $_SESSION['upload_secret_key'] ?? null;
// 2. Получаем токен, который передал Froala
$token_from_request = $_POST['upload_token'] ?? '';

if (!$secret_key_from_session || !$token_from_request) {
    send_froala_error('Отсутствует ключ сессии или токен.', 403);
}
// 3. Проверяем, что токен, присланный Froala, соответствует ключу в сессии
if (!hash_equals($secret_key_from_session, $token_from_request)) {
    send_froala_error('Неверный токен загрузки.', 403);
}
// --- КОНЕЦ НОВОГО МЕХАНИЗМА ---


// Если аутентификация пройдена, продолжаем...

if (empty($_FILES['file'])) {
    send_froala_error('Файл не найден.');
}
$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    send_froala_error('Ошибка сервера при загрузке. Код: ' . $file['error'], 500);
}

$upload_dir_relative = '/uploads/images/';
$upload_dir_absolute = realpath(__DIR__ . '/..') . $upload_dir_relative;
if (!is_dir($upload_dir_absolute)) {
    mkdir($upload_dir_absolute, 0755, true);
}
$allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if (!in_array($mime_type, $allowed_mime_types)) {
    send_froala_error('Недопустимый тип файла. Разрешены: JPG, PNG, GIF, WEBP.');
}

$safe_name = preg_replace("/[^a-zA-Z0-9.\-_]/", "", basename($file['name']));
$unique_filename = time() . '_' . $safe_name;
$destination_path = $upload_dir_absolute . $unique_filename;

if (move_uploaded_file($file['tmp_name'], $destination_path)) {
    // Формируем полный абсолютный URL, как этого требует Froala
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $base_path = dirname(dirname($_SERVER['SCRIPT_NAME']));
    if ($base_path === '/' || $base_path === '\\') { $base_path = ''; }
    $url = $protocol . '://' . $host . $base_path . $upload_dir_relative . $unique_filename;

    ob_end_clean();
    echo json_encode(['link' => $url]);
} else {
    send_froala_error('Не удалось переместить файл. Проверьте права на запись.', 500);
}
exit;