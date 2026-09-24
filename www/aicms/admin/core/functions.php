<?php
session_start();

// Send security headers (admin mode allows 'unsafe-eval' for Monaco editor)
require_once __DIR__ . '/security_headers.php';
send_security_headers(['admin' => true]);

if (!function_exists('h')) {
    function h($string) { 
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8'); 
    }
}

if (!function_exists('read_json')) {
    function read_json($file_path, $default = []) {
        if (!file_exists($file_path)) return $default;
        $content = @file_get_contents($file_path);
        if ($content === false) return $default;
        $data = json_decode($content, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : $default;
    }
}

$config = read_json(__DIR__ . '/../../data/config.json');

// Overlay environment secrets (data/.env.php) on top of config.json
require_once __DIR__ . '/env.php';
$config = aicms_apply_env($config);

// Поддержка обратной совместимости: перенос старого ключа Gemini
if (empty($config['deepseek_api_key']) && !empty($config['gemini_api_key'])) {
    $config['deepseek_api_key'] = $config['gemini_api_key'];
}

define('SUPPORTED_LANGS', $config['languages'] ?? [['code' => 'ru', 'name' => 'Русский']]);
define('DEFAULT_LANG', $config['default_lang'] ?? 'ru');

if (isset($_GET['set_lang'])) {
    $lang_code = $_GET['set_lang'];
    if (in_array($lang_code, array_column(SUPPORTED_LANGS, 'code'))) { 
        $_SESSION['admin_lang'] = $lang_code; 
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

define('ADMIN_LANG', $_SESSION['admin_lang'] ?? DEFAULT_LANG);

require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/plugins.php';
// multisite.php removed — causes theme override conflicts

function get_content_path($filename, $lang = ADMIN_LANG) {
    // Multi-site support: if sites.json exists and current site is set,
    // use per-site content directory; otherwise fall back to legacy data/content/<lang>/
    if (function_exists('current_site_id') && file_exists(__DIR__ . '/../../data/sites.json')) {
        $site_id = current_site_id();
        $site_path = __DIR__ . '/../../data/sites/' . $site_id . '/content/' . $lang . '/' . $filename;
        if (file_exists($site_path)) return $site_path;
        // If site path doesn't exist yet (new site, no content), still return site path
        // so write_content() creates it in the right place.
        $site_dir = __DIR__ . '/../../data/sites/' . $site_id . '/content/' . $lang;
        if (is_dir($site_dir) || $site_id !== 'default') return $site_path;
    }
    return __DIR__ . "/../../data/content/{$lang}/{$filename}"; 
}

function read_content($filename, $default = [], $lang = null) {
    $lang = $lang ?? ADMIN_LANG;
    return read_json(get_content_path($filename, $lang), $default);
}

function write_content($filename, $data, $lang = null) {
    $lang = $lang ?? ADMIN_LANG;
    $path = get_content_path($filename, $lang);
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    write_json($path, $data);
}

function write_json($file_path, $data) { 
    $fp = fopen($file_path, 'w'); 
    if ($fp && flock($fp, LOCK_EX)) { 
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); 
        fflush($fp); 
        flock($fp, LOCK_UN); 
        fclose($fp); 
        return true; 
    } 
    if($fp) fclose($fp); 
    return false; 
}

function require_auth() { 
    if (!isset($_SESSION['admin'])) { 
        header('Location: login.php'); 
        exit; 
    } 
    // ===== 2FA enforcement =====
    // If 2FA is enabled globally in config but the user hasn't set it up yet,
    // force them to settings page to set it up (except when already there).
    // Also re-verify 2FA every 8 hours to maintain strong auth on long sessions.
    _enforce_2fa();
}

/**
 * 2FA enforcement helper.
 * - If config.features.2fa is ON and current user has no totp_secret: redirect to settings.
 * - If session is older than 8h since last 2FA verification: force re-login.
 * - Skipped on login.php and settings.php?tab=account to allow setup.
 */
function _enforce_2fa() {
    $config_file = __DIR__ . '/../../data/config.json';
    if (!file_exists($config_file)) return;
    $cfg = json_decode(@file_get_contents($config_file), true);
    if (!is_array($cfg) || empty($cfg['features']['2fa'])) return;  // 2FA not enabled

    $users_file = __DIR__ . '/../../data/users.json';
    if (!file_exists($users_file)) return;
    $users = json_decode(@file_get_contents($users_file), true);
    if (!is_array($users)) return;

    $username = $_SESSION['admin'] ?? '';
    if (empty($username)) return;

    // Re-verify 2FA every 8 hours for sensitive sessions
    $last_2fa = $_SESSION['_2fa_verified_at'] ?? 0;
    if ($last_2fa > 0 && (time() - $last_2fa) > 8 * 3600) {
        session_destroy();
        session_start();
        header('Location: login.php?reason=2fa_expired');
        exit;
    }

    // User must have 2FA configured
    $user_has_2fa = !empty($users['totp_secret']) && $users['username'] === $username;
    if (!$user_has_2fa) {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        // Allow access to settings.php (to set up 2FA), logout, login
        $allowed = ['settings.php', 'logout.php', 'login.php'];
        if (!in_array($script, $allowed, true)) {
            header('Location: settings.php?tab=account&setup_2fa_required=1');
            exit;
        }
    }
}

function generate_csrf_token() { 
    if(empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) { 
    if(isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
        return true;
    }
    return false;
}

function csrf_field() { 
    return '<input type="hidden" name="csrf_token" value="' . generate_csrf_token() . '">'; 
}

function csrf_field_value() { 
    return generate_csrf_token(); 
}

/**
 * Marker cookie aicms_authed tells the frontend page cache that this browser
 * is authenticated (admin or site user). Set on login, cleared on logout.
 * It only disables page caching for this visitor — it grants nothing by
 * itself, so forging it has no security impact beyond losing the cache.
 */
if (!function_exists('aicms_cookie_secure')) {
    /**
     * Whether cookies should be marked Secure (only over HTTPS).
     * Detects HTTPS from $_SERVER (supports reverse proxies that set
     * X-Forwarded-Proto, but only if the proxy is trusted via
     * config.security.trusted_proxies).
     */
    function aicms_cookie_secure() {
        // Direct HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
        // Behind trusted reverse proxy
        static $_trusted_proxies = null;
        if ($_trusted_proxies === null) {
            $cfg_file = __DIR__ . '/../../data/config.json';
            $cfg = file_exists($cfg_file) ? json_decode(@file_get_contents($cfg_file), true) : [];
            $_trusted_proxies = is_array($cfg) ? ($cfg['security']['trusted_proxies'] ?? []) : [];
        }
        if (!empty($_trusted_proxies) && !empty($_SERVER['REMOTE_ADDR'])) {
            if (in_array($_SERVER['REMOTE_ADDR'], $_trusted_proxies, true)) {
                return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
            }
        }
        return false;
    }
}

if (!function_exists('aicms_set_authed_cookie')) {
    function aicms_set_authed_cookie() {
        setcookie('aicms_authed', '1', [
            'expires'  => time() + 30 * 86400,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => aicms_cookie_secure(),
        ]);
    }
    function aicms_clear_authed_cookie() {
        setcookie('aicms_authed', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => aicms_cookie_secure(),
        ]);
    }
}

function set_flash_message($message, $type = 'success') { 
    $_SESSION['flash_message'] = ['message' => $message, 'type' => $type]; 
}

function display_flash_message() {
    if(isset($_SESSION['flash_message'])) {
        $msg = $_SESSION['flash_message'];
        $type = h($msg['type']);
        $color_map = [
            'success' => 'bg-green-100 text-green-800 border-green-300',
            'danger'  => 'bg-red-100 text-red-800 border-red-300',
            'warning' => 'bg-amber-100 text-amber-800 border-amber-300',
            'info'    => 'bg-blue-100 text-blue-800 border-blue-300',
        ];
        $colors = $color_map[$type] ?? $color_map['info'];
        echo '<div class="alert-auto-dismiss border rounded-lg px-4 py-3 mb-4 flex justify-between items-center ' . $colors . '" role="alert">' .
             '<span>' . h($msg['message']) . '</span>' .
             '<button type="button" class="ml-4 text-current opacity-50 hover:opacity-100" onclick="this.parentElement.style.display=\'none\'">&times;</button>' .
             '</div>';
        unset($_SESSION['flash_message']);
    }
}

function run_auto_backup() { 
    /* код автобэкапа */ 
} 

/**
 * Verify that an uploaded file is safe by checking:
 *   1. Real MIME type via finfo (NOT the client-supplied $_FILES['type'])
 *   2. Extension matches the detected MIME type
 *   3. File is not a PHP script disguised as something else
 *   4. Magic bytes for known dangerous types (.php, .phtml, .phar)
 *
 * Returns:
 *   - ['ok' => true, 'mime' => 'image/jpeg', 'safe_ext' => 'jpg']  on success
 *   - ['ok' => false, 'reason' => 'why']                          on failure
 *
 * @param string $tmp_path  Path to the uploaded temp file ($_FILES['...']['tmp_name'])
 * @param string $filename  Original filename (for extension check)
 * @return array
 */
if (!function_exists('verify_upload_safe')) {
    function verify_upload_safe($tmp_path, $filename) {
        if (!file_exists($tmp_path) || !is_readable($tmp_path)) {
            return ['ok' => false, 'reason' => 'Temporary file not readable'];
        }
        // 1. Detect real MIME via finfo (file content inspection, not extension)
        $real_mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = finfo_file($finfo, $tmp_path);
                finfo_close($finfo);
                if ($detected) $real_mime = $detected;
            }
        }
        // 2. Check first bytes for PHP tags (most common attack vector)
        $head = @file_get_contents($tmp_path, false, null, 0, 4096);
        if ($head !== false) {
            // PHP open tag in any form: <?php, <?=, <? (short), <?=
            if (preg_match('/<\?(php|=|\s)/i', $head)) {
                return ['ok' => false, 'reason' => 'File contains PHP code (detected <?php tag)'];
            }
            // ASP-style tags
            if (stripos($head, '<%') !== false) {
                return ['ok' => false, 'reason' => 'File contains server-side script tags'];
            }
        }
        // 3. Whitelist of allowed MIME types and their typical extensions
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed = [
            // Images
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png'  => ['png'],
            'image/gif'  => ['gif'],
            'image/webp' => ['webp'],
            'image/bmp'  => ['bmp'],
            'image/svg+xml' => ['svg'],
            'image/x-icon'  => ['ico'],
            // Documents
            'application/pdf' => ['pdf'],
            'text/plain'      => ['txt', 'csv', 'md', 'log'],
            'text/html'       => ['html', 'htm'],
            'text/css'        => ['css'],
            'text/javascript' => ['js'],
            'application/json'=> ['json'],
            'application/xml' => ['xml'],
            'application/zip' => ['zip'],
            'application/x-zip-compressed' => ['zip'],
            'application/x-rar-compressed' => ['rar'],
            'application/x-7z-compressed'  => ['7z'],
            'application/gzip' => ['gz', 'tar.gz'],
            'application/x-tar' => ['tar'],
            // Video
            'video/mp4'  => ['mp4'],
            'video/webm' => ['webm'],
            'video/quicktime' => ['mov'],
            // Audio
            'audio/mpeg' => ['mp3'],
            'audio/wav'  => ['wav'],
            'audio/ogg'  => ['ogg'],
            // Office
            'application/msword' => ['doc'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/vnd.ms-excel' => ['xls'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
            'application/vnd.ms-powerpoint' => ['ppt'],
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        ];
        // 4. Block dangerous extensions outright, regardless of MIME
        $dangerous_ext = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'phps', 'pht', 'asp', 'aspx', 'jsp', 'exe', 'sh', 'bat', 'cmd', 'com', 'so', 'dll'];
        if (in_array($ext, $dangerous_ext)) {
            return ['ok' => false, 'reason' => 'Extension "' . $ext . '" is not allowed'];
        }
        // 5. If MIME is in whitelist, extension must match
        if (isset($allowed[$real_mime])) {
            if (!in_array($ext, $allowed[$real_mime])) {
                // Some MIMEs are permissive — allow if ext is at least in a known-good set across types
                $all_safe_ext = [];
                foreach ($allowed as $m => $exts) { $all_safe_ext = array_merge($all_safe_ext, $exts); }
                if (!in_array($ext, $all_safe_ext)) {
                    return ['ok' => false, 'reason' => 'Extension "' . $ext . '" does not match detected type "' . $real_mime . '"'];
                }
            }
        } else {
            // Unknown MIME — be conservative, allow only if ext is in safe list
            $all_safe_ext = [];
            foreach ($allowed as $m => $exts) { $all_safe_ext = array_merge($all_safe_ext, $exts); }
            if (!in_array($ext, $all_safe_ext)) {
                return ['ok' => false, 'reason' => 'Unknown file type: ' . $real_mime . ' (ext: ' . $ext . ')'];
            }
        }
        return ['ok' => true, 'mime' => $real_mime, 'safe_ext' => $ext];
    }
}

