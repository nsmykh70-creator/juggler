<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: modules.php'); exit; }
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash_message('Ошибка CSRF.', 'danger'); header('Location: modules.php'); exit;
}

$module_name = $_POST['module_name'] ?? '';

// Валидация
if (empty($module_name) || !preg_match('/^[a-z0-9_]+$/', $module_name)) {
    set_flash_message('Недопустимое имя модуля.', 'danger'); header('Location: modules.php'); exit;
}

$modules_dir = realpath(__DIR__ . '/../modules');
$file_path = $modules_dir . '/' . $module_name . '.php';

if (file_exists($file_path) && strpos(realpath($file_path), $modules_dir) === 0) {
    if (unlink($file_path)) {
        set_flash_message("Модуль '".h($module_name)."' удален.", 'success');
    } else {
        set_flash_message("Не удалось удалить файл модуля.", 'danger');
    }
} else {
    set_flash_message("Файл модуля не найден.", 'warning');
}

header('Location: modules.php');
exit;