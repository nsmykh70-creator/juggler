<?php
// Включаем буферизацию, чтобы случайные ошибки не сломали JSON-ответ
ob_start();

require_once __DIR__ . '/core/functions.php';

// Устанавливаем заголовок в самом начале
header('Content-Type: application/json; charset=utf-8');

function send_froala_error($message, $code = 400) {
    ob_end_clean(); // Очищаем буфер, чтобы отдать чистый JSON
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

// Проверка аутентификации
if (!isset($_SESSION['admin'])) {
    send_froala_error('Доступ запрещен.', 403);
}

// --- ФИНАЛЬНОЕ ИСПРАВЛЕНИЕ: Проверяем CSRF токен, который передает Froala ---
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    send_froala_error('Ошибка безопасности CSRF.', 403);
}

// Froala sends files in $_FILES['file']
if (empty($_FILES['file'])) {
    send_froala_error('Файл не найден.');
}

$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    send_froala_error('Ошибка сервера при загрузке. Код: ' . $file['error'], 500);
}

// File size limit: 8 MB (prevents DoS via huge uploads)
if ($file['size'] > 8 * 1024 * 1024) {
    send_froala_error('Файл слишком большой (макс. 8 МБ).', 413);
}

$upload_dir_relative = '/uploads/images/';
$upload_dir_absolute = realpath(__DIR__ . '/..') . $upload_dir_relative;
if (!is_dir($upload_dir_absolute)) { mkdir($upload_dir_absolute, 0755, true); }
$allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if (!in_array($mime_type, $allowed_mime_types, true)) {
    send_froala_error('Недопустимый тип файла. Разрешены: JPG, PNG, GIF, WEBP.');
}

// Scan first bytes for PHP/script tags (defence in depth — even though
// Froala only uploads images, an authenticated admin could be tricked
// into uploading a polyglot file via a malicious browser extension).
$head = @file_get_contents($file['tmp_name'], false, null, 0, 4096);
if ($head !== false) {
    if (preg_match('/<\?(php|=|\s)/i', $head) || stripos($head, '<%') !== false) {
        send_froala_error('Файл содержит код серверного скрипта.');
    }
}

// Sanitize filename: keep only ASCII alphanumeric, dot, dash, underscore.
// Generate a safe name from the original extension + random suffix to
// avoid collisions and prevent path traversal via crafted filenames.
$orig_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$ext_map = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];
$ext = $ext_map[$mime_type] ?? ($orig_ext !== '' ? preg_replace('/[^a-z0-9]/', '', $orig_ext) : 'jpg');
// If original extension was a disguised PHP (e.g. "image.jpg.php"), drop it
if (in_array($ext, ['php', 'phtml', 'phar', 'php3', 'php5', 'php7', 'phps', 'pht'], true)) {
    $ext = 'jpg';
}
// Compute the dated subfolder ONCE (calling date() twice could in theory
// return different values across a midnight boundary, breaking the path).
$date_subdir = date('Y/m/d');
$unique_filename = $date_subdir . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
$dest_dir = $upload_dir_absolute . $date_subdir;
if (!is_dir($dest_dir)) { mkdir($dest_dir, 0755, true); }
$destination_path = $upload_dir_absolute . $unique_filename;

if (move_uploaded_file($file['tmp_name'], $destination_path)) {
    // Build full absolute URL as required by Froala
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