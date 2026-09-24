<?php
// Lumina Editor image upload endpoint
// Hardened version: matches the security level of admin/upload_image.php
// (PHP-tag scanning, MIME-based extension, dated subfolder, file size cap).

ob_start();

require_once __DIR__ . '/core/functions.php';

header('Content-Type: application/json; charset=utf-8');

function send_error($message, $code = 400) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

// Authentication via upload token (Lumina uses session-based auth)
$secret_key_from_session = $_SESSION['upload_secret_key'] ?? null;
if (!$secret_key_from_session && !isset($_SESSION['admin'])) {
    send_error('Access denied.', 403);
}

// Verify CSRF
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    send_error('CSRF error.', 403);
}

if (empty($_FILES['file'])) {
    send_error('File not found.');
}

$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    send_error('Upload error. Code: ' . $file['error'], 500);
}

// File size limit: 8 MB (matches upload_image.php)
$max_file_size = 8 * 1024 * 1024;
if ($file['size'] <= 0 || $file['size'] > $max_file_size) {
    send_error('File too large. Maximum size is 8 MB.', 413);
}

$upload_dir_relative = '/uploads/images/';
$upload_dir_absolute = realpath(__DIR__ . '/..') . $upload_dir_relative;
if (!is_dir($upload_dir_absolute)) {
    mkdir($upload_dir_absolute, 0755, true);
}

$allowed_mime_types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($allowed_mime_types[$mime_type])) {
    send_error('Invalid file type. Allowed: JPG, PNG, GIF, WEBP.');
}

// Defence in depth: scan first 4 KB for PHP/script tags — even though
// MIME check should catch disguised scripts, a polyglot JPEG could still
// contain a PHP open tag in its EXIF/metadata.
$head = @file_get_contents($file['tmp_name'], false, null, 0, 4096);
if ($head !== false) {
    if (preg_match('/<\?(php|=|\s)/i', $head) || stripos($head, '<%') !== false) {
        send_error('File contains server-side script code.', 400);
    }
}

// Extension is derived from the DETECTED MIME type — original filename
// (including double/tricky extensions) is completely ignored.
$extension = $allowed_mime_types[$mime_type];

// Compute the dated subfolder ONCE (calling date() twice could in theory
// return different values across a midnight boundary, breaking the path).
$date_subdir = date('Y/m/d');
$unique_filename = $date_subdir . '/' . bin2hex(random_bytes(8)) . '.' . $extension;
$dest_dir = $upload_dir_absolute . $date_subdir;
if (!is_dir($dest_dir)) {
    mkdir($dest_dir, 0755, true);
}
$destination_path = $upload_dir_absolute . $unique_filename;

if (move_uploaded_file($file['tmp_name'], $destination_path)) {
    // Optional: convert to WebP if the source is JPEG/PNG and the
    // 'webp_conversion' feature is enabled in config. Saves bandwidth.
    $config = read_json(__DIR__ . '/../data/config.json', []);
    if (!empty($config['features']['webp_conversion']) && function_exists('imagewebp') && in_array($mime_type, ['image/jpeg', 'image/png'], true)) {
        $webp_path = $dest_dir . '/' . bin2hex(random_bytes(8)) . '.webp';
        if (convert_to_webp($destination_path, $webp_path, 85)) {
            // Use the WebP version if conversion succeeded; delete original
            @unlink($destination_path);
            $unique_filename = $date_subdir . '/' . basename($webp_path);
        }
    }

    // Return URL in format expected by Lumina editor
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $base_path = dirname(dirname($_SERVER['SCRIPT_NAME']));
    if ($base_path === '/' || $base_path === '\\') { $base_path = ''; }
    $url = $protocol . '://' . $host . $base_path . $upload_dir_relative . $unique_filename;

    ob_end_clean();
    echo json_encode(['link' => $url]);
} else {
    send_error('Failed to move file.', 500);
}
exit;