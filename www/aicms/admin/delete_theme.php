<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: themes.php'); exit; }
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash_message('Ошибка безопасности (CSRF).', 'danger'); header('Location: themes.php'); exit;
}

$theme_name = $_POST['theme_name'] ?? '';
$config = read_json(__DIR__ . '/../data/config.json');

// Безопасность
if ($theme_name === $config['theme']) {
    set_flash_message('Нельзя удалить активную тему!', 'warning');
    header('Location: themes.php'); exit;
}
if (empty($theme_name) || !preg_match('/^[a-z0-9_]+$/', $theme_name)) {
     set_flash_message('Недопустимое имя темы для удаления.', 'danger');
     header('Location: themes.php'); exit;
}

$theme_path = realpath(__DIR__ . '/../themes/' . $theme_name);
$themes_root = realpath(__DIR__ . '/../themes');

// Двойная проверка, чтобы не удалить что-то вне папки themes
if ($theme_path && strpos($theme_path, $themes_root) === 0 && is_dir($theme_path)) {
    // Рекурсивное удаление
    $it = new RecursiveDirectoryIterator($theme_path, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file) {
        if ($file->isDir()){ rmdir($file->getRealPath()); } 
        else { unlink($file->getRealPath()); }
    }
    rmdir($theme_path);
    set_flash_message("Тема '{$theme_name}' успешно удалена.", 'success');
} else {
    set_flash_message("Не удалось удалить тему '{$theme_name}'.", 'danger');
}
header('Location: themes.php');
exit;