<?php
/**
 * AI Settings API
 * GET: returns current AI config from config.json
 * POST: saves AI config to config.json
 */
require_once __DIR__ . '/core/functions.php';
require_auth();
header('Content-Type: application/json; charset=utf-8');

$config_file = __DIR__ . '/../data/config.json';
$config = aicms_apply_env(read_json($config_file, []));

// GET — return current AI settings (secrets overlaid from data/.env.php)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ai = $config['ai'] ?? [
        'text'  => ['provider' => 'openrouter', 'model' => 'mistralai/mistral-7b-instruct:free', 'api_key' => ''],
        'image' => ['provider' => 'pollinations', 'model' => 'flux', 'api_key' => ''],
        'video' => ['provider' => 'disabled', 'model' => '', 'api_key' => ''],
    ];
    echo json_encode($ai, JSON_UNESCAPED_UNICODE);
    exit;
}

// POST — save AI settings (API keys go to data/.env.php, not config.json)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $config['ai'] = [
        'text' => [
            'provider' => $_POST['text_provider'] ?? 'openrouter',
            'model'    => trim($_POST['text_model'] ?? ''),
            'api_key'  => trim($_POST['text_api_key'] ?? ''),
        ],
        'image' => [
            'provider' => $_POST['image_provider'] ?? 'pollinations',
            'model'    => trim($_POST['image_model'] ?? ''),
            'api_key'  => trim($_POST['image_api_key'] ?? ''),
        ],
        'video' => [
            'provider' => $_POST['video_provider'] ?? 'disabled',
            'model'    => trim($_POST['video_model'] ?? ''),
            'api_key'  => trim($_POST['video_api_key'] ?? ''),
        ],
    ];
    // Route secrets to .env.php by provider; keep config.json clean.
    // Only blank config.json if the env write succeeded (avoid silent secret loss).
    foreach (['text', 'image', 'video'] as $_sec) {
        $_provider = $config['ai'][$_sec]['provider'] ?? '';
        $_ek = aicms_provider_env_key($_provider);
        if ($_ek && aicms_env_set($_ek, $config['ai'][$_sec]['api_key'] ?? '')) {
            $config['ai'][$_sec]['api_key'] = '';
        }
    }
    $ok = write_json($config_file, aicms_env_strip($config));
    if ($ok) {
        echo json_encode(['status' => 'success', 'message' => 'AI настройки сохранены']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Не удалось записать config.json']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
