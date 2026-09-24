<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

header('Content-Type: application/json; charset=utf-8');

function send_json_response($status, $message) {
    echo json_encode(['status' => $status, 'message' => $message, 'new_csrf_token' => generate_csrf_token()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403); send_json_response('error', 'Ошибка безопасности (CSRF).');
}

$file_relative_path = $_POST['file'] ?? '';
$content = $_POST['content'] ?? '';

if (empty($file_relative_path) || strpos($file_relative_path, '..') !== false) {
    http_response_code(400); send_json_response('error', 'Недопустимый путь к файлу.');
}
$base_dir = explode('/', $file_relative_path)[0];
if ($base_dir !== 'themes' && $base_dir !== 'modules') {
    http_response_code(400); send_json_response('error', 'Редактирование разрешено только в themes и modules.');
}
$full_path_to_save = realpath('..') . '/' . $file_relative_path;

if (strpos(realpath(dirname($full_path_to_save)), realpath('..'.'/'.$base_dir)) !== 0) {
    http_response_code(403); send_json_response('error', 'Доступ запрещён.');
}

if (file_put_contents($full_path_to_save, $content, LOCK_EX) === false) {
    http_response_code(500); send_json_response('error', 'Ошибка записи. Проверьте права.');
}

send_json_response('success', 'Файл ' . h(basename($file_relative_path)) . ' сохранен!');