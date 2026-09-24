<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    header('Location: themes.php');
    exit;
}

$theme_name = trim($_POST['theme_name'] ?? '');
$layout = $_POST['layout'] ?? 'single_column';
$color = $_POST['color'] ?? 'scheme_light';
$typo = $_POST['typo'] ?? 'fonts_roboto';

// Валидация
if (empty($theme_name) || !preg_match('/^[a-z0-9_]+$/', $theme_name)) {
    set_flash_message('Неверное имя шаблона.', 'danger'); header('Location: themes.php'); exit;
}
if (!preg_match('/^[a-z0-9_]+$/', $layout) || !preg_match('/^[a-z0-9_]+$/', $color) || !preg_match('/^[a-z0-9_]+$/', $typo)) {
    set_flash_message('Неверные параметры генерации.', 'danger'); header('Location: themes.php'); exit;
}

$themes_dir = __DIR__ . '/../themes/';
$new_theme_path = $themes_dir . $theme_name;

if (is_dir($new_theme_path)) {
    set_flash_message("Шаблон '".h($theme_name)."' уже существует.", 'warning'); header('Location: themes.php'); exit;
}
mkdir($new_theme_path, 0755, true);

// Пути к частям
$parts_dir = $themes_dir . '_template_generator_parts/';

// --- ИЗМЕНЕНИЕ ЗДЕСЬ: ищем .html вместо .php ---
$layout_file = $parts_dir . 'layouts/' . $layout . '.html'; 
$color_file = $parts_dir . 'colors/' . $color . '.css';
$typo_file = $parts_dir . 'typography/' . $typo . '.css';

if (!file_exists($layout_file) || !file_exists($color_file) || !file_exists($typo_file)) {
    set_flash_message('Один из файлов для генерации не найден.', 'danger'); header('Location: themes.php'); exit;
}

// Собираем шаблон
// --- ИЗМЕНЕНИЕ ЗДЕСЬ: создаем index.html вместо index.php ---
copy($layout_file, $new_theme_path . '/index.html'); 

$css_content = file_get_contents($color_file) . "\n\n" . file_get_contents($typo_file);
file_put_contents($new_theme_path . '/style.css', $css_content);

set_flash_message("Шаблон '".h($theme_name)."' успешно сгенерирован.", 'success');
header('Location: themes.php');
exit;