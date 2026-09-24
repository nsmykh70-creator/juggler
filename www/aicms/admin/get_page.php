<?php
session_start();
if (!isset($_SESSION['admin'])) die();

require_once __DIR__ . '/core/functions.php';

// Ищем страницу во всех языках
$id = (int)$_GET['id'];
$page = null;
foreach (SUPPORTED_LANGS as $lang_info) {
    $pages = read_content('pages.json', [], $lang_info['code']);
    foreach ($pages as $p) {
        if ($p['id'] === $id) {
            $page = $p;
            break 2;
        }
    }
}
echo json_encode($page);
?>