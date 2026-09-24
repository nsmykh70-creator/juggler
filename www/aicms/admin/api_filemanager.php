<?php
/**
 * File Manager API
 * =================
 * Full file management for /uploads/ directory.
 */

require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/rate_limit.php';
require_auth();
header('Content-Type: application/json; charset=utf-8');

// Rate limiting: 180 requests per minute per user (file manager makes many small requests)
if (!api_rate_limit('api_filemanager', 180, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    $status = api_rate_limit_status('api_filemanager', 180, 60);
    header('X-RateLimit-Limit: ' . $status['limit']);
    header('X-RateLimit-Remaining: 0');
    header('X-RateLimit-Reset: ' . $status['reset']);
    echo json_encode(['status' => 'error', 'message' => 'Too Many Requests. Try again in a minute.']);
    exit;
}
$_rl_status = api_rate_limit_status('api_filemanager', 180, 60);
header('X-RateLimit-Limit: ' . $_rl_status['limit']);
header('X-RateLimit-Remaining: ' . $_rl_status['remaining']);
header('X-RateLimit-Reset: ' . $_rl_status['reset']);

// === Helper functions (defined once, outside conditionals) ===

if (!function_exists('fm_send_json')) {
    function fm_send_json($data) {
        $data['new_csrf_token'] = generate_csrf_token();
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if (!function_exists('fm_resolve_path')) {
    function fm_resolve_path($uploads_root, $relative) {
        $relative = trim(str_replace('\\', '/', $relative ?? ''), '/');
        if (strpos($relative, '..') !== false || strpos($relative, "\0") !== false) return null;
        $parts = explode('/', $relative);
        foreach ($parts as $p) {
            if ($p !== '' && $p[0] === '.') return null;
        }
        $absolute = $uploads_root . '/' . $relative;
        // Build URL base from root_name (set globally above)
        $rn = $GLOBALS['_fm_root_name'] ?? basename($uploads_root);
        if ($rn === 'aicms_root') { $url_base = ''; } // root = site root
        elseif ($rn === 'admin') { $url_base = '/admin'; }
        elseif ($rn === 'api') { $url_base = '/api'; }
        else { $url_base = '/' . $rn; }
        if (file_exists($absolute)) {
            $real = realpath($absolute);
            if ($real === false || strpos($real, $uploads_root) !== 0) return null;
            $absolute = $real;
        }
        return ['absolute' => $absolute, 'relative' => $relative, 'url' => $url_base . '/' . $relative, 'root_name' => $rn];
    }
}
if (!function_exists('fm_fmt_size')) {
    function fm_fmt_size($b) {
        $u = ['B','KB','MB','GB'];
        $i = 0;
        while ($b >= 1024 && $i < count($u)-1) { $b /= 1024; $i++; }
        return round($b, 1) . ' ' . $u[$i];
    }
}
if (!function_exists('fm_is_img')) {
    function fm_is_img($f) {
        return in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp','svg','bmp','ico']);
    }
}
if (!function_exists('fm_is_vid')) {
    function fm_is_vid($f) {
        return in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['mp4','webm','ogg','mov','avi','mkv']);
    }
}
if (!function_exists('fm_is_doc')) {
    function fm_is_doc($f) {
        return in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','zip','rar','7z','tar','gz','css','js','html','htm','json','xml','php','md']);
    }
}
if (!function_exists('fm_is_text')) {
    function fm_is_text($f) {
        return in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['txt','css','js','html','htm','json','xml','md','csv','php','sql','yaml','yml','ini','conf','htaccess']);
    }
}
if (!function_exists('fm_safe_slug')) {
    function fm_safe_slug($s) {
        if (function_exists('transliterator_transliterate')) {
            $t = @transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
            if ($t !== false && $t !== '') $s = $t;
        }
        return preg_replace('/[^a-zA-Z0-9.\-_]/', '', $s);
    }
}

/**
 * Resolve a root name (e.g. 'uploads', 'admin', 'aicms_root') to its absolute path.
 * Centralised so bulk endpoints don't duplicate this logic.
 *
 * @param string $root_name  One of: uploads, data, themes, modules, assets, widgets, admin, api, aicms_root
 * @return string|false  Absolute path, or false if invalid/missing.
 */
if (!function_exists('fm_resolve_root')) {
    function fm_resolve_root($root_name) {
        $allowed = ['uploads', 'data', 'themes', 'modules', 'assets', 'widgets', 'admin', 'api', 'aicms_root'];
        if (!in_array($root_name, $allowed)) return false;
        $cms_root = realpath(__DIR__ . '/..');
        if ($root_name === 'aicms_root') return $cms_root;
        if ($root_name === 'admin') return realpath(__DIR__);
        if ($root_name === 'api') return realpath(__DIR__ . '/../api');
        $path = $cms_root . '/' . $root_name;
        if (!is_dir($path)) {
            if ($root_name === 'data') { @mkdir($path, 0755, true); }
            else return false;
        }
        return realpath($path);
    }
}
if (!function_exists('fm_rrmdir')) {
    function fm_rrmdir($d) {
        $f = array_diff(scandir($d), ['.', '..']);
        foreach ($f as $i) {
            $p = $d . '/' . $i;
            if (is_dir($p)) fm_rrmdir($p);
            else unlink($p);
        }
        return rmdir($d);
    }
}
if (!function_exists('fm_rcopy')) {
    function fm_rcopy($src, $dst) {
        if (is_dir($src)) {
            if (!is_dir($dst)) mkdir($dst, 0755, true);
            $f = array_diff(scandir($src), ['.', '..']);
            foreach ($f as $i) {
                fm_rcopy($src . '/' . $i, $dst . '/' . $i);
            }
        } else {
            copy($src, $dst);
        }
    }
}
if (!function_exists('fm_add_dir_to_zip')) {
    function fm_add_dir_to_zip($zip, $dir, $base) {
        $zip->addEmptyDir($base);
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $f) {
            $full = $dir . '/' . $f;
            $rel = $base . '/' . $f;
            if (is_dir($full)) fm_add_dir_to_zip($zip, $full, $rel);
            else $zip->addFile($full, $rel);
        }
    }
}

