<?php
// security_headers.php removed — CSP was blocking Alpine.js and theme scripts

// --- Базовые функции (чтобы сайт работал без админки) ---
if(!function_exists('h')){function h($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}}
if(!function_exists('load_json_data')){function load_json_data($f,$d=[]){if(!file_exists($f))return $d;$c=@file_get_contents($f);if($c===false)return $d;$data=json_decode($c,true);return json_last_error()===JSON_ERROR_NONE?$data:$d;}}

// --- Настройки и определение языка ---
$config = load_json_data('data/config.json');

// --- Maintenance mode check (must run BEFORE cache_get / session_start) ---
// Admins and logged-in site users bypass maintenance.
require_once __DIR__ . '/admin/core/maintenance.php';

// ===== Ранняя отдача из кэша ДО session_start() =====
// Файловые сессии блокируют файл сессии на весь запрос и сериализуют запросы
// посетителей с одинаковым PHPSESSID. Анонимные посетители (подавляющее
// большинство трафика) теперь обслуживаются из кэша вообще БЕЗ сессии.
// Авторизованные пользователи определяются по маркерной cookie aicms_authed
// (ставится при логине, снимается при логауте) и кэш обходят.
require_once __DIR__ . '/admin/core/cache.php'; // система кэширования
if (!empty($config['cache_enabled'] ?? false) && empty($_COOKIE['aicms_authed'])) {
    $cached = cache_get();
    if ($cached !== null) {
        header('Content-Type: text/html; charset=utf-8');
        header('X-AICMS-Cache: HIT');
        echo $cached;
        exit;
    }
}

// Кэш-промах — запускаем сессию (нужна для CSRF-токенов модулей и форм)
session_start();
require_once __DIR__ . '/themes/parser.php'; // Подключаем шаблонизатор

// Авторизованные пользователи (admin или site user) видят персонализированный контент
$has_session = !empty($_COOKIE['aicms_authed']) || !empty($_SESSION['admin']) || !empty($_SESSION['site_user_id']);
$default_lang = $config['default_lang'] ?? 'ru';
$supported_langs = $config['languages'] ?? [['code' => 'ru', 'name' => 'Русский']];

// Clean URLs: always try to parse path from REQUEST_URI
// Works with .htaccess (RewriteRule ^(.*)$ index.php) or without (fallback to ?page=)
$clean_urls = false;
$_htaccess = __DIR__ . '/.htaccess';
if (file_exists($_htaccess)) {
    $_hta_content = @file_get_contents($_htaccess);
    // Only enable clean URLs if .htaccess actually has RewriteRule
    $clean_urls = (strpos($_hta_content, 'RewriteRule') !== false && strpos($_hta_content, 'RewriteEngine') !== false);
}
$config['clean_urls'] = $clean_urls;

$current_lang = $_GET['lang'] ?? ($_COOKIE['site_lang'] ?? $default_lang);
if(!in_array($current_lang, array_column($supported_langs, 'code'))) { $current_lang = $default_lang; }
setcookie('site_lang', $current_lang, time() + (86400 * 30), "/");

// --- Загрузка контента ---
$pages = load_json_data("data/content/{$current_lang}/pages.json", []);

// Filter out unpublished pages so they don't appear in navigation or direct access.
// Also auto-publish scheduled pages whose publish_at time has arrived.
$now = time();
foreach ($pages as &$p_ref) {
    if (($p_ref['status'] ?? '') === 'scheduled' && !empty($p_ref['publish_at'])) {
        if (strtotime($p_ref['publish_at']) <= $now) {
            $p_ref['status'] = 'published';
            $p_ref['published'] = true;
        }
    }
}
unset($p_ref);
$pages_all = $pages;
$pages = array_values(array_filter($pages, function($p) {
    return !isset($p['published']) || $p['published'] === true;
}));

// Build nested menu structure for themes
// Sort by menu_order, then split into top-level and children
usort($pages, function($a, $b) {
    $oa = (int)($a['menu_order'] ?? 0);
    $ob = (int)($b['menu_order'] ?? 0);
    if ($oa === $ob) return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
    return $oa <=> $ob;
});