if (isset($_SESSION['admin'])) { 
    run_auto_backup(); 
}

/**
 * Check if an IP address is within a CIDR range.
 * @param string $ip IP address (e.g. '192.168.1.1')
 * @param string $range CIDR range (e.g. '192.168.0.0/16')
 * @return bool
 */
if (!function_exists('ip_in_range')) {
    function ip_in_range($ip, $range) {
        if (strpos($range, '/') === false) {
            $range .= '/32';
        }
        list($subnet, $bits) = explode('/', $range);
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet_long &= $mask;
        return ($ip_long & $mask) === $subnet_long;
    }
}

/**
 * Get the real client IP address (proxy-aware).
 * Falls back to REMOTE_ADDR unless the request comes from a trusted proxy,
 * in which case X-Forwarded-For is parsed and the first public IP is used.
 *
 * Trusted proxies are configured in data/config.json under security.trusted_proxies
 * (array of IP or CIDR strings).
 *
 * @return string
 */
if (!function_exists('client_ip')) {
    function client_ip() {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!filter_var($remote, FILTER_VALIDATE_IP)) return '0.0.0.0';

        // Load trusted proxies once per request
        static $_trusted = null;
        if ($_trusted === null) {
            $cfg_file = __DIR__ . '/../../data/config.json';
            $cfg = file_exists($cfg_file) ? json_decode(@file_get_contents($cfg_file), true) : [];
            $_trusted = is_array($cfg) ? ($cfg['security']['trusted_proxies'] ?? []) : [];
        }
        if (empty($_trusted)) return $remote;

        // Is the direct connection from a trusted proxy?
        $from_trusted = false;
        foreach ($_trusted as $t) {
            if (strpos($t, '/') !== false) {
                if (ip_in_range($remote, $t)) { $from_trusted = true; break; }
            } elseif ($remote === $t) {
                $from_trusted = true; break;
            }
        }
        if (!$from_trusted) return $remote;

        // Take the LEFT-most IP from X-Forwarded-For that is not trusted
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff === '') return $remote;
        $parts = array_map('trim', explode(',', $xff));
        foreach ($parts as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                // Make sure this candidate isn't itself a trusted proxy
                $is_trusted = false;
                foreach ($_trusted as $t) {
                    if (strpos($t, '/') !== false) {
                        if (ip_in_range($candidate, $t)) { $is_trusted = true; break; }
                    } elseif ($candidate === $t) {
                        $is_trusted = true; break;
                    }
                }
                if (!$is_trusted) return $candidate;
            }
        }
        return $remote;
    }
}

