<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

if($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash_message('Ошибка CSRF.', 'danger'); header('Location: backups.php'); exit;
}

$settings = [
    'enabled' => isset($_POST['enabled']),
    'period' => $_POST['period'] ?? 'daily',
    'last_run' => 0 // Сбрасываем таймер, чтобы бэкап создался при следующем заходе
];

write_json(__DIR__ . '/../data/backup_settings.json', $settings);
set_flash_message('Настройки авто-бэкапа сохранены.', 'success');
header('Location: backups.php');
exit;