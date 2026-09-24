<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'export') {
    // Export all content to JSON
    $export = [
        'version' => 'AICMS Export v1.0',
        'created' => date('Y-m-d H:i:s'),
        'languages' => [],
    ];
    
    foreach (SUPPORTED_LANGS as $lang_info) {
        $lang_code = $lang_info['code'];
        $export['languages'][$lang_code] = [
            'pages' => read_content('pages.json', [], $lang_code),
            'news' => read_content('news.json', [], $lang_code),
        ];
    }
    
    // Add config (without sensitive data)
    $config = read_json(__DIR__ . '/../data/config.json');
    // Secrets must never leave the site: DB creds, API keys, integration tokens
    unset(
        $config['storage']['password'],
        $config['ai']['text']['api_key'],
        $config['ai']['image']['api_key'],
        $config['ai']['video']['api_key'],
        $config['openrouter_api_key'],
        $config['deepseek_api_key'],
        $config['gemini_api_key'],
        $config['api_token'],
        $config['webhook_url'],
        $config['telegram_bot_token'],
        $config['smtp']['password']
    );
    $export['config'] = $config;
    
    // Add menu
    $menu_file = __DIR__ . '/../data/menu.json';
    if (file_exists($menu_file)) {
        $export['menu'] = json_decode(file_get_contents($menu_file), true);
    }
    
    // Add widget zones
    $zones_file = __DIR__ . '/../data/widget_zones.json';
    if (file_exists($zones_file)) {
        $export['widget_zones'] = json_decode(file_get_contents($zones_file), true);
    }
    
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="aicms_export_' . date('Y-m-d_His') . '.json"');
    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash_message('CSRF error.', 'danger');
        header('Location: settings.php?tab=backup');
        exit;
    }
    
    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        set_flash_message('Файл не загружен.', 'danger');
        header('Location: settings.php?tab=backup');
        exit;
    }
    
    $content = file_get_contents($_FILES['import_file']['tmp_name']);
    $data = json_decode($content, true);
    
    if (!is_array($data) || !isset($data['version'])) {
        set_flash_message('Неверный формат файла.', 'danger');
        header('Location: settings.php?tab=backup');
        exit;
    }
    
    $imported = 0;
    
    // Import pages and news per language
    if (isset($data['languages'])) {
        foreach ($data['languages'] as $lang_code => $lang_data) {
            if (isset($lang_data['pages'])) {
                write_content('pages.json', $lang_data['pages'], $lang_code);
                $imported += count($lang_data['pages']);
            }
            if (isset($lang_data['news'])) {
                write_content('news.json', $lang_data['news'], $lang_code);
                $imported += count($lang_data['news']);
            }
        }
    }
    
    // Import menu
    if (isset($data['menu'])) {
        file_put_contents(__DIR__ . '/../data/menu.json', json_encode($data['menu'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    // Import widget zones
    if (isset($data['widget_zones'])) {
        file_put_contents(__DIR__ . '/../data/widget_zones.json', json_encode($data['widget_zones'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    // Clear cache
    require_once __DIR__ . '/core/cache.php';
    cache_clear();
    
    set_flash_message("Импортировано элементов: {$imported}", 'success');
    header('Location: settings.php?tab=backup');
    exit;
}

// If no action, redirect
header('Location: settings.php');
exit;
