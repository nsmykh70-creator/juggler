<?php
/**
 * AI Translation API
 * Translates page/news content to another language using configured AI provider.
 * POST: { text, target_lang, csrf_token }
 */
require_once __DIR__ . '/core/functions.php';
require_auth();
header('Content-Type: application/json; charset=utf-8');

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['error' => 'CSRF error']); exit;
}

$config = aicms_apply_env(read_json(__DIR__ . '/../data/config.json', []));
if (empty($config['features']['ai_translation'])) {
    echo json_encode(['error' => 'AI-перевод отключён. Включите в Настройки → Функции.']); exit;
}

$text = $_POST['text'] ?? '';
$target_lang = $_POST['target_lang'] ?? 'en';
$source_lang = $_POST['source_lang'] ?? 'ru';

if (empty($text) || mb_strlen($text) < 2) {
    echo json_encode(['error' => 'Нет текста для перевода']); exit;
}

$ai = $config['ai']['text'] ?? [];
$provider = $ai['provider'] ?? 'openrouter';
$model = $ai['model'] ?? 'mistralai/mistral-7b-instruct:free';
$api_key = $ai['api_key'] ?? '';

if (empty($api_key)) {
    echo json_encode(['error' => 'API ключ не настроен. Укажите в Настройки → AI.']); exit;
}

$lang_names = ['ru'=>'русский','en'=>'английский','de'=>'немецкий','fr'=>'французский','es'=>'испанский','it'=>'итальянский','pt'=>'португальский','zh'=>'китайский','ja'=>'японский','ko'=>'корейский','ar'=>'арабский'];
$target_name = $lang_names[$target_lang] ?? $target_lang;
$source_name = $lang_names[$source_lang] ?? $source_lang;

$prompt = "Переведи следующий HTML-контент с {$source_name} на {$target_name}. Сохрани все HTML-теги, форматирование и структуру. Верни только переведённый текст без пояснений.\n\n{$text}";

// Build API request based on provider
$api_url = '';
$headers = ['Content-Type: application/json'];
$body = [];

if ($provider === 'openrouter') {
    $api_url = 'https://openrouter.ai/api/v1/chat/completions';
    $headers[] = 'Authorization: Bearer ' . $api_key;
    $body = ['model' => $model, 'messages' => [['role'=>'system','content'=>'You are a professional translator. Translate HTML content preserving all tags.'], ['role'=>'user','content'=>$prompt]], 'temperature' => 0.3, 'max_tokens' => 4000];
} elseif ($provider === 'deepseek') {
    $api_url = 'https://api.deepseek.com/v1/chat/completions';
    $headers[] = 'Authorization: Bearer ' . $api_key;
    $body = ['model' => $model ?: 'deepseek-chat', 'messages' => [['role'=>'user','content'=>$prompt]], 'temperature' => 0.3];
} elseif ($provider === 'groq') {
    $api_url = 'https://api.groq.com/openai/v1/chat/completions';
    $headers[] = 'Authorization: Bearer ' . $api_key;
    $body = ['model' => $model ?: 'llama-3.1-8b-instant', 'messages' => [['role'=>'user','content'=>$prompt]], 'temperature' => 0.3];
} elseif ($provider === 'zai') {
    $api_url = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
    $headers[] = 'Authorization: Bearer ' . $api_key;
    $body = ['model' => $model ?: 'glm-4-flash', 'messages' => [['role'=>'user','content'=>$prompt]], 'temperature' => 0.3];
} else {
    echo json_encode(['error' => 'Неизвестный провайдер: ' . $provider]); exit;
}

$_is_localhost = strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false;
$ch = curl_init($api_url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => !$_is_localhost,
    CURLOPT_SSL_VERIFYHOST => $_is_localhost ? 0 : 2,
]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    echo json_encode(['error' => 'Ошибка соединения: ' . $curl_error]); exit;
}

// Handle empty response (common with free models that rate-limit)
if (empty($response)) {
    echo json_encode(['error' => 'Пустой ответ от API. Возможные причины: 1) Превышен лимит бесплатных запросов (попробуйте через минуту) 2) Модель недоступна 3) Слишком длинный текст. Попробуйте другую модель в Настройки → AI.']); exit;
}

if ($http_code !== 200) {
    $err_msg = 'API вернул код ' . $http_code;
    $err_data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Response is not JSON — show raw text (truncated)
        $err_msg .= ': ' . mb_substr($response, 0, 200);
    } elseif (!empty($err_data['error']['message'])) {
        $err_msg .= ': ' . $err_data['error']['message'];
    }
    // Specific error messages for common codes
    if ($http_code === 401) $err_msg = 'Неверный API ключ. Проверьте ключ в Настройки → AI.';
    if ($http_code === 403) $err_msg = 'Доступ запрещён (403). Без бесплатного API ключа OpenRouter блокирует запросы. Получите ключ на openrouter.ai/keys и укажите в Настройки → AI.';
    if ($http_code === 402) $err_msg = 'Недостаточно средств на аккаунте OpenRouter. Пополните баланс или используйте бесплатную модель.';
    if ($http_code === 429) $err_msg = 'Превышен лимит запросов (rate limit). Подождите минуту и попробуйте снова.';
    echo json_encode(['error' => $err_msg]); exit;
}

$data = json_decode($response, true);

// Check if JSON parsing failed
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['error' => 'API вернул некорректный JSON. Возможно, модель недоступна. Попробуйте другую модель в Настройки → AI.']); exit;
}

// Check for API-level error in the response body
if (!empty($data['error'])) {
    $err_msg = $data['error']['message'] ?? 'Неизвестная ошибка API';
    echo json_encode(['error' => 'Ошибка API: ' . $err_msg]); exit;
}

// Extract translated text — handle different response formats
$translated = '';
if (isset($data['choices'][0]['message']['content'])) {
    $translated = $data['choices'][0]['message']['content'];
} elseif (isset($data['choices'][0]['text'])) {
    $translated = $data['choices'][0]['text'];
} elseif (isset($data['output']['text'])) {
    $translated = $data['output']['text'];
} elseif (isset($data['content'])) {
    $translated = $data['content'];
}

// Some providers return the content wrapped in markdown code blocks
$translated = preg_replace('/^```(?:html)?\s*\n?/i', '', $translated);
$translated = preg_replace('/\n?```\s*$/i', '', $translated);
$translated = trim($translated);

if (empty($translated)) {
    // Log the raw response for debugging
    $debug = mb_substr($response, 0, 500);
    echo json_encode(['error' => 'Пустой ответ от API. Модель не вернула текст. Попробуйте другую модель. Debug: ' . $debug]); exit;
}

echo json_encode(['translated' => $translated, 'provider' => $provider, 'model' => $model], JSON_UNESCAPED_UNICODE);
