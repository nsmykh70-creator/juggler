<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    header('Location: themes.php');
    exit;
}

$theme_name = trim($_POST['theme_name'] ?? '');

if (empty($theme_name) || !preg_match('/^[a-z0-9_]+$/', $theme_name)) {
    set_flash_message('Неверное имя шаблона.', 'danger');
    header('Location: themes.php');
    exit;
}
$themes_dir = __DIR__ . '/../themes/';
$new_theme_path = $themes_dir . $theme_name;
$scaffold_path = $themes_dir . '_template_scaffold';

if (is_dir($new_theme_path)) {
    set_flash_message("Шаблон '".h($theme_name)."' уже существует.", 'warning');
    header('Location: themes.php');
    exit;
}
if (!is_dir($scaffold_path)) {
     set_flash_message("Папка-каркас 'themes/_template_scaffold' не найдена!", 'danger');
    header('Location: themes.php');
    exit;
}

function recurse_copy($src, $dst) {
    $dir = opendir($src); @mkdir($dst);
    while (($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src.'/'.$file)) { recurse_copy($src.'/'.$file, $dst.'/'.$file); }
            else { copy($src.'/'.$file, $dst.'/'.$file); }
        }
    }
    closedir($dir);
}
recurse_copy($scaffold_path, $new_theme_path);

set_flash_message("Шаблон '".h($theme_name)."' создан.", 'success');
header('Location: themes.php');
exit;