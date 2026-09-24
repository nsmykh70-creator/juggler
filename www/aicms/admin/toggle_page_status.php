<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash_message('Ошибка безопасности.', 'danger');
    require_once __DIR__ . '/core/cache.php'; cache_clear(); header('Location: pages.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash_message('Неверный ID страницы.', 'danger');
    require_once __DIR__ . '/core/cache.php'; cache_clear(); header('Location: pages.php');
    exit;
}

$lang_to_update = null;
$pages = [];
$found = false;

// Ищем страницу во всех языках
foreach (SUPPORTED_LANGS as $lang_info) {
    $lang_code = $lang_info['code'];
    $current_pages = read_content('pages.json', [], $lang_code);
    
    foreach ($current_pages as $index => &$page) {
        if ($page['id'] === $id) {
            $found = true;
            $lang_to_update = $lang_code;
            
            // Инвертируем статус. Если ключа нет — считаем опубликованным (true),
            // значит переключаем в скрытое (false).
            $is_published = !isset($page['published']) || $page['published'] === true;
            $page['published'] = !$is_published;
            
            $pages = $current_pages;
            break 2;
        }
    }
}

if (!$found) {
    set_flash_message('Страница для обновления статуса не найдена.', 'danger');
} else {
    write_content('pages.json', $pages, $lang_to_update);
    set_flash_message('Статус страницы успешно обновлен.', 'success');
}

require_once __DIR__ . '/core/cache.php'; cache_clear(); header('Location: pages.php');
exit;