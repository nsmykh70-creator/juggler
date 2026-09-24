<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

header('Content-Type: application/json; charset=utf-8');

// Check cURL
if (!function_exists('curl_init')) {
    echo json_encode(['status' => 'error', 'message' => 'PHP cURL не установлен. AI генерация требует расширение php-curl.']);
    exit;
}

// Logging setup
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$logFile = $logDir . '/ai_debug.log';
$log = function($message) use ($logFile) {
    @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] $message\n", FILE_APPEND);
};

function send_error($message, $log, $extra = []) {
    $log("ERROR: $message");
    echo json_encode(array_merge(['status' => 'error', 'message' => $message], $extra));
    exit;
}

function send_success($data, $log) {
    $log("SUCCESS: " . substr(json_encode($data), 0, 200));
    echo json_encode(array_merge(['status' => 'success'], $data));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    send_error('Security error.', $log);
}

// Load AI config (new structure under $config['ai']) — secrets overlaid from data/.env.php
$config = aicms_apply_env(read_json(__DIR__ . '/../data/config.json'));
$ai = $config['ai'] ?? [
    'text'  => ['provider' => 'openrouter', 'model' => 'mistralai/mistral-7b-instruct:free', 'api_key' => ''],
    'image' => ['provider' => 'pollinations', 'model' => 'flux', 'api_key' => ''],
    'video' => ['provider' => 'disabled', 'model' => '', 'api_key' => ''],
];

// Backward-compat: if old-style keys exist, migrate on the fly
if (!isset($config['ai'])) {
    $ai['text'] = [
        'provider' => 'openrouter',
        'model'    => 'mistralai/mistral-7b-instruct:free',
        'api_key'  => $config['openrouter_api_key'] ?? ($config['deepseek_api_key'] ?? ''),
    ];
}

$action = $_POST['action'] ?? 'text';  // 'text' | 'image' | 'video'
$log("===== AI REQUEST: action=$action =====");

/**
 * HTTP request helper using cURL.
 */
function http_request($url, $headers = [], $body = null, $method = 'POST', $timeout = 60) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERAGENT, 'AICMS/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    // Disable SSL verification on localhost (UniServer/XAMPP don't have CA bundle)
    $is_localhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1']) || strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false;
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$is_localhost);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $is_localhost ? 0 : 2);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
    } elseif ($method === 'GET') {
        // GET is default
    }
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['response' => $response, 'http_code' => $http_code, 'error' => $error];
}

/**
 * Download a file from URL and save it locally to the uploads/ai/ directory.
 * Returns the relative URL path that can be used in <img>/<video> tags.
 */
function save_remote_media($url, $ext = 'png') {
    $ext = preg_replace('/[^a-z0-9]/i', '', $ext);
    if (!$ext) $ext = 'png';
    $dir = __DIR__ . '/../uploads/ai/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $filename = 'ai_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
    $filepath = $dir . $filename;

    $ch = curl_init($url);
    $fp = fopen($filepath, 'wb');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_USERAGENT, 'AICMS/1.0');
    $is_localhost = strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false;
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$is_localhost);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $is_localhost ? 0 : 2);
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($http_code !== 200 || $error) {
        @unlink($filepath);
        return null;
    }
    return 'uploads/ai/' . $filename;
}

