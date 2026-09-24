<?php
// display_errors intentionally off — errors are logged to server log, not exposed to clients
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/rate_limit.php';
require_auth();

header('Content-Type: application/json; charset=utf-8');

// Rate limiting: 120 requests per minute per user
if (!api_rate_limit('api_media', 120, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    $status = api_rate_limit_status('api_media', 120, 60);
    header('X-RateLimit-Limit: ' . $status['limit']);
    header('X-RateLimit-Remaining: 0');
    header('X-RateLimit-Reset: ' . $status['reset']);
    echo json_encode(['status' => 'error', 'message' => 'Too Many Requests. Try again in a minute.']);
    exit;
}
$_rl_status = api_rate_limit_status('api_media', 120, 60);
header('X-RateLimit-Limit: ' . $_rl_status['limit']);
header('X-RateLimit-Remaining: ' . $_rl_status['remaining']);
header('X-RateLimit-Reset: ' . $_rl_status['reset']);

function send_json($data) {
    $data['new_csrf_token'] = generate_csrf_token();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function format_bytes_api($bytes) {
    if ($bytes === false || $bytes === null) return 'N/A';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, 2) . ' ' . $units[$pow];
}

// Shared MIME whitelist + dangerous extension blocklist — used by BOTH upload paths
// (regular upload and upload_from_url) so the lists stay in sync.
$GLOBALS['_media_allowed_mimes'] = [
    'image/jpeg','image/png','image/gif','image/webp','image/svg+xml','image/bmp','image/x-icon',
    'application/pdf','text/plain','text/csv','application/zip','application/x-rar-compressed',
    'video/mp4','video/webm','audio/mpeg','audio/wav','audio/ogg',
    'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];
$GLOBALS['_media_dangerous_ext'] = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'phps', 'pht', 'asp', 'aspx', 'jsp', 'exe', 'sh', 'bat', 'cmd', 'com', 'so', 'dll'];


// CSRF check
$submitted_token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
if (!verify_csrf_token($submitted_token)) {
    http_response_code(403);
    send_json(['status' => 'error', 'message' => 'CSRF error. Reload the page.']);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// === Path resolution ===
// All operations work within /uploads/ — no escaping outside.
$uploads_root = realpath(__DIR__ . '/../uploads');
if ($uploads_root === false) {
    mkdir(__DIR__ . '/../uploads', 0755, true);
    $uploads_root = realpath(__DIR__ . '/../uploads');
}

/**
 * Resolve a relative path within /uploads/ to an absolute path.
 * Returns ['absolute' => ..., 'relative' => ..., 'url' => ...] or null if invalid.
 * Path traversal (..) is blocked.
 */
function media_resolve_path($relative) {
    global $uploads_root;
    $relative = trim(str_replace('\\', '/', $relative ?? ''), '/');
    $relative = trim($relative, '/');
    if (strpos($relative, '..') !== false || strpos($relative, "\0") !== false) return null;
    // Block hidden files/dirs
    $parts = explode('/', $relative);
    foreach ($parts as $p) {
        if ($p !== '' && $p[0] === '.') return null;
    }
    $absolute = $uploads_root . '/' . $relative;
    if (file_exists($absolute)) {
        $real = realpath($absolute);
        if ($real === false || strpos($real, $uploads_root) !== 0) return null;
        $absolute = $real;
    }
    return [
        'absolute' => $absolute,
        'relative' => $relative,
        'url' => '/uploads/' . $relative,
    ];
}

/**
 * Get file type from extension.
 */
function media_file_type($ext) {
    $ext = strtolower($ext);
    $img_exts = ['jpg','jpeg','png','gif','webp','svg','bmp','ico'];
    $doc_exts = ['pdf','doc','docx','txt','rtf'];
    $arch_exts = ['zip','rar','7z','gz','tar'];
    $vid_exts  = ['mp4','webm','avi','mov','mkv'];
    $aud_exts  = ['mp3','wav','ogg','flac'];
    if (in_array($ext, $img_exts)) return 'image';
    if (in_array($ext, $doc_exts)) return 'document';
    if (in_array($ext, $arch_exts)) return 'archive';
    if (in_array($ext, $vid_exts)) return 'video';
    if (in_array($ext, $aud_exts)) return 'audio';
    return 'other';
}

/**
 * Recursively scan a directory and return all files (with metadata).
 */
function media_scan_dir($dir_absolute, $dir_relative) {
    $result = ['files' => [], 'folders' => []];
    if (!is_dir($dir_absolute)) return $result;
    $entries = @scandir($dir_absolute);
    if ($entries === false) return $result;
    $entries = array_diff($entries, ['.', '..', '.trash']);
    foreach ($entries as $name) {
        $full = $dir_absolute . '/' . $name;
        $rel = $dir_relative ? $dir_relative . '/' . $name : $name;
        if (is_dir($full)) {
            $sub_count = count(array_filter(glob($full . '/*'), 'is_file'));
            $result['folders'][] = [
                'name' => $name,
                'path' => $rel,
                'url' => '/uploads/' . $rel,
                'file_count' => $sub_count,
                'modified' => @filemtime($full),
                'modified_formatted' => @filemtime($full) ? date('d.m.Y H:i', filemtime($full)) : '',
            ];
        } else {
            $size = @filesize($full);
            $mtime = @filemtime($full);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $type = media_file_type($ext);
            $width = null; $height = null;
            if ($type === 'image' && $ext !== 'svg') {
                $info = @getimagesize($full);
                if ($info) { $width = $info[0]; $height = $info[1]; }
            }
            $result['files'][] = [
                'name' => $name,
                'path' => $rel,
                'url' => '/uploads/' . $rel,
                'size' => $size,
                'size_formatted' => format_bytes_api($size),
                'modified' => $mtime,
                'modified_formatted' => $mtime ? date('d.m.Y H:i', $mtime) : '',
                'ext' => $ext,
                'type' => $type,
                'width' => $width,
                'height' => $height,
                'folder' => $dir_relative,
            ];
        }
    }
    // Sort folders alphabetically
    usort($result['folders'], function($a, $b) { return strcasecmp($a['name'], $b['name']); });
    // Sort files: newest first
    usort($result['files'], function($a, $b) { return $b['modified'] <=> $a['modified']; });
    return $result;
}

/**
 * Get breadcrumb path segments for a given relative path.
 */
function media_breadcrumbs($relative) {
    $relative = trim($relative, '/');
    if ($relative === '') return [['name' => 'uploads', 'path' => '']];
    $parts = explode('/', $relative);
    $crumbs = [['name' => 'uploads', 'path' => '']];
    $acc = '';
    foreach ($parts as $p) {
        $acc = $acc ? $acc . '/' . $p : $p;
        $crumbs[] = ['name' => $p, 'path' => $acc];
    }
    return $crumbs;
}

// === LIST (with folder support) ===
if ($action === 'list') {
    $folder = $_GET['folder'] ?? '';
    $resolved = media_resolve_path($folder);
    if (!$resolved || !is_dir($resolved['absolute'])) {
        send_json(['status' => 'error', 'message' => 'Папка не найдена']);
    }
    $scan = media_scan_dir($resolved['absolute'], $resolved['relative']);
    // Stats
    $total_size = array_sum(array_column($scan['files'], 'size'));
    $by_type = [];
    foreach ($scan['files'] as $f) {
        $by_type[$f['type']] = ($by_type[$f['type']] ?? 0) + 1;
    }
    send_json([
        'status' => 'success',
        'folder' => $resolved['relative'],
        'breadcrumbs' => media_breadcrumbs($resolved['relative']),
        'folders' => $scan['folders'],
        'files' => $scan['files'],
        'stats' => [
            'total_files' => count($scan['files']),
            'total_folders' => count($scan['folders']),
            'total_size' => $total_size,
            'total_size_formatted' => format_bytes_api($total_size),
            'by_type' => $by_type,
        ],
    ]);
}

// === UPLOAD (to a specific folder) ===
if ($action === 'upload') {
    $folder = $_POST['folder'] ?? '';
    $resolved = media_resolve_path($folder);
    if (!$resolved) send_json(['status' => 'error', 'message' => 'Недопустимый путь']);
    if (!is_dir($resolved['absolute'])) {
        @mkdir($resolved['absolute'], 0755, true);
    }
    if (!isset($_FILES['file'])) send_json(['status' => 'error', 'message' => 'Файл не получен']);
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) send_json(['status' => 'error', 'message' => 'Ошибка загрузки: ' . $file['error']]);
    // Verify MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    // Strict whitelist: the detected MIME must be in the allowed list
    if (!in_array($mime, $GLOBALS['_media_allowed_mimes'])) {
        send_json(['status' => 'error', 'message' => 'Недопустимый тип файла: ' . $mime]);
    }
    // Defense in depth: block dangerous extensions regardless of detected MIME.
    // The strpos('.php') check is intentional — it also catches polyglot names like
    // shell.php.jpg where pathinfo() would only see the harmless last extension.
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (in_array($ext, $GLOBALS['_media_dangerous_ext']) || strpos(strtolower($file['name']), '.php') !== false) {
        send_json(['status' => 'error', 'message' => 'Расширение файла запрещено']);
    }
    $name = $file['name'];
    // Sanitize filename
    $name = preg_replace('/[^\w\.\-]/', '_', $name);
    $name = trim($name, '._-');
    if (empty($name)) $name = 'file_' . time();
    $target = $resolved['absolute'] . '/' . $name;
    // If exists — append _N
    if (file_exists($target)) {
        $pathinfo = pathinfo($name);
        $i = 1;
        while (file_exists($resolved['absolute'] . '/' . $pathinfo['filename'] . '_' . $i . '.' . $pathinfo['extension'])) {
            $i++;
        }
        $name = $pathinfo['filename'] . '_' . $i . '.' . $pathinfo['extension'];
        $target = $resolved['absolute'] . '/' . $name;
    }
    if (move_uploaded_file($file['tmp_name'], $target)) {
        send_json([
            'status' => 'success',
            'message' => 'Файл загружен',
            'file' => [
                'name' => $name,
                'path' => $resolved['relative'] ? $resolved['relative'] . '/' . $name : $name,
                'url' => '/uploads/' . ($resolved['relative'] ? $resolved['relative'] . '/' . $name : $name),
                'size' => filesize($target),
                'size_formatted' => format_bytes_api(filesize($target)),
            ],
        ]);
    }
    send_json(['status' => 'error', 'message' => 'Не удалось переместить файл']);
}

// === CREATE FOLDER ===
if ($action === 'create_folder') {
    $folder = $_POST['folder'] ?? '';
    $name = trim($_POST['name'] ?? '');
    if (empty($name)) send_json(['status' => 'error', 'message' => 'Имя не указано']);
    // Sanitize name
    $name = preg_replace('/[^\w\.\-]/', '_', $name);
    $name = trim($name, '._-');
    if (empty($name) || strpos($name, '.') === 0) send_json(['status' => 'error', 'message' => 'Недопустимое имя']);
    $resolved = media_resolve_path($folder);
    if (!$resolved) send_json(['status' => 'error', 'message' => 'Недопустимый путь']);
    if (!is_dir($resolved['absolute'])) send_json(['status' => 'error', 'message' => 'Родительская папка не найдена']);
    $new_path = $resolved['absolute'] . '/' . $name;
    if (is_dir($new_path)) send_json(['status' => 'error', 'message' => 'Папка уже существует']);
    if (mkdir($new_path, 0755, true)) {
        send_json(['status' => 'success', 'message' => 'Папка создана', 'name' => $name]);
    }
    send_json(['status' => 'error', 'message' => 'Не удалось создать папку']);
}

// === DELETE FOLDER ===
if ($action === 'delete_folder') {
    $folder = $_POST['folder'] ?? '';
    $resolved = media_resolve_path($folder);
    if (!$resolved || !is_dir($resolved['absolute'])) send_json(['status' => 'error', 'message' => 'Папка не найдена']);
    // Don't allow deleting the root uploads/ folder
    if ($resolved['relative'] === '') send_json(['status' => 'error', 'message' => 'Нельзя удалить корневую папку']);
    // Move to .trash instead of permanent delete
    $trash_dir = $uploads_root . '/.trash';
    if (!is_dir($trash_dir)) @mkdir($trash_dir, 0755, true);
    $trash_name = basename($resolved['absolute']) . '_' . date('Ymd_His');
    if (rename($resolved['absolute'], $trash_dir . '/' . $trash_name)) {
        send_json(['status' => 'success', 'message' => 'Папка перемещена в корзину']);
    }
    send_json(['status' => 'error', 'message' => 'Не удалось удалить']);
}

// === RENAME FOLDER ===
if ($action === 'rename_folder') {
    $folder = $_POST['folder'] ?? '';
    $new_name = trim($_POST['new_name'] ?? '');
    if (empty($new_name)) send_json(['status' => 'error', 'message' => 'Имя не указано']);
    $new_name = preg_replace('/[^\w\.\-]/', '_', $new_name);
    $new_name = trim($new_name, '._-');
    if (empty($new_name) || strpos($new_name, '.') === 0) send_json(['status' => 'error', 'message' => 'Недопустимое имя']);
    $resolved = media_resolve_path($folder);
    if (!$resolved || !is_dir($resolved['absolute'])) send_json(['status' => 'error', 'message' => 'Папка не найдена']);
    if ($resolved['relative'] === '') send_json(['status' => 'error', 'message' => 'Нельзя переименовать корневую папку']);
    $parent = dirname($resolved['absolute']);
    $new_path = $parent . '/' . $new_name;
    if (file_exists($new_path)) send_json(['status' => 'error', 'message' => 'Уже существует']);
    if (rename($resolved['absolute'], $new_path)) {
        send_json(['status' => 'success', 'message' => 'Папка переименована', 'new_name' => $new_name]);
    }
    send_json(['status' => 'error', 'message' => 'Не удалось переименовать']);
}

// === MOVE FILES (to another folder) ===
if ($action === 'move_files') {
    $from_folder = $_POST['from_folder'] ?? '';
    $to_folder = $_POST['to_folder'] ?? '';
    $names = $_POST['names'] ?? [];
    if (!is_array($names) || empty($names)) send_json(['status' => 'error', 'message' => 'Файлы не выбраны']);
    $from = media_resolve_path($from_folder);
    $to = media_resolve_path($to_folder);
    if (!$from || !$to) send_json(['status' => 'error', 'message' => 'Недопустимый путь']);
    if (!is_dir($to['absolute'])) @mkdir($to['absolute'], 0755, true);
    $moved = 0; $errors = [];
    foreach ($names as $name) {
        $name = basename($name);
        $src = $from['absolute'] . '/' . $name;
        $dst = $to['absolute'] . '/' . $name;
        if (!file_exists($src)) { $errors[] = "$name: не найден"; continue; }
        if (file_exists($dst)) {
            // Append _N
            $pi = pathinfo($name);
            $i = 1;
            while (file_exists($to['absolute'] . '/' . $pi['filename'] . '_' . $i . '.' . $pi['extension'])) $i++;
            $dst = $to['absolute'] . '/' . $pi['filename'] . '_' . $i . '.' . $pi['extension'];
        }
        if (rename($src, $dst)) $moved++;
        else $errors[] = "$name: ошибка";
    }
    send_json([
        'status' => $moved > 0 ? 'success' : 'error',
        'message' => "Перемещено: $moved" . ($errors ? '. Ошибки: ' . implode('; ', $errors) : ''),
        'moved' => $moved,
        'errors' => $errors,
    ]);
}

// === COPY FILES (to another folder) ===
if ($action === 'copy_files') {
    $from_folder = $_POST['from_folder'] ?? '';
    $to_folder = $_POST['to_folder'] ?? '';
    $names = $_POST['names'] ?? [];
    if (!is_array($names) || empty($names)) send_json(['status' => 'error', 'message' => 'Файлы не выбраны']);
    $from = media_resolve_path($from_folder);
    $to = media_resolve_path($to_folder);
    if (!$from || !$to) send_json(['status' => 'error', 'message' => 'Недопустимый путь']);
    if (!is_dir($to['absolute'])) @mkdir($to['absolute'], 0755, true);
    $copied = 0; $errors = [];
    foreach ($names as $name) {
        $name = basename($name);
        $src = $from['absolute'] . '/' . $name;
        $dst = $to['absolute'] . '/' . $name;
        if (!file_exists($src)) { $errors[] = "$name: не найден"; continue; }
        if (file_exists($dst)) {
            $pi = pathinfo($name);
            $i = 1;
            while (file_exists($to['absolute'] . '/' . $pi['filename'] . '_' . $i . '.' . $pi['extension'])) $i++;
            $dst = $to['absolute'] . '/' . $pi['filename'] . '_' . $i . '.' . $pi['extension'];
        }
        if (copy($src, $dst)) $copied++;
        else $errors[] = "$name: ошибка";
    }
    send_json([
        'status' => $copied > 0 ? 'success' : 'error',
        'message' => "Скопировано: $copied" . ($errors ? '. Ошибки: ' . implode('; ', $errors) : ''),
        'copied' => $copied,
        'errors' => $errors,
    ]);
}

// === DELETE FILE ===
if ($action === 'delete') {
    $path = $_POST['path'] ?? '';
    $resolved = media_resolve_path($path);
    if (!$resolved || !file_exists($resolved['absolute'])) send_json(['status' => 'error', 'message' => 'Файл не найден']);
    if (is_dir($resolved['absolute'])) send_json(['status' => 'error', 'message' => 'Используйте delete_folder для папок']);
    // Move to .trash
    $trash_dir = $uploads_root . '/.trash';
    if (!is_dir($trash_dir)) @mkdir($trash_dir, 0755, true);
    $name = basename($resolved['absolute']);
    $trash_name = $name . '_' . date('Ymd_His');
    if (rename($resolved['absolute'], $trash_dir . '/' . $trash_name)) {
        send_json(['status' => 'success', 'message' => 'Файл перемещён в корзину']);
    }
    send_json(['status' => 'error', 'message' => 'Не удалось удалить']);
}

// === UPLOAD FROM URL (download remote image/file) ===
if ($action === 'upload_from_url') {
    $url = trim($_POST['url'] ?? '');
    $folder = $_POST['folder'] ?? '';
    if (empty($url)) send_json(['status' => 'error', 'message' => 'URL не указан']);
    if (!filter_var($url, FILTER_VALIDATE_URL)) send_json(['status' => 'error', 'message' => 'Неверный URL']);
    // SSRF protection: block private IPs
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) send_json(['status' => 'error', 'message' => 'Неверный host']);
    // Block localhost, private ranges
    if (preg_match('/^(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|169\.254\.|0\.)/', $host)) {
        send_json(['status' => 'error', 'message' => 'Загрузка с локальных адресов запрещена']);
    }
    $resolved = media_resolve_path($folder);
    if (!$resolved || !is_dir($resolved['absolute'])) send_json(['status' => 'error', 'message' => 'Папка не найдена']);
    // Download with cURL
    if (!function_exists('curl_init')) send_json(['status' => 'error', 'message' => 'cURL не установлен']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'AICMS-Media/1.0',
    ]);
    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($data === false || $http_code !== 200) {
        send_json(['status' => 'error', 'message' => 'Не удалось скачать: ' . ($error ?: "HTTP $http_code")]);
    }
    // Determine filename from URL
    $url_path = parse_url($url, PHP_URL_PATH);
    $name = basename($url_path);
    if (empty($name) || strpos($name, '.') === false) {
        $name = 'download_' . time();
        // Try to guess extension from content-type
        $ext_map = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/svg+xml'=>'svg','video/mp4'=>'mp4'];
        $name .= '.' . ($ext_map[$content_type] ?? 'bin');
    }
    $name = preg_replace('/[^\w\.\-]/', '_', $name);
    $target = $resolved['absolute'] . '/' . $name;
    if (file_exists($target)) {
        $pi = pathinfo($name);
        $i = 1;
        while (file_exists($resolved['absolute'] . '/' . $pi['filename'] . '_' . $i . '.' . $pi['extension'])) $i++;
        $name = $pi['filename'] . '_' . $i . '.' . $pi['extension'];
        $target = $resolved['absolute'] . '/' . $name;
    }
    // Block dangerous extensions from remote URLs too (incl. ".php" anywhere in the name)
    $dl_ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($dl_ext, $GLOBALS['_media_dangerous_ext']) || strpos(strtolower($name), '.php') !== false) {
        send_json(['status' => 'error', 'message' => 'Расширение файла запрещено']);
    }
    // Validate downloaded content-type against the same MIME whitelist as regular uploads
    $dl_ct = strtolower(trim(explode(';', (string)$content_type)[0]));
    if (!in_array($dl_ct, $GLOBALS['_media_allowed_mimes'])) {
        send_json(['status' => 'error', 'message' => 'Недопустимый тип файла: ' . $dl_ct]);
    }
    if (file_put_contents($target, $data) !== false) {
        send_json([
            'status' => 'success',
            'message' => 'Файл загружен из URL',
            'file' => [
                'name' => $name,
                'path' => $resolved['relative'] ? $resolved['relative'] . '/' . $name : $name,
                'url' => '/uploads/' . ($resolved['relative'] ? $resolved['relative'] . '/' . $name : $name),
                'size' => strlen($data),
                'size_formatted' => format_bytes_api(strlen($data)),
            ],
        ]);
    }
    send_json(['status' => 'error', 'message' => 'Не удалось сохранить файл']);
}

// === LIST FOLDERS (for move/copy target selection) ===
if ($action === 'list_folders') {
    $folder = $_GET['folder'] ?? '';
    $resolved = media_resolve_path($folder);
    if (!$resolved || !is_dir($resolved['absolute'])) send_json(['status' => 'error', 'message' => 'Папка не найдена']);
    $entries = @scandir($resolved['absolute']);
    $folders = [];
    if ($entries !== false) {
        $entries = array_diff($entries, ['.', '..', '.trash']);
        foreach ($entries as $name) {
            $full = $resolved['absolute'] . '/' . $name;
            if (is_dir($full)) {
                $rel = $resolved['relative'] ? $resolved['relative'] . '/' . $name : $name;
                $folders[] = ['name' => $name, 'path' => $rel];
            }
        }
        usort($folders, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
    }
    send_json(['status' => 'success', 'folders' => $folders, 'current' => $resolved['relative']]);
}

send_json(['status' => 'error', 'message' => 'Неизвестное действие: ' . $action]);