// Filter pages that should show in menu
$menu_pages = array_values(array_filter($pages, function($p) {
    return !isset($p['show_in_menu']) || $p['show_in_menu'] !== false;
}));

// Build tree: top-level pages with their children
$menu_tree = [];
$children_map = [];
foreach ($menu_pages as $p) {
    $pid = $p['parent_id'] ?? 0;
    // Check both integer and string parent IDs (custom items use 'custom_N')
    if ($pid === 0 || $pid === '0' || empty($pid)) {
        $menu_tree[] = $p;
    } else {
        $key = is_string($pid) ? $pid : (int)$pid;
        if (!isset($children_map[$key])) $children_map[$key] = [];
        $children_map[$key][] = $p;
    }
}
// Attach children to their parents
foreach ($menu_tree as &$mp) {
    $mp['children'] = $children_map[$mp['id']] ?? [];
    $mp['has_children'] = !empty($mp['children']);
    // If page has children, make it non-clickable (dropdown only)
    if ($mp['has_children']) {
        $mp['menu_url'] = '#';
    } else {
        $mp['menu_url'] = ''; // will use page_url
    }
}
unset($mp);

// Load custom menu items (non-page menu entries)
$_menu_items_file = __DIR__ . '/data/menu_items.json';
$_custom_items = [];
if (file_exists($_menu_items_file)) {
    $_custom_items = json_decode(@file_get_contents($_menu_items_file), true) ?: [];
}
// Merge custom items into menu_tree
foreach ($_custom_items as $ci) {
    $ci_id = 'custom_' . $ci['id'];
    $ci['id'] = $ci_id;
    $ci['slug'] = $ci['url'] ?? '#';
    $ci['children'] = $children_map[$ci_id] ?? [];
    $ci['has_children'] = !empty($ci['children']);
    $ci['menu_url'] = $ci['url'] ?? '#';
    $menu_tree[] = $ci;
}
// Re-sort by menu_order
usort($menu_tree, function($a, $b) {
    return (int)($a['menu_order'] ?? 0) <=> (int)($b['menu_order'] ?? 0);
});
$news_list = load_json_data("data/content/{$current_lang}/news.json", []);

// Filter out unpublished news (published === false) so they don't appear on the site.
// News items without an explicit 'published' field are treated as published (backward compat).
$news_list = array_values(array_filter($news_list, function($item) {
    return !isset($item['published']) || $item['published'] === true;
}));