// =====================================================================
// TEXT GENERATION
// =====================================================================
if ($action === 'text') {
    $prompt = $_POST['prompt'] ?? '';
    if (empty($prompt)) send_error('Prompt cannot be empty.', $log);

    $cfg = $ai['text'];
    $provider = $cfg['provider'] ?? 'openrouter';
    $model = $cfg['model'] ?? '';
    $api_key = $cfg['api_key'] ?? '';

    $final_prompt = $prompt . "\n\nОтвет должен быть в формате HTML с использованием тегов h2, p, ul, li, strong, a. Не используй markdown.";
    $log("Text provider: $provider, model: $model, prompt: " . substr($final_prompt, 0, 150));

    try {
        $generated = null;

        // Check API key for providers that need it
        if (in_array($provider, ['deepseek', 'groq', 'zai']) && empty($api_key)) {
            send_error('API ключ не настроен для провайдера ' . $provider . '. Укажите его в Настройки → AI или в редакторе (шестерёнка → AI Настройки).', $log);
        }

        if ($provider === 'openrouter') {
            if (empty($api_key)) {
                send_error('OpenRouter требует бесплатный API ключ. Зарегистрируйтесь на openrouter.ai/keys, получите ключ и укажите его в Настройки → AI. Ключ бесплатный, без оплаты.', $log);
            }
            $url = 'https://openrouter.ai/api/v1/chat/completions';
            $headers = [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json',
                'HTTP-Referer: ' . ($_SERVER['HTTP_ORIGIN'] ?? 'http://localhost'),
                'X-Title: AICMS',
            ];
            $body = [
                'model' => $model ?: 'mistralai/mistral-7b-instruct:free',
                'messages' => [['role' => 'user', 'content' => $final_prompt]],
                'temperature' => 0.7,
                'max_tokens' => 1500,
            ];
            $r = http_request($url, $headers, $body, 'POST', 60);
            if ($r['http_code'] !== 200) {
                $err = json_decode($r['response'], true);
                $err_msg = $err['error']['message'] ?? $r['error'] ?? 'Unknown';
                if ($r['http_code'] === 401) $err_msg = 'Неверный API ключ. Проверьте ключ на openrouter.ai/keys';
                if ($r['http_code'] === 403) $err_msg = 'Доступ запрещён (403). Возможные причины: неверный ключ, исчерпан лимит, или модель недоступна. Попробуйте модель mistralai/mistral-7b-instruct:free';
                if ($r['http_code'] === 429) $err_msg = 'Превышен лимит запросов. Подождите немного или используйте другой провайдер.';
                send_error('OpenRouter error (' . $r['http_code'] . '): ' . $err_msg, $log);
            }
            $data = json_decode($r['response'], true);
            $generated = $data['choices'][0]['message']['content'] ?? '';
        }

        elseif ($provider === 'deepseek') {
            if (empty($api_key)) send_error('DeepSeek API key is required.', $log);
            $url = 'https://api.deepseek.com/v1/chat/completions';
            $headers = [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json',
            ];
            $body = [
                'model' => $model ?: 'deepseek-chat',
                'messages' => [['role' => 'user', 'content' => $final_prompt]],
                'temperature' => 0.7,
                'max_tokens' => 1500,
            ];
            $r = http_request($url, $headers, $body, 'POST', 60);
            if ($r['http_code'] !== 200) {
                $err = json_decode($r['response'], true);
                send_error('DeepSeek error (' . $r['http_code'] . '): ' . ($err['error']['message'] ?? 'Unknown'), $log);
            }
            $data = json_decode($r['response'], true);
            $generated = $data['choices'][0]['message']['content'] ?? '';
        }

        elseif ($provider === 'groq') {
            if (empty($api_key)) send_error('Groq API key is required.', $log);
            $url = 'https://api.groq.com/openai/v1/chat/completions';
            $headers = [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json',
            ];
            $body = [
                'model' => $model ?: 'llama-3.1-8b-instant',
                'messages' => [['role' => 'user', 'content' => $final_prompt]],
                'temperature' => 0.7,
                'max_tokens' => 1500,
            ];
            $r = http_request($url, $headers, $body, 'POST', 60);
            if ($r['http_code'] !== 200) {
                $err = json_decode($r['response'], true);
                send_error('Groq error (' . $r['http_code'] . '): ' . ($err['error']['message'] ?? 'Unknown'), $log);
            }
            $data = json_decode($r['response'], true);
            $generated = $data['choices'][0]['message']['content'] ?? '';
        }

        elseif ($provider === 'zai') {
            if (empty($api_key)) send_error('Z.ai API key is required.', $log);
            $url = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
            $headers = [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json',
            ];
            $body = [
                'model' => $model ?: 'glm-4-flash',
                'messages' => [['role' => 'user', 'content' => $final_prompt]],
                'temperature' => 0.7,
                'max_tokens' => 1500,
            ];
            $r = http_request($url, $headers, $body, 'POST', 60);
            if ($r['http_code'] !== 200) {
                $err = json_decode($r['response'], true);
                send_error('Z.ai error (' . $r['http_code'] . '): ' . ($err['error']['message'] ?? 'Unknown'), $log);
            }
            $data = json_decode($r['response'], true);
            $generated = $data['choices'][0]['message']['content'] ?? '';
        }

        elseif ($provider === 'openai') {
            if (empty($api_key)) send_error('OpenAI API key required. Get it at platform.openai.com/api-keys', $log);
            $url = 'https://api.openai.com/v1/chat/completions';
            $headers = ['Authorization: Bearer ' . $api_key, 'Content-Type: application/json'];
            $body = ['model' => $model ?: 'gpt-3.5-turbo', 'messages' => [['role' => 'user', 'content' => $final_prompt]], 'temperature' => 0.7, 'max_tokens' => 1500];
            $r = http_request($url, $headers, $body, 'POST', 60);
            if ($r['http_code'] !== 200) { $err = json_decode($r['response'], true); send_error('OpenAI error (' . $r['http_code'] . '): ' . ($err['error']['message'] ?? 'Unknown'), $log); }
            $data = json_decode($r['response'], true);
            $generated = $data['choices'][0]['message']['content'] ?? '';
        }

        elseif ($provider === 'anthropic') {
            if (empty($api_key)) send_error('Anthropic API key required. Get it at console.anthropic.com', $log);
            $url = 'https://api.anthropic.com/v1/messages';
            $headers = ['x-api-key: ' . $api_key, 'Content-Type: application/json', 'anthropic-version: 2023-06-01'];
            $body = ['model' => $model ?: 'claude-3-haiku-20240307', 'max_tokens' => 1500, 'messages' => [['role' => 'user', 'content' => $final_prompt]]];
            $r = http_request($url, $headers, $body, 'POST', 60);
            if ($r['http_code'] !== 200) { $err = json_decode($r['response'], true); send_error('Anthropic error (' . $r['http_code'] . '): ' . ($err['error']['message'] ?? 'Unknown'), $log); }
            $data = json_decode($r['response'], true);
            $generated = $data['content'][0]['text'] ?? '';
        }

        elseif ($provider === 'mistral') {
            if (empty($api_key)) send_error('Mistral API key required. Get it at console.mistral.ai', $log);
            $url = 'https://api.mistral.ai/v1/chat/completions';
            $headers = ['Authorization: Bearer ' . $api_key, 'Content-Type: application/json'];
            $body = ['model' => $model ?: 'mistral-tiny', 'messages' => [['role' => 'user', 'content' => $final_prompt]], 'temperature' => 0.7, 'max_tokens' => 1500];
            $r = http_request($url, $headers, $body, 'POST', 60);
            if ($r['http_code'] !== 200) { $err = json_decode($r['response'], true); send_error('Mistral error (' . $r['http_code'] . '): ' . ($err['error']['message'] ?? 'Unknown'), $log); }
            $data = json_decode($r['response'], true);
            $generated = $data['choices'][0]['message']['content'] ?? '';
        }

        elseif ($provider === 'together') {
            if (empty($api_key)) send_error('Together AI API key required. Get it at api.together.xyz', $log);
            $url = 'https://api.together.xyz/v1/chat/completions';
            $headers = ['Authorization: Bearer ' . $api_key, 'Content-Type: application/json'];
            $body = ['model' => $model ?: 'mistralai/Mistral-7B-Instruct-v0.2', 'messages' => [['role' => 'user', 'content' => $final_prompt]], 'temperature' => 0.7, 'max_tokens' => 1500];
            $r = http_request($url, $headers, $body, 'POST', 60);
            if ($r['http_code'] !== 200) { $err = json_decode($r['response'], true); send_error('Together AI error (' . $r['http_code'] . '): ' . ($err['error']['message'] ?? 'Unknown'), $log); }
            $data = json_decode($r['response'], true);
            $generated = $data['choices'][0]['message']['content'] ?? '';
        }

        elseif ($provider === 'google') {
            if (empty($api_key)) send_error('Google AI API key required. Get it at aistudio.google.com/app/apikey', $log);
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model ?: 'gemini-1.5-flash') . ':generateContent?key=' . $api_key;
            $headers = ['Content-Type: application/json'];
            $body = ['contents' => [['parts' => [['text' => $final_prompt]]]], 'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 1500]];
            $r = http_request($url, $headers, $body, 'POST', 60);
            if ($r['http_code'] !== 200) { $err = json_decode($r['response'], true); send_error('Google AI error (' . $r['http_code'] . '): ' . ($err['error']['message'] ?? 'Unknown'), $log); }
            $data = json_decode($r['response'], true);
            $generated = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        }

        else {
            send_error('Unknown text provider: ' . $provider . '. Available: openrouter, deepseek, groq, zai, openai, anthropic, mistral, together, google', $log);
        }

        if (empty($generated)) {
            send_error('Provider returned empty content.', $log);
        }

        send_success(['content' => $generated, 'provider' => $provider, 'type' => 'text'], $log);

    } catch (Exception $e) {
        send_error('Exception: ' . $e->getMessage(), $log);
    }
}

