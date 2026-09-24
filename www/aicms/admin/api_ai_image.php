<?php
/**
 * AI Image Generation API
 * ========================
 * Generates an image from a text prompt using z-ai-web-dev-sdk.
 * Saves the result to uploads/ai_generated/ and returns the public URL.
 *
 * Used by the Lumina editor's AI image generator button.
 *
 * POST params:
 *   - prompt: text description of the image to generate
 *   - size: '1024x1024' | '768x768' | '512x512' (default 1024x1024)
 *   - csrf_token: CSRF token
 *
 * Response: { status: 'success', url: 'uploads/ai_generated/xxx.png' }
 *       or: { status: 'error', message: '...' }
 */
require_once __DIR__ . '/core/functions.php';
require_auth();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['status' => 'error', 'message' => 'Security error.']);
    exit;
}

$prompt = trim($_POST['prompt'] ?? '');
$size = $_POST['size'] ?? '1024x1024';

// Validate inputs
if (mb_strlen($prompt) < 5) {
    echo json_encode(['status' => 'error', 'message' => 'Prompt too short. Describe the image in 5+ characters.']);
    exit;
}
if (mb_strlen($prompt) > 2000) {
    echo json_encode(['status' => 'error', 'message' => 'Prompt too long. Maximum 2000 characters.']);
    exit;
}
$allowed_sizes = ['1024x1024', '768x768', '512x512', '1024x576', '576x1024'];
if (!in_array($size, $allowed_sizes, true)) $size = '1024x1024';

// Rate limit: 10 image generations per hour per admin
if (!api_rate_limit('ai_image_gen', 10, 3600)) {
    http_response_code(429);
    header('Retry-After: 3600');
    echo json_encode(['status' => 'error', 'message' => 'Rate limit: max 10 AI image generations per hour.']);
    exit;
}

// Load AI config — secrets overlaid from data/.env.php
$config = aicms_apply_env(read_json(__DIR__ . '/../data/config.json'));
$provider = $config['ai']['image']['provider'] ?? 'zai';
$api_key = $config['ai']['image']['api_key'] ?? ($config['deepseek_api_key'] ?? '');

if (empty($api_key) && $provider === 'zai') {
    // Try ZAI SDK directly (env var)
}

// Prepare target directory
$target_dir = __DIR__ . '/../uploads/ai_generated';
if (!is_dir($target_dir)) @mkdir($target_dir, 0755, true);

$filename = 'ai_' . date('Ymd_His') . '_' . substr(md5($prompt . mt_rand()), 0, 8) . '.png';
$target_path = $target_dir . '/' . $filename;

// Try multiple providers in order of preference
$image_generated = false;
$error_msg = '';

// ===== 1. Try ZAI SDK via Node.js subprocess =====
$node_script = <<<'NODE'
const ZAI = require('z-ai-web-dev-sdk').default;
(async () => {
    const prompt = process.argv[2];
    const size = process.argv[3] || '1024x1024';
    const outputPath = process.argv[4];
    try {
        const zai = await ZAI.create();
        const response = await zai.images.generations.create({ prompt, size });
        const base64 = response.data[0].base64;
        require('fs').writeFileSync(outputPath, Buffer.from(base64, 'base64'));
        process.exit(0);
    } catch (e) {
        console.error(e.message);
        process.exit(1);
    }
})();
NODE;

// Save script temporarily
$script_path = sys_get_temp_dir() . '/aicms_ai_img_' . uniqid() . '.js';
file_put_contents($script_path, $node_script);

$node_bin = trim(shell_exec('which node 2>/dev/null') ?? '');
if ($node_bin) {
    $cmd = escapeshellarg($node_bin) . ' ' . escapeshellarg($script_path) . ' '
         . escapeshellarg($prompt) . ' ' . escapeshellarg($size) . ' ' . escapeshellarg($target_path);
    $output = shell_exec($cmd . ' 2>&1');
    $exit_code = (int)shell_exec("echo $?");
    if (file_exists($target_path) && filesize($target_path) > 1000) {
        $image_generated = true;
    } else {
        $error_msg = 'ZAI SDK failed: ' . trim($output);
    }
}
@unlink($script_path);

if ($image_generated) {
    // Verify MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $target_path);
    finfo_close($finfo);
    if (strpos($mime, 'image/') !== 0) {
        @unlink($target_path);
        echo json_encode(['status' => 'error', 'message' => 'Generated file is not an image.']);
        exit;
    }

    // Log success
    $log_file = __DIR__ . '/../logs/ai_image.log';
    @file_put_contents($log_file, date('Y-m-d H:i:s') . " OK prompt=" . substr($prompt, 0, 100) . " file=" . $filename . "\n", FILE_APPEND);

    echo json_encode([
        'status' => 'success',
        'url' => 'uploads/ai_generated/' . $filename,
        'filename' => $filename,
        'prompt' => $prompt,
    ]);
    exit;
}

// ===== 2. Fallback: external provider via cURL (Stability AI etc.) =====
if (!empty($api_key) && $provider !== 'zai') {
    // Placeholder for external image API integration
    // (e.g. OpenAI DALL-E, Stability AI, Replicate)
    $error_msg .= '. External provider integration not yet configured.';
}

echo json_encode([
    'status' => 'error',
    'message' => 'Image generation failed. ' . $error_msg . ' Check that Node.js + z-ai-web-dev-sdk are installed.',
]);
