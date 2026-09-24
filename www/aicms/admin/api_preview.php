<?php
/**
 * AICMS Preview API
 * =================
 * Renders editor content with shortcodes expanded + theme template.
 * Returns JSON: { status: 'success', html: '<full HTML>' }
 */

// Prevent any output before JSON
ob_start();

error_reporting(0);
ini_set('display_errors', '0');

session_start();

// Minimal bootstrap — load config and functions manually
$__cms_root = realpath(__DIR__ . '/..');
$__admin_root = __DIR__;

// Load config
$__config_file = $__cms_root . '/data/config.json';
$__config = [];
if (file_exists($__config_file)) {
    $raw = @file_get_contents($__config_file);
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $__config = $decoded;
    }
}

// Define constants needed by modules
if (!defined('SUPPORTED_LANGS')) {
    define('SUPPORTED_LANGS', $__config['languages'] ?? [['code' => 'ru', 'name' => 'Русский']]);
}
if (!defined('DEFAULT_LANG')) {
    define('DEFAULT_LANG', $__config['default_lang'] ?? 'ru');
}
if (!defined('ADMIN_LANG')) {
    define('ADMIN_LANG', $_SESSION['admin_lang'] ?? DEFAULT_LANG);
}

// Helper functions (minimal set, don't require full functions.php)
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('read_json')) {
    function read_json($file, $default = []) {
        if (!file_exists($file)) return $default;
        $c = @file_get_contents($file);
        if ($c === false) return $default;
        $d = json_decode($c, true);
        return json_last_error() === JSON_ERROR_NONE ? $d : $default;
    }
}

// Read content files (pages.json, news.json) for a given language
if (!function_exists('preview_read_content')) {
    function preview_read_content($filename, $lang = 'ru') {
        $path = realpath(__DIR__ . '/..') . '/data/content/' . $lang . '/' . $filename;
        if (!file_exists($path)) return [];
        $raw = @file_get_contents($path);
        if (!$raw) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}

// CSRF check
$__csrf = $_POST['csrf_token'] ?? '';
$__session_csrf = $_SESSION['csrf_token'] ?? '';
if (empty($__csrf) || $__csrf !== $__session_csrf) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'CSRF mismatch']);
    exit;
}

// Auth check — must be logged in as admin
if (empty($_SESSION['admin'])) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

// Clear buffer and set JSON header
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

// === Main logic ===

$content = $_POST['content'] ?? '';
$lang = $_POST['lang'] ?? ADMIN_LANG;
$page_id = (int)($_POST['page_id'] ?? 0);

if (empty($content)) {
    echo json_encode(['status' => 'success', 'html' => '<p style="color:#94a3b8;text-align:center;padding:40px;">Empty content</p>']);
    exit;
}

// Decode HTML entities
$content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
// Fix image paths
$content = preg_replace('/(src=["\'])(\.\.\/)+/i', '$1/', $content);

// === Set globals for modules ===
$GLOBALS['is_preview'] = true;
$GLOBALS['current_lang'] = $lang;
$GLOBALS['currentPage'] = ['id' => $page_id ?: 1, 'title' => 'Preview', 'slug' => 'preview'];
$GLOBALS['current_news_item'] = ['id' => $page_id ?: 1];
$news_item = $GLOBALS['current_news_item'];
$currentPage = $GLOBALS['currentPage'];

// Load actual page if page_id provided
if ($page_id > 0) {
    foreach (SUPPORTED_LANGS as $li) {
        $pages = preview_read_content('pages.json', $li['code']);
        foreach ($pages as $p) {
            if ($p['id'] === $page_id) {
                $GLOBALS['currentPage'] = $p;
                $GLOBALS['current_news_item'] = ['id' => $page_id];
                $currentPage = $p;
                $news_item = ['id' => $page_id];
                break 2;
            }
        }
    }
}

// Load extensions helper (needed by some modules)
$__ext_file = $__admin_root . '/core/extensions.php';
if (file_exists($__ext_file) && !function_exists('extension_enabled')) {
    require_once $__ext_file;
}
// Load DB helper (needed by comments, shop, forum)
$__db_file = $__admin_root . '/core/db.php';
if (file_exists($__db_file) && !function_exists('db_available')) {
    require_once $__db_file;
}
// Load module settings
$__ms_file = $__admin_root . '/core/module_settings.php';
if (file_exists($__ms_file) && !function_exists('module_get_settings')) {
    require_once $__ms_file;
}
// Load user auth (needed by comments module)
$__ua_file = $__admin_root . '/core/user_auth.php';
if (file_exists($__ua_file) && !function_exists('current_user')) {
    require_once $__ua_file;
}

/**
 * Process shortcodes — expand {{module:name}} and {{zone:name}}
 */
