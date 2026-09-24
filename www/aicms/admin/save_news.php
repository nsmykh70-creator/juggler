<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

// --- Проверка метода запроса и CSRF токена ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    require_once __DIR__ . '/core/cache.php'; cache_clear(); header('Location: news.php');
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash_message('Ошибка безопасности: неверный CSRF токен.', 'danger');
    require_once __DIR__ . '/core/cache.php'; cache_clear(); header('Location: news.php');
    exit;
}

// --- Сбор и проверка данных из формы ---
$id = (int)($_POST['id'] ?? 0);
$lang = $_POST['lang'] ?? DEFAULT_LANG;
$title = trim($_POST['title'] ?? '');
$content = $_POST['content'] ?? '';
$date = $_POST['date'] ?? date('Y-m-d');
$category = trim($_POST['category'] ?? '');
$tags = trim($_POST['tags'] ?? '');
$status = $_POST['status'] ?? 'published';
$publish_at = trim($_POST['publish_at'] ?? '');
if (!in_array($status, ['published', 'draft', 'scheduled'])) $status = 'published';
// Visibility: the checkbox 'published' controls whether the news shows on site.
// If status is draft or scheduled, force published=false (overrides checkbox).
$published = isset($_POST['published']) ? (bool)$_POST['published'] : true;
if ($status === 'draft' || $status === 'scheduled') $published = false;

if (empty($title) || empty($date)) {
    set_flash_message('Заголовок и дата не могут быть пустыми.', 'danger');
    require_once __DIR__ . '/core/cache.php'; cache_clear(); header('Location: edit_news.php' . ($id ? '?id=' . $id : ''));
    exit;
}

// --- Авто-вставка Lumina-кнопки «Читать далее / Свернуть» ---
// Если в контенте НЕТ [data-lumina-toggle], вставляем кнопку после 1-го блока.
// Кнопка — ЧАСТЬ КОНТЕНТА: видна в редакторе Lumina, можно перемещать/удалять.
// ИДЕНТИЧНА кнопке из toggleButtonCreator.js (редактор Lumina).
// Контент ДО кнопки = превью (всегда виден).
// Контент ПОСЛЕ кнопки = скрыт lumina-toggle.js (раскрывается по клику).
if (strpos($content, 'data-lumina-toggle') === false) {
    $pattern = '/<(p|h[1-6]|ul|ol|div|blockquote|pre|table)([^>]*)>.*?<\/\1\s*>/s';
    $count = preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);
    if ($count >= 2) {
        // Insert after the 1st block (index 0) — need at least 2 blocks
        $nth_match = $matches[0][0];
        $insert_pos = $nth_match[1] + strlen($nth_match[0]);
        // EXACT same HTML as toggleButtonCreator.js — data-lumina-toggle (no ="")
        $toggle_html =
            '<div class="lumina-toggle-block" data-lumina-toggle>' .
                '<button type="button" ' .
                    'class="btn btn-primary btn-sm toggle-news-btn lumina-toggle-btn" ' .
                    'data-text-collapsed="Читать далее" ' .
                    'data-text-expanded="Свернуть" ' .
                    'style="display:inline-block; background-color:#2563eb; color:#ffffff; ' .
                    'border-radius:8px; padding:10px 20px; margin:0; font-size:14px; ' .
                    'box-shadow:rgba(0,0,0,0.15) 0px 2px 4px 0px; border:none; ' .
                    'line-height:1.2; font-weight:500; cursor:pointer; ' .
                    'transition: all 0.1s ease-out, box-shadow 0.15s ease-out, filter 0.1s ease-out;" ' .
                    'contenteditable="false">Читать далее</button>' .
            '</div>';
        $content = substr($content, 0, $insert_pos) . "\n" . $toggle_html . "\n" . substr($content, $insert_pos);
    }
}

// --- Логика сохранения ---
$all_news = read_content('news.json', [], $lang);
$found = false;

// [ИЗМЕНЕНО] Создаем массив с данными новости и принудительно задаем page_slug
// published: true = видно на сайте, false = скрыто (без удаления)
$news_data = [
    'title' => $title,
    'content' => $content,
    'date' => $date,
    'page_slug' => 'news',
    'published' => $published,
    'category' => $category,
    'tags' => $tags,
    'status' => $status,
];
if (!empty($publish_at)) $news_data['publish_at'] = $publish_at;

if ($id > 0) {
    // Редактирование существующей новости
    // Save version before overwriting
    require_once __DIR__ . '/core/versioning.php';
    foreach ($all_news as $key => $item) {
        if ($item['id'] === $id) {
            version_save('news', $item, $lang);
            $all_news[$key] = array_merge($item, $news_data);
            $found = true;
            break;
        }
    }
    if ($found) {
        set_flash_message('Новость успешно обновлена!', 'success');
    } else {
        set_flash_message('Ошибка: новость для обновления не найдена.', 'danger');
    }
} else {
    // Создание новой новости
    $max_id = empty($all_news) ? 0 : max(array_column($all_news, 'id'));
    $news_data['id'] = $max_id + 1;
    $news_data['created'] = date('Y-m-d H:i:s');
    $all_news[] = $news_data;
    set_flash_message('Новость успешно создана!', 'success');
}

// Сохраняем данные и перенаправляем
write_content('news.json', $all_news, $lang);
require_once __DIR__ . '/core/cache.php'; cache_clear(); header('Location: news.php');
exit;