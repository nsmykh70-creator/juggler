<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash_message('Ошибка безопасности.', 'danger');
    require_once __DIR__ . '/core/cache.php'; cache_clear(); header('Location: news.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash_message('Неверный ID новости.', 'danger');
    require_once __DIR__ . '/core/cache.php'; cache_clear(); header('Location: news.php');
    exit;
}

$lang_to_update = null;
$all_news = [];
$found = false;

// Ищем новость во всех языках
foreach (SUPPORTED_LANGS as $lang_info) {
    $lang_code = $lang_info['code'];
    $current_news = read_content('news.json', [], $lang_code);
    
    foreach ($current_news as $index => &$item) {
        if ($item['id'] === $id) {
            $found = true;
            $lang_to_update = $lang_code;
            // Инвертируем статус. Если ключа нет — считаем опубликованным (true),
            // значит переключаем в скрытое (false).
            $is_published = !isset($item['published']) || $item['published'] === true;
            $item['published'] = !$is_published;
            $all_news = $current_news;
            break 2;
        }
    }
}

if (!$found) {
    set_flash_message('Новость для обновления статуса не найдена.', 'danger');
} else {
    usort($all_news, fn($a, $b) => strtotime($b['date']) <=> strtotime($a['date']));
    write_content('news.json', $all_news, $lang_to_update);
    set_flash_message('Статус новости успешно обновлен.', 'success');
}

require_once __DIR__ . '/core/cache.php'; cache_clear(); header('Location: news.php');
exit;