function preview_process_shortcodes($raw) {
    global $news_item, $currentPage, $lang;

    // Widget zones
    $raw = preg_replace_callback('/{{zone:([a-z0-9_]+)}}/', function($m) {
        $zf = realpath(__DIR__ . '/..') . '/data/widget_zones.json';
        if (!file_exists($zf)) return '';
        $zones = json_decode(@file_get_contents($zf), true);
        if (!is_array($zones) || !isset($zones[$m[1]])) return '';
        $out = '';
        foreach ($zones[$m[1]] as $sc) { $out .= preview_process_shortcodes($sc); }
        return $out;
    }, $raw);

    // Module shortcodes
    return preg_replace_callback('/{{module:([a-z0-9_]+)(?:\|([^}]*))?}}/', function($m) use ($news_item, $currentPage, $lang) {
        $mod = $m[1];
        $params_str = $m[2] ?? '';
        $path = realpath(__DIR__ . '/..') . '/modules/' . $mod . '.php';
        if (!file_exists($path)) {
            return '<div style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:12px;border-radius:6px;font-size:13px;margin:8px 0;">⚠️ Module not found: <code>' . htmlspecialchars($mod) . '</code></div>';
        }

        // Parse params
        $params = [];
        if (!empty($params_str)) {
            foreach (explode('|', $params_str) as $pair) {
                $kv = explode('=', $pair, 2);
                if (count($kv) === 2) $params[trim($kv[0])] = trim($kv[1]);
            }
        }
        $GLOBALS['module_inline_params'] = $params;

        ob_start();
        try {
            include $path;
        } catch (Exception $e) {
            ob_end_clean();
            return '<div style="background:#fee2e2;border:1px solid #dc2626;color:#991b1b;padding:12px;border-radius:6px;font-size:13px;margin:8px 0;">Module "' . htmlspecialchars($mod) . '" error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        } catch (Error $e) {
            ob_end_clean();
            return '<div style="background:#fee2e2;border:1px solid #dc2626;color:#991b1b;padding:12px;border-radius:6px;font-size:13px;margin:8px 0;">Module "' . htmlspecialchars($mod) . '" error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        return ob_get_clean();
    }, $raw);
}

// Expand shortcodes in editor content
$rendered = preview_process_shortcodes($content);

// === Build full page using theme template ===
$theme_name = $__config['theme'] ?? 'helios';
$theme_path = $__cms_root . '/themes/' . $theme_name . '/index.html';
$site_url = rtrim($__config['site_url'] ?? 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), '/');

// Load pages for nav
$pages_nav = [];
foreach (SUPPORTED_LANGS as $li) {
    if ($li['code'] === $lang) {
        $all = preview_read_content('pages.json', $li['code']);
        $pages_nav = array_values(array_filter($all, function($p) {
            return !isset($p['published']) || $p['published'] === true;
        }));
        break;
    }
}

// Current lang name
$cur_lang_name = '';
foreach (SUPPORTED_LANGS as $li) {
    if ($li['code'] === $lang) { $cur_lang_name = $li['name']; break; }
}

// Use render_template from parser.php if available
$__parser = $__cms_root . '/themes/parser.php';
if (file_exists($__parser)) {
    require_once $__parser;
}

// Build template data
$data = [
    'site_title' => h($__config['site_title'] ?? 'AICMS'),
    'page_title' => h($currentPage['title'] ?? 'Preview'),
    'page_content' => $rendered,
    'pages' => $pages_nav,
    'current_slug' => $currentPage['slug'] ?? 'preview',
    'currentPage' => $currentPage,
    'current_year' => date('Y'),
    'current_lang' => $lang,
    'current_lang_name' => $cur_lang_name,
    'supported_langs' => SUPPORTED_LANGS,
    'site_description' => h($__config['site_description'] ?? ''),
    'favicon' => h($__config['favicon'] ?? '/favicon.ico'),
    'site_url' => h($site_url),
    'clean_urls' => file_exists($__cms_root . '/.htaccess') ? '1' : '0',
    'default_lang' => $__config['default_lang'] ?? 'ru',
    'canonical_url' => h($site_url . '/?page=' . ($currentPage['slug'] ?? 'preview')),
    'og_title' => h($currentPage['title'] ?? 'Preview'),
    'og_description' => h($__config['site_description'] ?? ''),
    'og_url' => h($site_url),
    'og_type' => 'article',
    'meta_title' => h($currentPage['title'] ?? ''),
    'meta_description' => h($__config['site_description'] ?? ''),
    'site_keywords' => h($__config['site_keywords'] ?? ''),
];

// Render theme template
$full_html = '';
try {
    if (function_exists('render_template') && file_exists($theme_path)) {
        $full_html = render_template($theme_path, $data);
    }
} catch (Exception $e) {
    $full_html = '';
} catch (Error $e) {
    $full_html = '';
}

// Fallback if theme rendering failed
if (empty($full_html)) {
    $full_html = '<!DOCTYPE html><html lang="' . h($lang) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>' . h($currentPage['title'] ?? 'Preview') . '</title></head><body style="font-family:Inter,sans-serif;color:#1e293b;margin:0;padding:16px;">' . $rendered . '</body></html>';
}

// Process shortcodes in the rendered template (for navbar modules like auth_block)
$full_html = preview_process_shortcodes($full_html);

