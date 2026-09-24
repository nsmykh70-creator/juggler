<?php
require_once __DIR__ . '/core/functions.php';
require_auth();
require_once __DIR__ . '/../themes/parser.php';

$theme_name = $_GET['theme'] ?? '';
$file_name = $_GET['file'] ?? 'index.html';

if (empty($theme_name) || !preg_match('/^[a-z0-9_]+$/', $theme_name)) { die('Недопустимое имя темы.'); }
if (!preg_match('/^[a-z0-9_.\-]+$/i', $file_name)) { die('Недопустимое имя файла.'); }

$file_path = realpath(__DIR__ . '/../themes/' . $theme_name . '/' . $file_name);

if (pathinfo($file_path, PATHINFO_EXTENSION) !== 'html') {
    if ($file_path && file_exists($file_path)) { header('Content-Type: text/plain; charset=utf-8'); echo file_get_contents($file_path); }
    else { echo "Файл не найден."; }
    exit;
}
if (!$file_path || !file_exists($file_path)) { die('Файл шаблона не найден.'); }

$dummy_pages = [ ['slug' => 'home', 'title' => 'Главная'], ['slug' => 'about', 'title' => 'О нас'] ];
$data_for_template = [ 'site_title' => 'Предпросмотр', 'page_title' => 'Заголовок', 'page_content' => '<p>Область контента.</p>', 'pages' => $dummy_pages, 'current_year' => date('Y') ];
echo render_template($file_path, $data_for_template);