// === CSRF ===
$token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
if (!verify_csrf_token($token)) {
    http_response_code(403);
    fm_send_json(['status' => 'error', 'message' => 'CSRF error']);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// === Root directory selection ===
$root_name = $_POST['root'] ?? $_GET['root'] ?? 'uploads';
$root_real = fm_resolve_root($root_name);
if ($root_real === false) {
    fm_send_json(['status' => 'error', 'message' => 'Недопустимая корневая папка']);
}
$uploads_root = $root_real;
// Store root_name globally so fm_resolve_path can build correct URLs
$GLOBALS['_fm_root_name'] = $root_name;

// === LIST ===
if ($action === 'list') {
    $resolved = fm_resolve_path($uploads_root, $_GET['path'] ?? '');
    if (!$resolved || !is_dir($resolved['absolute'])) {
        fm_send_json(['status' => 'error', 'message' => 'Папка не найдена']);
    }
    $entries = @scandir($resolved['absolute']);
    if ($entries === false) $entries = [];
    $entries = array_diff($entries, ['.', '..']);
    $folders = []; $files = [];
    foreach ($entries as $entry) {
        $full = $resolved['absolute'] . '/' . $entry;
        if (is_dir($full)) {
            $folders[] = ['name'=>$entry, 'path'=>$resolved['relative']?$resolved['relative'].'/'.$entry:$entry, 'mtime'=>filemtime($full)];
        } else {
            $size = filesize($full);
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            $files[] = ['name'=>$entry,'path'=>$resolved['relative'],'url'=>$resolved['url'].'/'.$entry,
                'size'=>$size,'size_h'=>fm_fmt_size($size),'ext'=>$ext,
                'is_image'=>fm_is_img($entry),'is_video'=>fm_is_vid($entry),'is_doc'=>fm_is_doc($entry),'is_text'=>fm_is_text($entry),
                'mtime'=>filemtime($full),'mtime_h'=>date('d.m.Y H:i',filemtime($full))];
        }
    }
    usort($folders, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
    usort($files, function($a, $b) { return $b['mtime'] <=> $a['mtime']; });
    if (!$resolved['relative'] && !is_dir($uploads_root . '/ai')) {
        @mkdir($uploads_root . '/ai', 0755, true);
    }
    $breadcrumbs = [['name'=>'uploads','path'=>'']];
    if (!empty($resolved['relative'])) {
        $acc = '';
        foreach (explode('/', $resolved['relative']) as $p) {
            $acc = $acc ? $acc.'/'.$p : $p;
            $breadcrumbs[] = ['name'=>$p, 'path'=>$acc];
        }
    }
    fm_send_json(['status'=>'success','path'=>$resolved['relative'],'breadcrumbs'=>$breadcrumbs,'folders'=>$folders,'files'=>$files]);
}

// === UPLOAD ===
if ($action === 'upload') {
    if (!isset($_FILES['files'])) fm_send_json(['status'=>'error','message'=>'Файлы не найдены']);
    $resolved = fm_resolve_path($uploads_root, $_POST['path'] ?? '');
    if (!$resolved || !is_dir($resolved['absolute'])) fm_send_json(['status'=>'error','message'=>'Папка не найдена']);
    $ok = 0; $errors = [];
    // Hard-block extensions that the web server would EXECUTE — even if an
    // admin is uploading into admin/ or aicms_root. These can be edited via
    // the dedicated code editor (which has its own auth), never uploaded
    // as arbitrary files via the file manager.
    $hard_blocked_ext = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'phps', 'pht', 'asp', 'aspx', 'jsp', 'sh', 'cgi', 'pl', 'py'];
    foreach ($_FILES['files']['tmp_name'] as $i => $tmp) {
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) { $errors[] = $_FILES['files']['name'][$i].': код '.$_FILES['files']['error'][$i]; continue; }
        $orig_name = $_FILES['files']['name'][$i];
        $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

        // Hard block: server-side executable extensions are NEVER allowed via upload
        if (in_array($ext, $hard_blocked_ext, true)) {
            $errors[] = $orig_name . ': загрузка файлов .' . $ext . ' запрещена. Используйте редактор кода.';
            continue;
        }

        // Security: verify MIME type, block PHP scripts, check extension
        $check = verify_upload_safe($tmp, $orig_name);
        if (!$check['ok']) {
            $errors[] = $orig_name . ': ' . $check['reason'];
            continue;
        }
        $safe = fm_safe_slug($orig_name);
        if (empty($safe)) $safe = 'file_'.time();
        // Final guard: ensure the sanitised name has no executable extension
        $safe_ext = strtolower(pathinfo($safe, PATHINFO_EXTENSION));
        if (in_array($safe_ext, $hard_blocked_ext, true)) {
            $errors[] = $orig_name . ': запрещённое расширение';
            continue;
        }
        $dest = $resolved['absolute'].'/'.$safe;
        if (move_uploaded_file($tmp, $dest)) $ok++; else $errors[] = $orig_name.': не удалось переместить';
    }
    if ($ok > 0) {
        $msg = 'Загружено: '.$ok;
        if ($errors) $msg .= '. Ошибки: '.implode('; ', $errors);
        fm_send_json(['status'=>'success','message'=>$msg,'errors'=>$errors]);
    }
    fm_send_json(['status'=>'error','message'=>implode('; ', $errors)]);
}

