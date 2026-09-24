<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/trash.php';
require_once __DIR__ . '/core/audit.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash_message('Ошибка безопасности.', 'danger');
    header('Location: pages.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash_message('Неверный идентификатор страницы.', 'danger');
    header('Location: pages.php');
    exit;
}

$found = false;
$lang_to_delete = null;
$pages = [];
$deleted_page = null;

// Ищем страницу во всех языках
foreach (SUPPORTED_LANGS as $lang_info) {
    $lang_code = $lang_info['code'];
    $pages_in_lang = read_content('pages.json', [], $lang_code);

    foreach ($pages_in_lang as $index => $page) {
        if ($page['id'] === $id) {
            $found = true;
            $lang_to_delete = $lang_code;
            $deleted_page = $page;
            $pages = $pages_in_lang;
            unset($pages[$index]);
            break 2;
        }
    }
}

if (!$found) {
    set_flash_message('Ошибка: страница для удаления не найдена.', 'danger');
    header('Location: pages.php');
    exit;
}

// Move to trash before permanent removal
if ($deleted_page) {
    trash_move('page', $deleted_page, $lang_to_delete);
    audit_log('delete_page', "id={$id} title='" . ($deleted_page['title'] ?? '') . "' lang={$lang_to_delete} (moved to trash)");
}

// Сохраняем обновленный список страниц
write_content('pages.json', array_values($pages), $lang_to_delete);

// Очистка кэша
require_once __DIR__ . '/core/cache.php';
cache_clear();

set_flash_message('Страница перемещена в корзину. Можно восстановить.', 'success');
header('Location: pages.php');
exit;