// =====================================================================
// SEO GENERATION — generate meta title + meta description from content
// =====================================================================
elseif ($action === 'seo') {
    $content = $_POST['content'] ?? '';
    $title = $_POST['title'] ?? '';
    if (empty($content)) send_error('Content cannot be empty for SEO generation.', $log);

    $cfg = $ai['text'];
    $provider = $cfg['provider'] ?? 'openrouter';
    $model = $cfg['model'] ?? '';
    $api_key = $cfg['api_key'] ?? '';

    $plain_text = strip_tags($content);
    $plain_text = mb_substr($plain_text, 0, 2000);

    $seo_prompt = "Ты SEO-эксперт. На основе заголовка и текста страницы создай мета-теги для поисковых систем.\n\n";
    $seo_prompt .= "Заголовок страницы: {$title}\n\n";
    $seo_prompt .= "Текст страницы (сокращённый):\n{$plain_text}\n\n";
    $seo_prompt .= 'Верни ответ в формате JSON (без markdown, без ```): {"meta_title": "оптимизированный заголовок до 60 символов", "meta_description": "описание до 160 символов", "keywords": "5-7 ключевых слов через запятую"}';

    $log("SEO generation, provider: $provider");
    $generated = null;

    if ($provider === 'openrouter') {
        if (empty($api_key)) {
            send_error('OpenRouter требует бесплатный API ключ. Получите на openrouter.ai/keys и укажите в Настройки → AI.', $log);
        }
        $url = 'https://openrouter.ai/api/v1/chat/completions';
        $headers = ['Authorization: Bearer ' . $api_key, 'Content-Type: application/json', 'HTTP-Referer: ' . ($_SERVER['HTTP_ORIGIN'] ?? 'https://aicms.rf.gd'), 'X-Title: AICMS'];
        $body = ['model' => $model ?: 'mistralai/mistral-7b-instruct:free', 'messages' => [['role' => 'user', 'content' => $seo_prompt]], 'temperature' => 0.3, 'max_tokens' => 500];
        $r = http_request($url, $headers, $body, 'POST', 30);
        if ($r['http_code'] === 200 && !empty($r['response'])) {
            $data = json_decode($r['response'], true);
            if (json_last_error() === JSON_ERROR_NONE) { $generated = $data['choices'][0]['message']['content'] ?? ''; }
        } elseif ($r['http_code'] === 403) {
            send_error('OpenRouter: доступ запрещён (403). Проверьте API ключ в Настройки → AI. Без ключа бесплатная модель может не работать.', $log);
        } elseif ($r['http_code'] === 429) {
            send_error('Превышен лимит запросов OpenRouter. Подождите минуту.', $log);
        }
    } elseif ($provider === 'deepseek') {
        $url = 'https://api.deepseek.com/v1/chat/completions';
        $headers = ['Authorization: Bearer ' . $api_key, 'Content-Type: application/json'];
        $body = ['model' => $model ?: 'deepseek-chat', 'messages' => [['role' => 'user', 'content' => $seo_prompt]], 'temperature' => 0.3, 'max_tokens' => 500];
        $r = http_request($url, $headers, $body, 'POST', 30);
        if ($r['http_code'] === 200 && !empty($r['response'])) { $data = json_decode($r['response'], true); if (json_last_error() === JSON_ERROR_NONE) { $generated = $data['choices'][0]['message']['content'] ?? ''; } }
    } elseif ($provider === 'groq') {
        $url = 'https://api.groq.com/openai/v1/chat/completions';
        $headers = ['Authorization: Bearer ' . $api_key, 'Content-Type: application/json'];
        $body = ['model' => $model ?: 'llama-3.1-8b-instant', 'messages' => [['role' => 'user', 'content' => $seo_prompt]], 'temperature' => 0.3, 'max_tokens' => 500];
        $r = http_request($url, $headers, $body, 'POST', 30);
        if ($r['http_code'] === 200 && !empty($r['response'])) { $data = json_decode($r['response'], true); if (json_last_error() === JSON_ERROR_NONE) { $generated = $data['choices'][0]['message']['content'] ?? ''; } }
    } elseif ($provider === 'zai') {
        $url = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
        $headers = ['Authorization: Bearer ' . $api_key, 'Content-Type: application/json'];
        $body = ['model' => $model ?: 'glm-4-flash', 'messages' => [['role' => 'user', 'content' => $seo_prompt]], 'temperature' => 0.3, 'max_tokens' => 500];
        $r = http_request($url, $headers, $body, 'POST', 30);
        if ($r['http_code'] === 200 && !empty($r['response'])) { $data = json_decode($r['response'], true); if (json_last_error() === JSON_ERROR_NONE) { $generated = $data['choices'][0]['message']['content'] ?? ''; } }
    }

    if (empty($generated)) {
        $debug = !empty($r['response']) ? mb_substr($r['response'], 0, 300) : 'empty';
        send_error('Failed to generate SEO. Provider: ' . $provider . '. HTTP: ' . ($r['http_code'] ?? 'N/A') . '. Проверьте настройки в Настройки → AI. Response: ' . $debug, $log);
    }

    $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', trim($generated));
    $seo_data = json_decode($cleaned, true);
    if (!$seo_data) {
        send_success(['content' => $generated, 'provider' => $provider, 'type' => 'seo', 'parsed' => false], $log);
    }
    send_success(['content' => $seo_data, 'provider' => $provider, 'type' => 'seo', 'parsed' => true], $log);
}

