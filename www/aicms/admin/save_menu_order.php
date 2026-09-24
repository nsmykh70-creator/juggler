<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

header('Content-Type: application/json; charset=utf-8');

// --- Безопасность и проверка данных ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Неверный метод запроса.']);
    exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['status' => 'error', 'message' => 'Ошибка безопасности (CSRF).']);
    exit;
}

$order_json = $_POST['order'] ?? '';
$order_ids = json_decode($order_json, true);

if (!is_array($order_ids)) { // Убрана проверка на empty, так как можно сохранить пустой порядок
    echo json_encode(['status' => 'error', 'message' => 'Получены некорректные данные порядка.']);
    exit;
}

// --- Логика сохранения ---
// [ИСПРАВЛЕНО] Читаем страницы для текущего активного языка
$pages = read_content('pages.json');

// Создаем ассоциативный массив для быстрого доступа к страницам по ID
$pages_by_id = [];
foreach ($pages as $page) {
    $pages_by_id[$page['id']] = $page;
}

// Формируем новый отсортированный массив
$sorted_pages = [];
foreach ($order_ids as $id) {
    // Проверяем, существует ли страница с таким ID, прежде чем добавить
    if (isset($pages_by_id[$id])) {
        $sorted_pages[] = $pages_by_id[$id];
    }
}

// Проверяем, все ли страницы на месте.
// Это важно, чтобы случайно не удалить страницу из списка при ошибке на клиенте.
if (count($sorted_pages) !== count($pages)) {
    // Если по какой-то причине количество не совпадает, лучше не сохранять,
    // чтобы не потерять данные.
    echo json_encode(['status' => 'error', 'message' => 'Ошибка целостности данных. Количество страниц не совпадает. Пожалуйста, обновите страницу.']);
    exit;
}

// [ИСПРАВЛЕНО] Записываем новый отсортированный массив в файл для текущего языка
write_content('pages.json', $sorted_pages);

// Invalidate cache — menu order changed all pages
require_once __DIR__ . '/core/cache.php';
cache_clear();

// Отправляем успешный ответ с новым CSRF токеном для последующих запросов
echo json_encode([
    'status' => 'success',
    'message' => 'Порядок меню успешно сохранен!',
    'new_csrf_token' => generate_csrf_token()
]);
exit;