// Сортируем новости по дате от новых к старым
usort($news_list, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

// --- Определение текущей страницы ---
// Priority 1: $_GET['page'] (old-style ?page=home)
// Priority 2: parse from REQUEST_URI path (clean URLs /home)
$slug = $_GET['page'] ?? '';
if (empty($slug)) {
    // Try to extract slug from URL path
    $request_uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $request_uri = trim($request_uri, '/');
    // Strip language prefix if present (e.g. /en/about → about)
    $lang_codes = array_column($supported_langs, 'code');
    foreach ($lang_codes as $lc) {
        if (strpos($request_uri, $lc . '/') === 0) {
            $request_uri = substr($request_uri, strlen($lc) + 1);
            break;
        }
        if ($request_uri === $lc) { $request_uri = ''; break; }
    }
    // Skip if it looks like a file (has extension)
    if (!empty($request_uri) && strpos($request_uri, '.') === false) {
        $parts = explode('/', $request_uri);
        $slug = $parts[0];
        // /news/article-slug
        if ($slug === 'news' && !empty($parts[1])) { $_GET['article'] = $parts[1]; }
        // /shop/product-slug
        if ($slug === 'shop' && !empty($parts[1]) && $parts[1] !== 'category') { $_GET['shop'] = 'product'; $_GET['slug'] = $parts[1]; }
        // /shop/category/slug
        if ($slug === 'shop' && !empty($parts[1]) && $parts[1] === 'category' && !empty($parts[2])) { $_GET['cat_slug'] = $parts[2]; }
        // /forum/topic/123
        if ($slug === 'forum' && !empty($parts[1]) && $parts[1] === 'topic' && !empty($parts[2])) { $_GET['faction'] = 'topic'; $_GET['id'] = $parts[2]; }
        if ($slug === 'forum' && !empty($parts[1]) && $parts[1] === 'new') { $_GET['faction'] = 'new'; }
    }
}
if (empty($slug)) $slug = $pages[0]['slug'] ?? 'home';
$currentPage = null;

// =================================================================
// [ИСПРАВЛЕННЫЙ БЛОК] Новая логика отображения новостей
// =================================================================
if ($slug === 'news') {
    // Находим основную информацию о странице "Новости" (заголовок и т.д.)
    foreach($pages as $p) {
        if (isset($p['slug']) && $p['slug'] === 'news') {
            $currentPage = $p;
            break;
        }
    }
    // Если страница "Новости" не создана, выдаем ошибку
    if ($currentPage === null) {
        http_response_code(404);
        $currentPage = ['title' => 'Ошибка', 'content' => '<p>Страница новостей не найдена. Создайте ее в админ-панели.</p>', 'slug' => '404'];
    } else {
        // Подключаем виджет, который генерирует HTML для списка новостей
        ob_start();
        // Передаем отсортированный список новостей в виджет
        $news_to_display = $news_list; 
        include __DIR__.'/widgets/latest_news.php';
        // Заменяем содержимое страницы на сгенерированный HTML
        $currentPage['content'] = ob_get_clean();
    }
} else {
    // Логика для всех остальных страниц (осталась без изменений)
    if(!empty($pages)){
        foreach($pages as $p){
            if(isset($p['slug']) && $p['slug'] === $slug){
                $currentPage = $p;
                break;
            }
        }
    }
}
// =================================================================
// [КОНЕЦ ИСПРАВЛЕННОГО БЛОКА]
// =================================================================

// Если страница все еще не найдена, показываем 404
if($currentPage === null){
    http_response_code(404);
    $currentPage = ['title' => '404', 'content' => '<p>Страница не найдена</p>', 'slug' => '404'];
}

// --- Обработка шорткодов и вывод шаблона ---
$theme_name = $config['theme'] ?? 'helios';
// Fallback: if configured theme doesn't exist, use first available theme
if (!is_dir(__DIR__ . '/themes/' . $theme_name) || !file_exists(__DIR__ . '/themes/' . $theme_name . '/index.html')) {
    // Try default themes in order
    $fallback_themes = ['helios', 'default', 'aurora', 'cosmos'];
    $found = false;
    foreach ($fallback_themes as $ft) {
        if (is_dir(__DIR__ . '/themes/' . $ft) && file_exists(__DIR__ . '/themes/' . $ft . '/index.html')) {
            $theme_name = $ft;
            $found = true;
            break;
        }
    }
    // If no fallback found, try ANY theme
    if (!$found) {
        $dirs = glob(__DIR__ . '/themes/*', GLOB_ONLYDIR);
        if ($dirs) {
            foreach ($dirs as $d) {
                $bn = basename($d);
                if (strpos($bn, '_') !== 0 && file_exists($d . '/index.html')) {
                    $theme_name = $bn;
                    break;
                }
            }
        }
    }
}
if(!empty($currentPage['template']) && is_dir(__DIR__ . '/themes/'.$currentPage['template'])){ $theme_name = $currentPage['template']; }
if(isset($_GET['theme_preview']) && isset($_SESSION['admin'])){ $ptn = $_GET['theme_preview']; if(preg_match('/^[a-z0-9_]+$/',$ptn) && is_dir(__DIR__ . '/themes/'.$ptn)){$theme_name = $ptn;}}

function process_shortcodes($raw_content) {
    // Process widget zones first: {{zone:sidebar}} → render assigned modules
    $raw_content = preg_replace_callback('/{{zone:([a-z0-9_]+)}}/', function($zm) {
        $zone_name = $zm[1];
        $zones_file = __DIR__.'/data/widget_zones.json';
        if (!file_exists($zones_file)) return '';
        $zones = json_decode(file_get_contents($zones_file), true);
        if (!is_array($zones) || !isset($zones[$zone_name])) return '';
        $output = '';
        foreach ($zones[$zone_name] as $module_shortcode) {
            // Recursively process module shortcodes in the zone
            $output .= process_shortcodes($module_shortcode);
        }
        return $output;
    }, $raw_content);

    // Process module shortcodes: {{module:name}} or {{module:name|param=value}}
    return preg_replace_callback('/{{module:([a-z0-9_]+)(?:\|([^}]*))?}}/', function($m) {
        $module_name = $m[1];
        $params_str = $m[2] ?? '';
        $module_path = __DIR__.'/modules/'.$module_name.'.php';
        if(!file_exists($module_path)) return '';

        // Parse inline parameters: param1=value1|param2=value2
        $module_params = [];
        if (!empty($params_str)) {
            $pairs = explode('|', $params_str);
            foreach ($pairs as $pair) {
                $kv = explode('=', $pair, 2);
                if (count($kv) === 2) {
                    $module_params[trim($kv[0])] = trim($kv[1]);
                }
            }
        }
        // Make params available to the module as a global variable
        $GLOBALS['module_inline_params'] = $module_params;

        ob_start();
        include $module_path;
        return ob_get_clean();
    }, $raw_content);
}

$current_lang_name = ''; 
foreach($supported_langs as $lang){ if($lang['code']===$current_lang){ $current_lang_name=$lang['name']; break; }}

// Compute site URL for canonical/sitemap
$_site_url = rtrim($config['site_url'] ?? ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), '/');

