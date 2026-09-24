<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Неверный метод.']); exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['status' => 'error', 'message' => 'Ошибка CSRF.']); exit;
}

$module_name = $_POST['module_name'] ?? '';
$content = $_POST['content'] ?? '';

// Валидация
if (empty($module_name) || !preg_match('/^[a-z0-9_]+$/', $module_name)) {
    echo json_encode(['status' => 'error', 'message' => 'Недопустимое имя модуля.']); exit;
}

$modules_dir = realpath(__DIR__ . '/../modules');
$file_path = $modules_dir . '/' . $module_name . '.php';

// Проверка безопасности, чтобы не выйти за пределы папки /modules/
if (strpos(realpath(dirname($file_path)), $modules_dir) !== 0) {
    echo json_encode(['status' => 'error', 'message' => 'Ошибка безопасности пути.']); exit;
}

if (file_put_contents($file_path, $content, LOCK_EX) === false) {
     echo json_encode(['status' => 'error', 'message' => 'Ошибка записи файла. Проверьте права.']); exit;
}

// Invalidate cache — module code change affects frontend rendering
require_once __DIR__ . '/core/cache.php';
cache_clear();

echo json_encode([
    'status' => 'success',
    'message' => 'Модуль ' . h($module_name) . ' сохранен!',
    'new_csrf_token' => generate_csrf_token()
]);
exit;