// === SOFT DELETE — move to .trash/ instead of permanent delete ===
if (!function_exists('fm_soft_delete')) {
    function fm_soft_delete($target, $root_dir) {
        // Create .trash directory if not exists
        $trash_dir = $root_dir . '/.trash';
        if (!is_dir($trash_dir)) {
            @mkdir($trash_dir, 0755, true);
        }
        
        $basename = basename($target);
        $trash_target = $trash_dir . '/' . $basename;
        
        // If file with same name exists in trash, append timestamp
        if (file_exists($trash_target)) {
            $info = pathinfo($basename);
            $trash_target = $trash_dir . '/' . $info['filename'] . '_' . time() . '.' . ($info['extension'] ?? 'dat');
        }
        
        // Move to trash
        if (rename($target, $trash_target)) {
            return true;
        }
        return false;
    }
}

// === DELETE (now uses soft-delete) ===
if ($action === 'delete') {
    $resolved = fm_resolve_path($uploads_root, $_POST['path'] ?? '');
    if (!$resolved) fm_send_json(['status'=>'error','message'=>'Путь не найден']);
    $name = basename($_POST['name'] ?? '');
    if (!$name || $name[0] === '.') fm_send_json(['status'=>'error','message'=>'Имя не указано']);
    // Block deletion of CMS-critical files in aicms_root
    if ($root_name === 'aicms_root' && $resolved['relative'] === '') {
        $protected = ['index.php','install.php','robots.php','sitemap.php','feed.php','user.php','og_image.php','.htaccess','package.json','cron.php'];
        if (in_array($name, $protected)) fm_send_json(['status'=>'error','message'=>'Нельзя удалить критический файл системы: '.$name]);
    }
    $target = $resolved['absolute'].'/'.$name;
    if (!file_exists($target)) fm_send_json(['status'=>'error','message'=>'Не найдено']);
    
    // Use soft-delete: move to .trash/ instead of permanent deletion
    if (fm_soft_delete($target, $resolved['absolute'])) {
        fm_send_json(['status'=>'success','message'=>'Файл перемещён в корзину (.trash/)']);
    }
    fm_send_json(['status'=>'error','message'=>'Ошибка удаления']);
}

