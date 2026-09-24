<?php
// Этот файл - "песочница" для отображения одного модуля в iframe
require_once __DIR__ . '/core/functions.php';
require_auth();

$module_name = $_GET['module'] ?? '';

// Безопасность
if (empty($module_name) || !preg_match('/^[a-z0-9_]+$/', $module_name)) {
    die('<div class="m-4 p-4 bg-red-50 text-red-700 rounded-lg border border-red-200">' . t('invalid_module_name_die') . '</div>');
}

$module_path = __DIR__ . '/../modules/' . $module_name . '.php';

if (!file_exists($module_path)) {
    die('<div class="m-4 p-4 bg-amber-50 text-amber-700 rounded-lg border border-amber-200">' . t('module_file_missing_die') . '</div>');
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= t('title_module_preview') ?></title>
    <!-- Tailwind Play CDN для стилизации контента внутри модуля -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>body { padding: 1.5rem; background-color: #f8fafc; margin: 0; }</style>
</head>
<body>
    <?php
        // Делаем глобальные переменные доступными, как на сайте
        global $news, $pages, $config;
        $config = read_json(__DIR__ . '/../data/config.json');
        $pages = read_content('pages.json');
        $news = read_content('news.json');

        // Выполняем и выводим результат работы модуля
        include $module_path;
    ?>
</body>
</html>