// Inject <base> for relative URLs
$base_tag = '<base href="' . h($site_url . '/') . '">';
$full_html = preg_replace('/(<head[^>]*>)/i', '$1' . $base_tag, $full_html, 1);

// Inject responsive CSS at END of <head>
$responsive_css = '<style id="preview-fix">
html,body{overflow-x:hidden!important;max-width:100%!important;margin:0!important;}
*{box-sizing:border-box!important;}
body>*{max-width:100%!important;overflow-x:hidden!important;}
img,video,canvas,svg,iframe{max-width:100%!important;height:auto!important;}
table{display:block!important;overflow-x:auto!important;max-width:100%!important;}
pre,code{max-width:100%!important;overflow-x:auto!important;word-wrap:break-word!important;}
[style*="grid-template-columns"]{grid-template-columns:1fr!important;}
@media(min-width:600px){[style*="repeat(auto-fit"]{grid-template-columns:repeat(auto-fit,minmax(min(250px,100%),1fr))!important;}}
[style*="display: flex"],[style*="display:flex"]{flex-wrap:wrap!important;}
[style*="width: 768px"],[style*="width: 1024px"],[style*="width: 600px"],[style*="width: 700px"],[style*="width:1200px"],[style*="width: 1200px"]{width:100%!important;max-width:100%!important;}
form input[type="text"],form input[type="email"],form input[type="tel"],form textarea,form select{width:100%!important;box-sizing:border-box!important;}
form [style*="grid-template-columns"],form [style*="display: flex"]{grid-template-columns:1fr!important;flex-direction:column!important;}
.comments-module{max-width:100%!important;width:100%!important;padding:0 4px!important;box-sizing:border-box!important;}
.comments-module form{max-width:100%!important;width:100%!important;box-sizing:border-box!important;padding:1rem!important;}
.comments-module form>div[style*="grid"]{grid-template-columns:1fr!important;}
.comments-module .comment-item{max-width:100%!important;box-sizing:border-box!important;word-break:break-word!important;}
.helios-container{max-width:100%!important;padding:0 12px!important;box-sizing:border-box!important;}
.page-content{padding:16px!important;box-sizing:border-box!important;max-width:100%!important;word-wrap:break-word!important;}
.helios-navbar,.helios-navbar-inner{max-width:100%!important;padding:0 16px!important;overflow-x:hidden!important;}
.helios-brand span{max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.helios-footer,.helios-footer-row{max-width:100%!important;flex-wrap:wrap!important;padding:0 12px!important;box-sizing:border-box!important;}
@media(min-width:1024px){.helios-nav.desktop-only{display:flex!important;}.helios-toggle{display:none!important;}.helios-mobile-menu{display:none!important;}}
@media(max-width:1023px){.helios-nav.desktop-only{display:none!important;}.helios-toggle{display:block!important;}.helios-mobile-menu{display:none!important;}.helios-mobile-menu.is-open{display:flex!important;}}
@media(max-width:500px){.page-content{padding:12px!important;}.page-content h1{font-size:24px!important;}.page-content h2{font-size:20px!important;}[style*="padding: 40px"],[style*="padding: 32px"]{padding:16px!important;}}
</style>';
$full_html = preg_replace('/<\/head>/i', $responsive_css . '</head>', $full_html, 1);

// Inject vanilla JS for hamburger menu (no Alpine.js dependency)
$nav_js = '<script>
(function(){
function init(){
var t=document.querySelector(".helios-toggle"),m=document.querySelector(".helios-mobile-menu");
if(t&&m){
m.removeAttribute("x-show");m.removeAttribute("x-cloak");m.removeAttribute("x-transition");t.removeAttribute("x-cloak");
t.addEventListener("click",function(e){e.preventDefault();e.stopPropagation();var o=m.classList.toggle("is-open");var i=t.querySelector("i");if(i)i.className=o?"fas fa-times":"fas fa-bars";});
m.addEventListener("click",function(e){if(e.target.tagName==="A"){m.classList.remove("is-open");var i=t.querySelector("i");if(i)i.className="fas fa-bars";}});
document.addEventListener("click",function(e){if(!m.classList.contains("is-open"))return;if(!t.contains(e.target)&&!m.contains(e.target)){m.classList.remove("is-open");var i=t.querySelector("i");if(i)i.className="fas fa-bars";}});
}
var lb=document.querySelector(".helios-lang-btn"),lm=document.querySelector(".helios-lang-menu");
if(lb&&lm){lm.removeAttribute("x-show");lm.removeAttribute("x-cloak");lm.style.display="none";lb.addEventListener("click",function(e){e.preventDefault();e.stopPropagation();lm.style.display=lm.style.display==="none"?"block":"none";});document.addEventListener("click",function(e){if(!lb.contains(e.target)&&!lm.contains(e.target))lm.style.display="none";});}
}
if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",init);else init();
})();
</script>';
$full_html = preg_replace('/<\/body>/i', $nav_js . '</body>', $full_html, 1);

echo json_encode([
    'status' => 'success',
    'html' => $full_html,
    'theme' => $theme_name,
]);