// === PERMANENT DELETE (bypasses .trash/) ===
if ($action === 'permanent_delete') {
    $resolved = fm_resolve_path($uploads_root, $_POST['path'] ?? '');
    if (!$resolved) fm_send_json(['status'=>'error','message'=>'Путь не найден']);
    $name = basename($_POST['name'] ?? '');
    if (!$name || $name[0] === '.') fm_send_json(['status'=>'error','message'=>'Имя не указано']);
    $target = $resolved['absolute'].'/'.$name;
    if (!file_exists($target)) fm_send_json(['status'=>'error','message'=>'Не найдено']);
    if (is_dir($target)) {
        if (fm_rrmdir($target)) fm_send_json(['status'=>'success','message'=>'Папка удалена навсегда']);
    } else {
        if (unlink($target)) fm_send_json(['status'=>'success','message'=>'Файл удалён навсегда']);
    }
    fm_send_json(['status'=>'error','message'=>'Ошибка удаления']);
}

// === RESTORE FROM TRASH ===
if ($action === 'restore') {
    $resolved = fm_resolve_path($uploads_root, $_POST['path'] ?? '');
    if (!$resolved) fm_send_json(['status'=>'error','message'=>'Путь не найдена']);
    $name = basename($_POST['name'] ?? '');
    if (!$name) fm_send_json(['status'=>'error','message'=>'Имя не указано']);
    $trash_path = $resolved['absolute'] . '/.trash/' . $name;
    $restore_path = $resolved['absolute'] . '/' . $name;
    if (!file_exists($trash_path)) fm_send_json(['status'=>'error','message'=>'Файл не найден в корзине']);
    if (file_exists($restore_path)) {
        // Name collision — rename restored file
        $info = pathinfo($name);
        $restore_path = $resolved['absolute'] . '/' . $info['filename'] . '_restored_' . time() . '.' . ($info['extension'] ?? 'dat');
    }
    if (rename($trash_path, $restore_path)) {
        fm_send_json(['status'=>'success','message'=>'Файл восстановлен из корзины']);
    }
    fm_send_json(['status'=>'error','message'=>'Ошибка восстановления']);
}

// === LIST TRASH ===
if ($action === 'list_trash') {
    $resolved = fm_resolve_path($uploads_root, $_GET['path'] ?? '');
    if (!$resolved) fm_send_json(['status'=>'error','message'=>'Путь не найден']);
    $trash_dir = $resolved['absolute'] . '/.trash';
    $items = [];
    if (is_dir($trash_dir)) {
        $entries = @scandir($trash_dir);
        if ($entries) {
            foreach (array_diff($entries, ['.', '..']) as $entry) {
                $full = $trash_dir . '/' . $entry;
                $items[] = [
                    'name' => $entry,
                    'is_dir' => is_dir($full),
                    'size' => is_file($full) ? filesize($full) : 0,
                    'mtime' => filemtime($full),
                ];
            }
        }
    }
    fm_send_json(['status'=>'success','items'=>$items]);
}

// === BULK DELETE (uses soft-delete) ===
// POST: path, names[] (array of names in the same folder)
if ($action === 'bulk_delete') {
    $resolved = fm_resolve_path($uploads_root, $_POST['path'] ?? '');
    if (!$resolved) fm_send_json(['status'=>'error','message'=>'Путь не найден']);
    $names = $_POST['names'] ?? [];
    if (!is_array($names) || empty($names)) fm_send_json(['status'=>'error','message'=>'Не выбраны файлы']);
    $ok = 0; $errors = []; $skipped = 0;
    $protected = ['index.php','install.php','robots.php','sitemap.php','feed.php','user.php','og_image.php','.htaccess','package.json','cron.php'];
    foreach ($names as $name) {
        $name = basename($name);
        if (!$name || $name[0] === '.') { $skipped++; continue; }
        if ($root_name === 'aicms_root' && $resolved['relative'] === '' && in_array($name, $protected)) {
            $errors[] = $name.': критический файл';
            continue;
        }
        $target = $resolved['absolute'].'/'.$name;
        if (!file_exists($target)) { $errors[] = $name.': не найдено'; continue; }
        // Use soft-delete
        if (fm_soft_delete($target, $resolved['absolute'])) $ok++; else $errors[] = $name.': ошибка';
    }
    $msg = 'Перемещено в корзину: '.$ok;
    if ($skipped) $msg .= ', пропущено: '.$skipped;
    if ($errors) $msg .= '. Ошибки: '.implode('; ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' (всего '.count($errors).')' : '');
    if ($ok > 0) fm_send_json(['status'=>'success','message'=>$msg,'deleted'=>$ok,'errors'=>$errors]);
    fm_send_json(['status'=>'error','message'=>$msg]);
}

// === BULK ZIP ===
// POST: path, names[] (array), zip_name
if ($action === 'bulk_zip') {
    $resolved = fm_resolve_path($uploads_root, $_POST['path'] ?? '');
    if (!$resolved) fm_send_json(['status'=>'error','message'=>'Путь не найден']);
    $names = $_POST['names'] ?? [];
    if (!is_array($names) || empty($names)) fm_send_json(['status'=>'error','message'=>'Не выбраны файлы']);
    $zip_name = fm_safe_slug($_POST['zip_name'] ?? 'archive');
    if (empty($zip_name)) $zip_name = 'archive';
    $zip_name .= '.zip';
    $zip_path = $resolved['absolute'].'/'.$zip_name;
    if (file_exists($zip_path)) fm_send_json(['status'=>'error','message'=>'Архив уже существует: '.$zip_name]);
    if (!class_exists('ZipArchive')) fm_send_json(['status'=>'error','message'=>'ZipArchive не установлен']);
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE) !== true) fm_send_json(['status'=>'error','message'=>'Не удалось создать архив']);
    $added = 0;
    foreach ($names as $name) {
        $name = basename($name);
        if (!$name || $name[0] === '.') continue;
        $full = $resolved['absolute'].'/'.$name;
        if (file_exists($full)) {
            if (is_dir($full)) {
                fm_add_dir_to_zip($zip, $full, $name);
            } else {
                $zip->addFile($full, $name);
            }
            $added++;
        }
    }
    $zip->close();
    if ($added > 0) fm_send_json(['status'=>'success','message'=>'Архив создан: '.$zip_name.' (файлов: '.$added.')']);
    @unlink($zip_path);
    fm_send_json(['status'=>'error','message'=>'Не удалось добавить файлы в архив']);
}

