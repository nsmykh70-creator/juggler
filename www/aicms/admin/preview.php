<?php
// preview.php - файл для предпросмотра шаблонов
session_start();
if (empty($_SESSION['preview_mode'])) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

$file = $_GET['file'] ?? '';
if (file_exists($file) && in_array(pathinfo($file, PATHINFO_EXTENSION), ['php', 'html'])) {
    include $file;
} else {
    echo "<h4>Предпросмотр недоступен</h4>";
}