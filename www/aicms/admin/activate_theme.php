<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { require_once __DIR__ . '/core/cache.php'; cache_clear(); header('Location: themes.php'); exit; }
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash_message('Ошибка безопасности (CSRF).', 'danger'); require_once __DIR__ . '/core/cache.php'; cache_clear(); header('Location: themes.php'); exit;
}

$theme_name = $_POST['theme_name'] ?? '';

if (!empty($theme_name) && is_dir(__DIR__ . '/../themes/' . $theme_name)) {
    $config = read_json(__DIR__ . '/../data/config.json');
    $config['theme'] = $theme_name;
    write_json(__DIR__ . '/../data/config.json', $config);
    
    // Also update sites.json if exists (sync theme)
    $sites_file = __DIR__ . '/../data/sites.json';
    if (file_exists($sites_file)) {
        $sites = json_decode(@file_get_contents($sites_file), true);
        if (is_array($sites)) {
            foreach ($sites as &$s) {
                if ($s['id'] === 'default' || $s['host'] === ($_SERVER['HTTP_HOST'] ?? 'localhost')) {
                    $s['theme'] = $theme_name;
                }
            }
            file_put_contents($sites_file, json_encode($sites, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
    }
    
    set_flash_message("Тема '{$theme_name}' активирована.", 'success');
} else {
    set_flash_message('Неверное имя темы.', 'danger');
}
require_once __DIR__ . '/core/cache.php'; cache_clear(); header('Location: themes.php');
exit;