// =====================================================================
// TRANSLATION — translate page content to another language
// =====================================================================
elseif ($action === 'translate') {
    $content = $_POST['content'] ?? '';
    $title = $_POST['title'] ?? '';
    $target_lang = $_POST['target_lang'] ?? '';
    $target_lang_name = $_POST['target_lang_name'] ?? $target_lang;

    if (empty($content) || empty($target_lang)) send_error('Content and target language are required.', $log);

    $cfg = $ai['text'];
    $provider = $cfg['provider'] ?? 'openrouter';
    $model = $cfg['model'] ?? '';
    $api_key = $cfg['api_key'] ?? '';

    $translate_prompt = "Переведи следующую HTML-страницу на язык: {$target_lang_name} (код: {$target_lang}).\n\n";
    $translate_prompt .= "СОХРАНИ всю HTML-разметку (теги, атрибуты, стили) без изменений. Переводи ТОЛЬКО текстовое содержимое.\n\n";
    $translate_prompt .= "Заголовок страницы для перевода:\n{$title}\n\n";
    $translate_prompt .= "HTML-контент для перевода:\n{$content}\n\n";
    $translate_prompt .= "Верни только переведённый HTML, без пояснений.";

    $log("Translation to {$target_lang}, provider: $provider");
    $generated = null;

    if ($provider === 'openrouter') {
        $url = 'https://openrouter.ai/api/v1/chat/completions';
        $headers = ['Authorization: Bearer ' . ($api_key ?: 'free'), 'Content-Type: application/json', 'HTTP-Referer: ' . ($_SERVER['HTTP_ORIGIN'] ?? 'http://localhost'), 'X-Title: AICMS'];
        $body = ['model' => $model ?: 'mistralai/mistral-7b-instruct:free', 'messages' => [['role' => 'user', 'content' => $translate_prompt]], 'temperature' => 0.3, 'max_tokens' => 4000];
        $r = http_request($url, $headers, $body, 'POST', 90);
        if ($r['http_code'] === 200 && !empty($r['response'])) {
            $data = json_decode($r['response'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $generated = $data['choices'][0]['message']['content'] ?? '';
            }
        } elseif ($r['http_code'] === 429) {
            send_error('Превышен лимит запросов OpenRouter (rate limit). Подождите 1 минуту и попробуйте снова.', $log);
        } elseif ($r['http_code'] === 402) {
            send_error('Недостаточно средств на OpenRouter. Используйте бесплатную модель (mistralai/mistral-7b-instruct:free).', $log);
        } elseif (empty($r['response'])) {
            send_error('Пустой ответ от OpenRouter. Модель может быть недоступна. Попробуйте другую модель в Настройки → AI.', $log);
        }
    } elseif ($provider === 'deepseek') {
        $url = 'https://api.deepseek.com/v1/chat/completions';
        $headers = ['Authorization: Bearer ' . $api_key, 'Content-Type: application/json'];
        $body = ['model' => $model ?: 'deepseek-chat', 'messages' => [['role' => 'user', 'content' => $translate_prompt]], 'temperature' => 0.3, 'max_tokens' => 4000];
        $r = http_request($url, $headers, $body, 'POST', 90);
        if ($r['http_code'] === 200 && !empty($r['response'])) {
            $data = json_decode($r['response'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $generated = $data['choices'][0]['message']['content'] ?? '';
            }
        }
    } elseif ($provider === 'groq') {
        $url = 'https://api.groq.com/openai/v1/chat/completions';
        $headers = ['Authorization: Bearer ' . $api_key, 'Content-Type: application/json'];
        $body = ['model' => $model ?: 'llama-3.1-8b-instant', 'messages' => [['role' => 'user', 'content' => $translate_prompt]], 'temperature' => 0.3, 'max_tokens' => 4000];
        $r = http_request($url, $headers, $body, 'POST', 90);
        if ($r['http_code'] === 200 && !empty($r['response'])) {
            $data = json_decode($r['response'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $generated = $data['choices'][0]['message']['content'] ?? '';
            }
        }
    } elseif ($provider === 'zai') {
        $url = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
        $headers = ['Authorization: Bearer ' . $api_key, 'Content-Type: application/json'];
        $body = ['model' => $model ?: 'glm-4-flash', 'messages' => [['role' => 'user', 'content' => $translate_prompt]], 'temperature' => 0.3, 'max_tokens' => 4000];
        $r = http_request($url, $headers, $body, 'POST', 90);
        if ($r['http_code'] === 200 && !empty($r['response'])) {
            $data = json_decode($r['response'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $generated = $data['choices'][0]['message']['content'] ?? '';
            }
        }
    }

    // Strip markdown code blocks if present
    if (!empty($generated)) {
        $generated = preg_replace('/^```(?:html)?\s*\n?/i', '', $generated);
        $generated = preg_replace('/\n?```\s*$/i', '', $generated);
        $generated = trim($generated);
    }

    if (empty($generated)) {
        $debug = !empty($r['response']) ? mb_substr($r['response'], 0, 300) : 'empty response';
        send_error('Translation failed. Provider: ' . $provider . '. Модель не вернула текст. Попробуйте другую модель в Настройки → AI. HTTP: ' . ($r['http_code'] ?? 'N/A') . '. Response: ' . $debug, $log);
    }

    $translated_title = $title;
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $generated, $m)) {
        $translated_title = strip_tags($m[1]);
    }

    send_success([
        'content' => $generated,
        'title' => $translated_title,
        'provider' => $provider,
        'type' => 'translate',
        'target_lang' => $target_lang,
    ], $log);
}

// =====================================================================
// IMAGE GENERATION
// =====================================================================
elseif ($action === 'image') {
    $prompt = $_POST['prompt'] ?? '';
    if (empty($prompt)) send_error('Image prompt cannot be empty.', $log);

    $cfg = $ai['image'];
    $provider = $cfg['provider'] ?? 'pollinations';
    $model = $cfg['model'] ?? '';
    $api_key = $cfg['api_key'] ?? '';
    $log("Image provider: $provider, model: $model, prompt: " . substr($prompt, 0, 150));

    try {
        $image_url = null;
        $image_local = null;

        if ($provider === 'pollinations') {
            // Pollinations.ai — free, no API key. GET the image URL.
            $model_name = $model ?: 'flux';
            $encoded_prompt = urlencode($prompt);
            $poll_url = "https://image.pollinations.ai/prompt/{$encoded_prompt}?width=768&height=768&model=" . urlencode($model_name) . '&nologo=true&seed=' . rand(1, 999999);
            $log("Pollinations URL: $poll_url");
            $img_result = http_request($poll_url, ['Accept: image/png, image/jpeg, image/*'], null, 'GET', 120);
            if ($img_result['http_code'] === 200 && !empty($img_result['response'])) {
                // Verify it's actually a binary image, not text/JSON error
                $first_bytes = substr($img_result['response'], 0, 20);
                $is_png = (ord($first_bytes[0] ?? '') === 0x89 && substr($first_bytes, 1, 3) === 'PNG');
                $is_jpeg = (ord($first_bytes[0] ?? '') === 0xFF && ord($first_bytes[1] ?? '') === 0xD8);
                $is_webp = (substr($first_bytes, 0, 4) === 'RIFF');
                if (!$is_png && !$is_jpeg && !$is_webp) {
                    // Not an image — probably error text
                    $text_preview = substr($img_result['response'], 0, 200);
                    send_error('Pollinations вернул текст вместо изображения: ' . $text_preview, $log);
                }
                $ext = $is_jpeg ? 'jpg' : ($is_webp ? 'webp' : 'png');
                $dir = __DIR__ . '/../uploads/ai/';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $filename = 'ai_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
                $ok = file_put_contents($dir . $filename, $img_result['response']);
                if ($ok !== false) {
                    $image_local = 'uploads/ai/' . $filename;
                } else {
                    send_error('Не удалось сохранить изображение. Проверьте права на папку uploads/ai/.', $log);
                }
            } else {
                $err_detail = $img_result['error'] ?: 'HTTP ' . $img_result['http_code'];
                send_error('Pollinations error: ' . $err_detail, $log);
            }
        }

        elseif ($provider === 'zai') {
            if (empty($api_key)) send_error('Z.ai API key is required for image generation.', $log);
            // Z.ai CogView — POST request returns an image URL
            $url = 'https://open.bigmodel.cn/api/paas/v4/images/generations';
            $headers = [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json',
            ];
            $body = [
                'model' => $model ?: 'cogview-3-plus',
                'prompt' => $prompt,
                'size' => '1024x1024',
            ];
            $r = http_request($url, $headers, $body, 'POST', 120);
            if ($r['http_code'] !== 200) {
                $err = json_decode($r['response'], true);
                send_error('Z.ai image error (' . $r['http_code'] . '): ' . ($err['error']['message'] ?? 'Unknown'), $log);
            }
            $data = json_decode($r['response'], true);
            $image_url = $data['data'][0]['url'] ?? null;
            if (!$image_url) send_error('Z.ai did not return an image URL.', $log);
            $image_local = save_remote_media($image_url, 'png');
            if (!$image_local) send_error('Failed to download Z.ai image.', $log);
        }

        elseif ($provider === 'stability') {
            if (empty($api_key)) send_error('Stability API key is required.', $log);
            $url = 'https://api.stability.ai/v1/generation/' . ($model ?: 'stable-diffusion-xl-1024-v1-0') . '/text-to-image';
            $headers = [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json',
                'Accept: application/json',
            ];
            $body = [
                'text_prompts' => [['text' => $prompt]],
                'cfg_scale' => 7,
                'height' => 1024,
                'width' => 1024,
                'samples' => 1,
                'steps' => 30,
            ];
            $r = http_request($url, $headers, $body, 'POST', 120);
            if ($r['http_code'] !== 200) {
                send_error('Stability error (' . $r['http_code'] . '): ' . $r['response'], $log);
            }
            $data = json_decode($r['response'], true);
            $b64 = $data['artifacts'][0]['base64'] ?? '';
            if (empty($b64)) send_error('Stability did not return image data.', $log);
            // Save base64 to file
            $dir = __DIR__ . '/../uploads/ai/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $filename = 'ai_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.png';
            file_put_contents($dir . $filename, base64_decode($b64));
            $image_local = 'uploads/ai/' . $filename;
        }

        elseif ($provider === 'openrouter') {
            if (empty($api_key)) send_error('OpenRouter API key is required for image generation.', $log);
            $url = 'https://openrouter.ai/api/v1/images/generations';
            $headers = [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json',
            ];
            $body = [
                'model' => $model ?: 'openai/dall-e-3',
                'prompt' => $prompt,
                'n' => 1,
                'size' => '1024x1024',
            ];
            $r = http_request($url, $headers, $body, 'POST', 120);
            if ($r['http_code'] !== 200) {
                $err = json_decode($r['response'], true);
                send_error('OpenRouter image error (' . $r['http_code'] . '): ' . ($err['error']['message'] ?? 'Unknown'), $log);
            }
            $data = json_decode($r['response'], true);
            $image_url = $data['data'][0]['url'] ?? null;
            if (!$image_url) send_error('OpenRouter did not return an image URL.', $log);
            $image_local = save_remote_media($image_url, 'png');
            if (!$image_local) send_error('Failed to download OpenRouter image.', $log);
        }

        elseif ($provider === 'openai_img') {
            if (empty($api_key)) send_error('OpenAI API key required for DALL-E. Get it at platform.openai.com/api-keys', $log);
            $url = 'https://api.openai.com/v1/images/generations';
            $headers = ['Authorization: Bearer ' . $api_key, 'Content-Type: application/json'];
            $body = ['model' => $model ?: 'dall-e-3', 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024'];
            $r = http_request($url, $headers, $body, 'POST', 120);
            if ($r['http_code'] !== 200) { $err = json_decode($r['response'], true); send_error('OpenAI DALL-E error (' . $r['http_code'] . '): ' . ($err['error']['message'] ?? 'Unknown'), $log); }
            $data = json_decode($r['response'], true);
            $image_url = $data['data'][0]['url'] ?? '';
            if (!$image_url) send_error('DALL-E did not return image URL.', $log);
            $image_local = save_remote_media($image_url, 'png');
            if (!$image_local) send_error('Failed to download DALL-E image.', $log);
        }

        elseif ($provider === 'together_img') {
            if (empty($api_key)) send_error('Together AI API key required. Get it at api.together.xyz', $log);
            $url = 'https://api.together.xyz/v1/images/generations';
            $headers = ['Authorization: Bearer ' . $api_key, 'Content-Type: application/json'];
            $body = ['model' => $model ?: 'stabilityai/stable-diffusion-xl-1024-v1-0', 'prompt' => $prompt, 'n' => 1, 'height' => 768, 'width' => 768];
            $r = http_request($url, $headers, $body, 'POST', 120);
            if ($r['http_code'] !== 200) { $err = json_decode($r['response'], true); send_error('Together AI image error (' . $r['http_code'] . '): ' . ($err['error']['message'] ?? 'Unknown'), $log); }
            $data = json_decode($r['response'], true);
            // Together returns base64 or URL
            if (!empty($data['data'][0]['url'])) {
                $image_local = save_remote_media($data['data'][0]['url'], 'png');
            } elseif (!empty($data['data'][0]['b64_json'])) {
                $dir = __DIR__ . '/../uploads/ai/';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $filename = 'ai_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.png';
                file_put_contents($dir . $filename, base64_decode($data['data'][0]['b64_json']));
                $image_local = 'uploads/ai/' . $filename;
            }
            if (!$image_local) send_error('Together AI did not return image data.', $log);
        }

        elseif ($provider === 'google_img') {
            if (empty($api_key)) send_error('Google AI API key required. Get it at aistudio.google.com/app/apikey', $log);
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model ?: 'imagen-3.0-generate-002') . ':predict?key=' . $api_key;
            $headers = ['Content-Type: application/json'];
            $body = ['instances' => [['prompt' => $prompt]], 'parameters' => ['sampleCount' => 1]];
            $r = http_request($url, $headers, $body, 'POST', 120);
            if ($r['http_code'] !== 200) { $err = json_decode($r['response'], true); send_error('Google Imagen error (' . $r['http_code'] . '): ' . ($err['error']['message'] ?? 'Unknown'), $log); }
            $data = json_decode($r['response'], true);
            $b64 = $data['predictions'][0]['bytesBase64Encoded'] ?? '';
            if (empty($b64)) send_error('Google Imagen did not return image data.', $log);
            $dir = __DIR__ . '/../uploads/ai/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $filename = 'ai_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.png';
            file_put_contents($dir . $filename, base64_decode($b64));
            $image_local = 'uploads/ai/' . $filename;
        }

        else {
            send_error('Unknown image provider: ' . $provider . '. Available: pollinations, zai, stability, openrouter, openai_img, together_img, google_img', $log);
        }

        send_success(['url' => $image_local, 'provider' => $provider, 'type' => 'image'], $log);

    } catch (Exception $e) {
        send_error('Exception: ' . $e->getMessage(), $log);
    }
}

// =====================================================================
// VIDEO GENERATION
// =====================================================================
elseif ($action === 'video') {
    $prompt = $_POST['prompt'] ?? '';
    if (empty($prompt)) send_error('Video prompt cannot be empty.', $log);

    $cfg = $ai['video'];
    $provider = $cfg['provider'] ?? 'disabled';
    $model = $cfg['model'] ?? '';
    $api_key = $cfg['api_key'] ?? '';
    $log("Video provider: $provider, model: $model, prompt: " . substr($prompt, 0, 150));

    if ($provider === 'disabled' || empty($provider)) {
        send_error('Video generation is disabled. Configure a provider in AI Settings.', $log);
    }

    try {
        $video_local = null;

        if ($provider === 'zai') {
            if (empty($api_key)) send_error('Z.ai API key is required for video generation.', $log);
            // Z.ai CogVideoX — async: submit task, then poll for completion.
            $submit_url = 'https://open.bigmodel.cn/api/paas/v4/videos/generations';
            $headers = [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json',
            ];
            $body = [
                'model' => $model ?: 'cogvideox-2',
                'prompt' => $prompt,
            ];
            $r = http_request($submit_url, $headers, $body, 'POST', 60);
            if ($r['http_code'] !== 200) {
                $err = json_decode($r['response'], true);
                send_error('Z.ai video submit error (' . $r['http_code'] . '): ' . ($err['error']['message'] ?? 'Unknown'), $log);
            }
            $data = json_decode($r['response'], true);
            $task_id = $data['id'] ?? ($data['task_id'] ?? '');
            if (empty($task_id)) send_error('Z.ai did not return a task ID.', $log);

            // Poll for completion (up to ~5 minutes)
            $max_polls = 30;
            $poll_interval = 10;
            $poll_url = 'https://open.bigmodel.cn/api/paas/v4/async-result/' . $task_id;
            for ($i = 0; $i < $max_polls; $i++) {
                sleep($poll_interval);
                $pr = http_request($poll_url, $headers, null, 'GET', 30);
                $pdata = json_decode($pr['response'], true);
                $task_status = $pdata['task_status'] ?? '';
                $log("Z.ai video poll #$i: status=$task_status");
                if ($task_status === 'SUCCESS') {
                    $video_url = $pdata['video_result'][0]['url'] ?? ($pdata['output']['video_url'] ?? '');
                    if (empty($video_url)) send_error('Z.ai video task succeeded but no URL found.', $log);
                    $video_local = save_remote_media($video_url, 'mp4');
                    if (!$video_local) send_error('Failed to download Z.ai video.', $log);
                    break;
                }
                if ($task_status === 'FAIL') {
                    send_error('Z.ai video generation failed.', $log);
                }
            }
            if (!$video_local) send_error('Z.ai video generation timed out.', $log);
        }

        elseif ($provider === 'stable') {
            if (empty($api_key)) send_error('Stability API key is required.', $log);
            // Stability Video API — async generation
            $url = 'https://api.stability.ai/v2beta/image-to-video';
            $headers = [
                'Authorization: Bearer ' . $api_key,
            ];
            $post_fields = [
                'seed' => 0,
                'cfg_scale' => 1.8,
                'motion_bucket_id' => 127,
                'text' => $prompt,
            ];
            // Use multipart form for Stability
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $api_key, 'Accept: application/json']);
            $multipart = [];
            foreach ($post_fields as $k => $v) $multipart[] = "$k=$v";
            curl_setopt($ch, CURLOPT_POSTFIELDS, implode('&', $multipart));
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($http_code !== 200) send_error('Stability video error (' . $http_code . '): ' . $response, $log);
            $data = json_decode($response, true);
            $task_id = $data['id'] ?? '';
            if (empty($task_id)) send_error('Stability did not return a task ID.', $log);
            // Poll
            for ($i = 0; $i < 60; $i++) {
                sleep(10);
                $pr = http_request('https://api.stability.ai/v2beta/image-to-video/result/' . $task_id, ['Authorization: Bearer ' . $api_key, 'Accept: application/json'], null, 'GET', 30);
                $pdata = json_decode($pr['response'], true);
                if (($pdata['status'] ?? '') === 'complete') {
                    $video_url = $pdata['video'] ?? '';
                    if (empty($video_url)) send_error('Stability video succeeded but no URL.', $log);
                    $video_local = save_remote_media($video_url, 'mp4');
                    break;
                }
                if (($pdata['status'] ?? '') === 'error') send_error('Stability video failed.', $log);
            }
            if (!$video_local) send_error('Stability video timed out.', $log);
        }

        elseif ($provider === 'google_video') {
            if (empty($api_key)) send_error('Google AI API key required. Get it at aistudio.google.com/app/apikey', $log);
            // Google Veo via Vertex AI / Generative Language API
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model ?: 'veo-2.0-generate-001') . ':predictLongRunning?key=' . $api_key;
            $headers = ['Content-Type: application/json'];
            $body = ['instances' => [['prompt' => $prompt]], 'parameters' => ['sampleCount' => 1]];
            $r = http_request($url, $headers, $body, 'POST', 30);
            if ($r['http_code'] !== 200) { $err = json_decode($r['response'], true); send_error('Google Veo error (' . $r['http_code'] . '): ' . ($err['error']['message'] ?? 'Unknown'), $log); }
            $data = json_decode($r['response'], true);
            $operation = $data['name'] ?? '';
            if (empty($operation)) send_error('Google Veo did not return operation name.', $log);
            // Poll for completion (up to 5 min)
            $video_url = null;
            for ($i = 0; $i < 60; $i++) {
                sleep(5);
                $poll_url = 'https://generativelanguage.googleapis.com/v1beta/' . $operation . '?key=' . $api_key;
                $pr = http_request($poll_url, [], null, 'GET', 30);
                if ($pr['http_code'] !== 200) continue;
                $pdata = json_decode($pr['response'], true);
                if (($pdata['done'] ?? false) === true) {
                    $video_url = $pdata['response']['generatedSamples'][0]['video']['uri'] ?? '';
                    if (!empty($video_url) && strpos($video_url, 'http') !== 0) {
                        $video_url = 'https://generativelanguage.googleapis.com/v1beta/' . $video_url . '?key=' . $api_key;
                    }
                    break;
                }
                if (($pdata['error']['code'] ?? 0) !== 0) send_error('Google Veo failed: ' . ($pdata['error']['message'] ?? ''), $log);
            }
            if (empty($video_url)) send_error('Google Veo video generation timed out.', $log);
            $video_local = save_remote_media($video_url, 'mp4');
            if (!$video_local) send_error('Failed to download Google Veo video.', $log);
        }

        else {
            send_error('Unknown video provider: ' . $provider . '. Available: zai, stable, google_video', $log);
        }

        send_success(['url' => $video_local, 'provider' => $provider, 'type' => 'video'], $log);

    } catch (Exception $e) {
        send_error('Exception: ' . $e->getMessage(), $log);
    }
}

else {
    send_error('Unknown action: ' . $action, $log);
}