$data_for_template = [
    'site_title' => h($config['site_title'] ?? ''),
    'page_title' => h($currentPage['title']),
    'page_content' => process_shortcodes($currentPage['content'] ?? ''),
    'pages' => $pages,
    'menu_tree' => $menu_tree,
    'current_slug' => $slug,
    'currentPage' => $currentPage,
    'current_year' => date('Y'),
    'current_lang' => $current_lang,
    'current_lang_name' => $current_lang_name,
    'supported_langs' => $supported_langs,
    'site_description' => h($config['site_description'] ?? ''),
    'favicon' => h($config['favicon'] ?? '/favicon.ico'),
    'site_url' => h($_site_url),
    'clean_urls' => $clean_urls ? '1' : '0',
    'default_lang' => $default_lang,
    'canonical_url' => h($_site_url . $_SERVER['REQUEST_URI']),
    // SEO: Open Graph meta tags (themes can output these in <head>)
    'og_title' => h($currentPage['meta_title'] ?? $currentPage['title'] ?? ''),
    'og_description' => h($currentPage['meta_description'] ?? $config['site_description'] ?? ''),
    'og_url' => h($_site_url . $_SERVER['REQUEST_URI']),
    'og_type' => ($slug === 'news') ? 'website' : 'article',
    'meta_title' => h($currentPage['meta_title'] ?? ''),
    'meta_description' => h($currentPage['meta_description'] ?? ''),
];

header('Content-Type: text/html; charset=utf-8');
$template_file_path = __DIR__ . "/themes/{$theme_name}/index.html";
$output = render_template($template_file_path, $data_for_template);

// Process shortcodes ({{module:...}}) in the rendered template too,
// so themes can use {{module:auth_block}} in the navbar etc.
$output = process_shortcodes($output);

// Inject <base> tag so relative URLs (assets/js/lumina-toggle.js, etc.)
// always resolve to the site root, regardless of the current page URL.
// This is what makes the preview work — the frontend needs it too.
if (!empty($_site_url) && strpos($output, '<base') === false) {
    $base_tag = '<base href="' . h($_site_url . '/') . '">';
    $output = preg_replace('/(<head[^>]*>)/i', '$1' . $base_tag, $output, 1);
}

// --- CSS optimizations disabled to prevent theme conflicts ---

// loading="lazy" injection disabled

// --- Prism.js disabled to prevent theme conflicts ---

// --- Dark theme toggle — DISABLED to prevent theme layout conflicts ---
// Dark theme should be implemented per-theme in style.css, not via global CSS injection
// $dark_theme_inject = ... (removed — was breaking theme navigation)
// $dark_toggle_button = ... (removed)

// Save to cache (only for non-logged-in visitors)
if (!empty($config['cache_enabled'] ?? false) && !$has_session) {
    cache_put($output);
}

header('X-AICMS-Cache: MISS');
echo $output;