// === BULK MOVE ===
// POST: from_path, to_path, names[] (array), [from_root / to_root for cross-root]
if ($action === 'bulk_move') {
    // Resolve source root (may differ from target $root_name)
    $src_root_name = $_POST['from_root'] ?? $root_name;
    $src_uploads_root = ($src_root_name === $root_name) ? $uploads_root : fm_resolve_root($src_root_name);
    if ($src_uploads_root === false) fm_send_json(['status'=>'error','message'=>'Недопустимый исходный root']);
    $GLOBALS['_fm_root_name'] = $src_root_name;
    $src = fm_resolve_path($src_uploads_root, $_POST['from_path'] ?? '');
    $GLOBALS['_fm_root_name'] = $root_name;
    $dst = fm_resolve_path($uploads_root, $_POST['to_path'] ?? '');
    if (!$src || !$dst) fm_send_json(['status'=>'error','message'=>'Путь не найден']);
    $names = $_POST['names'] ?? [];
    if (!is_array($names) || empty($names)) fm_send_json(['status'=>'error','message'=>'Не выбраны файлы']);
    $ok = 0; $errors = [];
    foreach ($names as $name) {
        $name = basename($name);
        if (!$name || $name[0] === '.') continue;
        $src_path = $src['absolute'].'/'.$name;
        $dst_path = $dst['absolute'].'/'.$name;
        if (!file_exists($src_path)) { $errors[] = $name.': не найден'; continue; }
        if (file_exists($dst_path)) { $errors[] = $name.': уже существует в целевой папке'; continue; }
        if (rename($src_path, $dst_path)) $ok++; else $errors[] = $name.': ошибка';
    }
    $msg = 'Перемещено: '.$ok;
    if ($errors) $msg .= '. Ошибки: '.implode('; ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' (всего '.count($errors).')' : '');
    if ($ok > 0) fm_send_json(['status'=>'success','message'=>$msg,'moved'=>$ok,'errors'=>$errors]);
    fm_send_json(['status'=>'error','message'=>$msg]);
}

// === BULK COPY ===
// POST: from_path, to_path, names[] (array), [from_root]
if ($action === 'bulk_copy') {
    $src_root_name = $_POST['from_root'] ?? $root_name;
    $src_uploads_root = ($src_root_name === $root_name) ? $uploads_root : fm_resolve_root($src_root_name);
    if ($src_uploads_root === false) fm_send_json(['status'=>'error','message'=>'Недопустимый исходный root']);
    $GLOBALS['_fm_root_name'] = $src_root_name;
    $src = fm_resolve_path($src_uploads_root, $_POST['from_path'] ?? '');
    $GLOBALS['_fm_root_name'] = $root_name;
    $dst = fm_resolve_path($uploads_root, $_POST['to_path'] ?? '');
    if (!$src || !$dst) fm_send_json(['status'=>'error','message'=>'Путь не найден']);
    $names = $_POST['names'] ?? [];
    if (!is_array($names) || empty($names)) fm_send_json(['status'=>'error','message'=>'Не выбраны файлы']);
    $ok = 0; $errors = [];
    foreach ($names as $name) {
        $name = basename($name);
        if (!$name || $name[0] === '.') continue;
        $src_path = $src['absolute'].'/'.$name;
        $dst_path = $dst['absolute'].'/'.$name;
        if (!file_exists($src_path)) { $errors[] = $name.': не найден'; continue; }
        if (file_exists($dst_path)) { $errors[] = $name.': уже существует в целевой папке'; continue; }
        try { fm_rcopy($src_path, $dst_path); $ok++; } catch (Exception $e) { $errors[] = $name.': '.$e->getMessage(); }
    }
    $msg = 'Скопировано: '.$ok;
    if ($errors) $msg .= '. Ошибки: '.implode('; ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' (всего '.count($errors).')' : '');
    if ($ok > 0) fm_send_json(['status'=>'success','message'=>$msg,'copied'=>$ok,'errors'=>$errors]);
    fm_send_json(['status'=>'error','message'=>$msg]);
}

// === BULK DOWNLOAD (stream a zip to browser) ===
// GET: path, names[]
if ($action === 'bulk_download') {
    $resolved = fm_resolve_path($uploads_root, $_GET['path'] ?? '');
    if (!$resolved) fm_send_json(['status'=>'error','message'=>'Путь не найден']);
    $names = $_GET['names'] ?? [];
    if (!is_array($names) || empty($names)) fm_send_json(['status'=>'error','message'=>'Не выбраны файлы']);
    if (!class_exists('ZipArchive')) fm_send_json(['status'=>'error','message'=>'ZipArchive не установлен']);
    $tmp_path = sys_get_temp_dir() . '/aicms_fm_' . uniqid() . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmp_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) fm_send_json(['status'=>'error','message'=>'Не удалось создать архив']);
    foreach ($names as $name) {
        $name = basename($name);
        if (!$name || $name[0] === '.') continue;
        $full = $resolved['absolute'].'/'.$name;
        if (file_exists($full)) {
            if (is_dir($full)) fm_add_dir_to_zip($zip, $full, $name);
            else $zip->addFile($full, $name);
        }
    }
    $zip->close();
    if (!file_exists($tmp_path) || filesize($tmp_path) === 0) { @unlink($tmp_path); fm_send_json(['status'=>'error','message'=>'Не удалось создать архив']); }
    // Stream download
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="aicms_download_' . date('Y-m-d_His') . '.zip"');
    header('Content-Length: ' . filesize($tmp_path));
    readfile($tmp_path);
    @unlink($tmp_path);
    exit;
}

// === MKDIR ===
if ($action === 'mkdir') {
    $resolved = fm_resolve_path($uploads_root, $_POST['path'] ?? '');
    if (!$resolved || !is_dir($resolved['absolute'])) fm_send_json(['status'=>'error','message'=>'Папка не найдена']);
    $name = fm_safe_slug($_POST['name'] ?? '');
    if (empty($name)) fm_send_json(['status'=>'error','message'=>'Недопустимое имя']);
    $new_path = $resolved['absolute'].'/'.$name;
    if (is_dir($new_path)) fm_send_json(['status'=>'error','message'=>'Уже существует']);
    if (mkdir($new_path, 0755, true)) fm_send_json(['status'=>'success','message'=>'Папка создана']);
    fm_send_json(['status'=>'error','message'=>'Не удалось создать']);
}

// === RENAME ===
if ($action === 'rename') {
    $resolved = fm_resolve_path($uploads_root, $_POST['path'] ?? '');
    if (!$resolved) fm_send_json(['status'=>'error','message'=>'Путь не найден']);
    $old = basename($_POST['old_name'] ?? '');
    $new_name = fm_safe_slug($_POST['new_name'] ?? '');
    if (!$old || !$new_name) fm_send_json(['status'=>'error','message'=>'Имена не указаны']);
    $old_path = $resolved['absolute'].'/'.$old;
    if (is_file($old_path)) {
        $old_ext = pathinfo($old, PATHINFO_EXTENSION);
        $new_ext = pathinfo($new_name, PATHINFO_EXTENSION);
        if ($old_ext && strtolower($old_ext) !== strtolower($new_ext)) {
            $new_name .= '.' . $old_ext;
        }
    }
    $new_path = $resolved['absolute'].'/'.$new_name;
    if (file_exists($new_path)) fm_send_json(['status'=>'error','message'=>'Уже существует']);
    if (rename($old_path, $new_path)) fm_send_json(['status'=>'success','message'=>'Переименовано']);
    fm_send_json(['status'=>'error','message'=>'Ошибка']);
}

// === MOVE ===
if ($action === 'move') {
    $src = fm_resolve_path($uploads_root, $_POST['from_path'] ?? '');
    $dst = fm_resolve_path($uploads_root, $_POST['to_path'] ?? '');
    if (!$src || !$dst) fm_send_json(['status'=>'error','message'=>'Путь не найден']);
    $name = basename($_POST['name'] ?? '');
    if (!$name) fm_send_json(['status'=>'error','message'=>'Имя не указано']);
    $src_path = $src['absolute'].'/'.$name;
    $dst_path = $dst['absolute'].'/'.$name;
    if (!file_exists($src_path)) fm_send_json(['status'=>'error','message'=>'Источник не найден']);
    if (file_exists($dst_path)) fm_send_json(['status'=>'error','message'=>'Уже существует']);
    if (rename($src_path, $dst_path)) fm_send_json(['status'=>'success','message'=>'Перемещено']);
    fm_send_json(['status'=>'error','message'=>'Ошибка перемещения']);
}

// === COPY ===
if ($action === 'copy') {
    $src = fm_resolve_path($uploads_root, $_POST['from_path'] ?? '');
    $dst = fm_resolve_path($uploads_root, $_POST['to_path'] ?? '');
    if (!$src || !$dst) fm_send_json(['status'=>'error','message'=>'Путь не найден']);
    $name = basename($_POST['name'] ?? '');
    if (!$name) fm_send_json(['status'=>'error','message'=>'Имя не указано']);
    $src_path = $src['absolute'].'/'.$name;
    $dst_path = $dst['absolute'].'/'.$name;
    if (!file_exists($src_path)) fm_send_json(['status'=>'error','message'=>'Не найден']);
    if (file_exists($dst_path)) fm_send_json(['status'=>'error','message'=>'Уже существует']);
    fm_rcopy($src_path, $dst_path);
    fm_send_json(['status'=>'success','message'=>'Скопировано']);
}

// === ZIP ===
if ($action === 'zip') {
    $resolved = fm_resolve_path($uploads_root, $_POST['path'] ?? '');
    if (!$resolved) fm_send_json(['status'=>'error','message'=>'Путь не найден']);
    $names = $_POST['names'] ?? [];
    if (empty($names)) fm_send_json(['status'=>'error','message'=>'Не выбраны файлы']);
    $zip_name = fm_safe_slug($_POST['zip_name'] ?? 'archive');
    if (empty($zip_name)) $zip_name = 'archive';
    $zip_name .= '.zip';
    $zip_path = $resolved['absolute'].'/'.$zip_name;
    if (file_exists($zip_path)) fm_send_json(['status'=>'error','message'=>'Архив уже существует']);
    if (!class_exists('ZipArchive')) fm_send_json(['status'=>'error','message'=>'ZipArchive не установлен']);
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE) !== true) fm_send_json(['status'=>'error','message'=>'Не удалось создать архив']);
    foreach ($names as $name) {
        $name = basename($name);
        $full = $resolved['absolute'].'/'.$name;
        if (file_exists($full)) {
            if (is_dir($full)) {
                fm_add_dir_to_zip($zip, $full, $name);
            } else {
                $zip->addFile($full, $name);
            }
        }
    }
    $zip->close();
    fm_send_json(['status'=>'success','message'=>'Архив создан: '.$zip_name]);
}