/**
 * Convert an image file to WebP format.
 * Requires PHP-GD or Imagick extension.
 *
 * @param string $source_path Path to the source image (jpg/png/gif)
 * @param string $dest_path Path to save the WebP file (must end with .webp)
 * @param int $quality Quality (0-100), default 85
 * @return bool True on success, false on failure
 */
if (!function_exists('convert_to_webp')) {
    function convert_to_webp($source_path, $dest_path, $quality = 85) {
        if (!file_exists($source_path)) return false;
        $ext = strtolower(pathinfo($source_path, PATHINFO_EXTENSION));

        // Try GD first
        if (function_exists('imagewebp') && function_exists('imagecreatefromjpeg')) {
            $image = null;
            switch ($ext) {
                case 'jpg':
                case 'jpeg':
                    $image = @imagecreatefromjpeg($source_path);
                    break;
                case 'png':
                    $image = @imagecreatefrompng($source_path);
                    // Preserve transparency
                    if ($image) {
                        imagepalettetotruecolor($image);
                        imagealphablending($image, true);
                        imagesavealpha($image, true);
                    }
                    break;
                case 'gif':
                    $image = @imagecreatefromgif($source_path);
                    break;
                default:
                    return false;
            }
            if (!$image) return false;
            $result = imagewebp($image, $dest_path, $quality);
            imagedestroy($image);
            return $result;
        }

        // Try Imagick
        if (class_exists('Imagick')) {
            try {
                $image = new Imagick($source_path);
                $image->setImageFormat('webp');
                $image->setImageCompressionQuality($quality);
                $result = $image->writeImage($dest_path);
                $image->destroy();
                return $result;
            } catch (Exception $e) {
                return false;
            }
        }

        return false;
    }
}