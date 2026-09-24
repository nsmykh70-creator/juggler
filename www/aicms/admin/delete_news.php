<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/trash.php';
require_once __DIR__ . '/core/audit.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: news.php');
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash_message('Ошибка безопасности: неверный CSRF токен.', 'danger');
    header('Location: news.php');
    exit;
}

$id_to_delete = (int)($_POST['id'] ?? 0);

if ($id_to_delete > 0) {
    $all_news = read_content('news.json');

    // Find the news item to delete (for trash)
    $deleted_news = null;
    foreach ($all_news as $item) {
        if (isset($item['id']) && $item['id'] === $id_to_delete) {
            $deleted_news = $item;
            break;
        }
    }

    $news_filtered = array_filter($all_news, function($item) use ($id_to_delete) {
        return isset($item['id']) && $item['id'] !== $id_to_delete;
    });

    if (count($all_news) > count($news_filtered)) {
        // Move to trash before removing
        if ($deleted_news) {
            trash_move('news', $deleted_news, ADMIN_LANG);
            audit_log('delete_news', "id={$id_to_delete} title='" . ($deleted_news['title'] ?? '') . "' (moved to trash)");
        }
        write_content('news.json', array_values($news_filtered));
        set_flash_message('Новость перемещена в корзину. Можно восстановить.', 'success');
    } else {
        set_flash_message('Ошибка: новость для удаления не найдена.', 'danger');
    }
} else {
    set_flash_message('Ошибка: неверный ID для удаления.', 'danger');
}

require_once __DIR__ . '/core/cache.php';
cache_clear();
header('Location: news.php');
exit;