// === UNZIP ===
if ($action === 'unzip') {
    $resolved = fm_resolve_path($uploads_root, $_POST['path'] ?? '');
    if (!$resolved) fm_send_json(['status'=>'error','message'=>'Путь не найден']);
    $name = basename($_POST['name'] ?? '');
    if (!$name) fm_send_json(['status'=>'error','message'=>'Имя не указано']);
    $zip_path = $resolved['absolute'].'/'.$name;
    if (!file_exists($zip_path) || !is_file($zip_path)) fm_send_json(['status'=>'error','message'=>'Файл не найден']);
    if (!class_exists('ZipArchive')) fm_send_json(['status'=>'error','message'=>'ZipArchive не установлен']);
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) fm_send_json(['status'=>'error','message'=>'Не удалось открыть архив']);
    $extract_to = $resolved['absolute'].'/'.pathinfo($name, PATHINFO_FILENAME);
    if (!is_dir($extract_to)) mkdir($extract_to, 0755, true);
    $zip->extractTo($extract_to);
    $zip->close();
    fm_send_json(['status'=>'success','message'=>'Распаковано в: '.pathinfo($name, PATHINFO_FILENAME)]);
}

// === READ ===
if ($action === 'read') {
    $resolved = fm_resolve_path($uploads_root, $_GET['path'] ?? '');
    if (!$resolved) fm_send_json(['status'=>'error','message'=>'Путь не найден']);
    $name = basename($_GET['name'] ?? '');
    if (!$name) fm_send_json(['status'=>'error','message'=>'Имя не указано']);
    $file_path = $resolved['absolute'].'/'.$name;
    if (!file_exists($file_path) || !is_file($file_path)) fm_send_json(['status'=>'error','message'=>'Файл не найден']);
    if (!fm_is_text($name)) fm_send_json(['status'=>'error','message'=>'Можно читать только текстовые файлы']);
    $content = file_get_contents($file_path);
    fm_send_json(['status'=>'success','content'=>$content,'name'=>$name,'ext'=>strtolower(pathinfo($name,PATHINFO_EXTENSION))]);
}

// === SAVE ===
if ($action === 'save') {
    $resolved = fm_resolve_path($uploads_root, $_POST['path'] ?? '');
    if (!$resolved) fm_send_json(['status'=>'error','message'=>'Путь не найден']);
    $name = basename($_POST['name'] ?? '');
    $content = $_POST['content'] ?? '';
    if (!$name) fm_send_json(['status'=>'error','message'=>'Имя не указано']);
    $file_path = $resolved['absolute'].'/'.$name;
    if (file_put_contents($file_path, $content) !== false) fm_send_json(['status'=>'success','message'=>'Файл сохранён']);
    fm_send_json(['status'=>'error','message'=>'Ошибка сохранения']);
}

// === GET URL (returns direct public URL of a file) ===
if ($action === 'get_url') {
    $resolved = fm_resolve_path($uploads_root, $_GET['path'] ?? '');
    if (!$resolved) fm_send_json(['status'=>'error','message'=>'Путь не найден']);
    $name = basename($_GET['name'] ?? '');
    if (!$name) fm_send_json(['status'=>'error','message'=>'Имя не указано']);
    $file_path = $resolved['absolute'].'/'.$name;
    if (!file_exists($file_path)) fm_send_json(['status'=>'error','message'=>'Файл не найден']);
    // Build absolute URL with host
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script_dir = trim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/api_filemanager.php')), '/');
    // Strip /admin from script dir to get site root
    if (strpos($script_dir, 'admin') !== false) {
        $script_dir = substr($script_dir, 0, strpos($script_dir, 'admin'));
        $script_dir = rtrim($script_dir, '/');
    }
    $base_url = $scheme . '://' . $host . ($script_dir ? '/' . $script_dir : '');
    // resolved['url'] is like '/uploads/path/file'
    $full_url = $base_url . $resolved['url'] . ($resolved['relative'] ? '/' : '') . $name;
    if ($resolved['root_name'] === 'aicms_root') {
        $full_url = $base_url . '/' . $name;
    }
    // For uploads root, also include the path
    $relative_path = $resolved['relative'] ? $resolved['relative'] . '/' . $name : $name;
    if ($resolved['root_name'] === 'uploads') {
        $full_url = $base_url . '/uploads/' . $relative_path;
    } elseif ($resolved['root_name'] === 'aicms_root') {
        $full_url = $base_url . '/' . $relative_path;
    } else {
        $full_url = $base_url . '/' . $resolved['root_name'] . '/' . $relative_path;
    }
    fm_send_json([
        'status' => 'success',
        'url' => $full_url,
        'relative_path' => $relative_path,
        'root' => $resolved['root_name'],
        'is_image' => fm_is_img($name),
        'is_video' => fm_is_vid($name),
        'size' => filesize($file_path),
        'size_formatted' => fm_fmt_size(filesize($file_path)),
        'modified' => date('Y-m-d H:i:s', filemtime($file_path)),
    ]);
}

// === DIRECT DOWNLOAD (single file, not ZIP) ===
if ($action === 'download_file') {
    $resolved = fm_resolve_path($uploads_root, $_GET['path'] ?? '');
    if (!$resolved) fm_send_json(['status'=>'error','message'=>'Путь не найден']);
    $name = basename($_GET['name'] ?? '');
    if (!$name) fm_send_json(['status'=>'error','message'=>'Имя не указано']);
    $file_path = $resolved['absolute'].'/'.$name;
    if (!file_exists($file_path) || !is_file($file_path)) fm_send_json(['status'=>'error','message'=>'Файл не найден']);
    // Stream the file
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: no-cache');
    readfile($file_path);
    exit;
}

fm_send_json(['status'=>'error','message'=>'Неизвестное действие: '.$action]);
