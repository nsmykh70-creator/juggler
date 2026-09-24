<?php
/**
 * AICMS REST API v1
 * URL: /api/v1/index.php (or /api/v1/ via .htaccess)
 *
 * Endpoints:
 *   GET    /api/v1/pages          — list pages
 *   GET    /api/v1/pages/{id}     — get single page
 *   POST   /api/v1/pages          — create page
 *   PUT    /api/v1/pages/{id}     — update page
 *   DELETE /api/v1/pages/{id}     — delete page
 *   GET    /api/v1/news           — list news
 *   GET    /api/v1/news/{id}      — get single news
 *   POST   /api/v1/news           — create news
 *   DELETE /api/v1/news/{id}      — delete news
 *   GET    /api/v1/products       — list products
 *   GET    /api/v1/products/{id}  — get single product
 *   GET    /api/v1/stats          — site statistics
 */
require_once __DIR__ . '/../../admin/core/functions.php';
require_once __DIR__ . '/../../admin/core/db.php';
require_once __DIR__ . '/../../admin/core/extensions.php';
require_once __DIR__ . '/../../admin/core/rate_limit.php';

// Security headers (REST API: stricter CSP, no framing)
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// Rate limiting: 120 requests per minute per IP
if (!api_rate_limit('api_v1', 120, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['error' => 'Too Many Requests']);
    exit;
}

$config = read_json(__DIR__ . '/../../data/config.json', []);
$api_token = $config['api_token'] ?? '';
if (empty($config['features']['rest_api'])) { http_response_code(403); echo json_encode(['error' => 'REST API disabled']); exit; }
if (empty($api_token)) { http_response_code(403); echo json_encode(['error' => 'No API token configured']); exit; }

// Constant-time token comparison (prevents timing attacks on the API token)
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = preg_match('/Bearer\s+(.+)/', $auth, $m) ? trim($m[1]) : '';
if (!hash_equals($api_token, $token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if (strpos($uri, 'api/v1') === 0) $uri = substr($uri, 7);
$uri = trim($uri, '/');
$parts = $uri ? explode('/', $uri) : [];
$resource = $parts[0] ?? '';
$id = (int)($parts[1] ?? 0);
$lang = $_GET['lang'] ?? ($config['default_lang'] ?? 'ru');

// Validate lang against supported languages (prevents path traversal via lang)
$supported_codes = array_column($config['languages'] ?? [['code'=>'ru']], 'code');
if (!in_array($lang, $supported_codes, true)) $lang = $config['default_lang'] ?? 'ru';

// Read request body with size limit (1 MB max) — prevents DoS via huge JSON payloads
$raw_body = file_get_contents('php://input', false, null, 0, 1024 * 1024);
$body = json_decode($raw_body, true) ?: [];

try {
    switch ($resource) {
    case 'pages':
        $pages = read_content('pages.json', [], $lang);
        if ($method === 'GET' && !$id) { echo json_encode(array_values(array_filter($pages, fn($p)=>!isset($p['published'])||$p['published'])), JSON_UNESCAPED_UNICODE); }
        elseif ($method === 'GET' && $id) { $p=null; foreach($pages as $pg){if((int)$pg['id']===$id){$p=$pg;break;}} echo $p?json_encode($p,JSON_UNESCAPED_UNICODE):json_encode(['error'=>'Not found']); }
        elseif ($method === 'POST') { $mx=empty($pages)?0:max(array_column($pages,'id')); $n=['id'=>$mx+1,'title'=>$body['title']??'','slug'=>$body['slug']??'','content'=>$body['content']??'','published'=>true,'created'=>date('Y-m-d H:i:s')]; $pages[]=$n; write_content('pages.json',$pages,$lang); http_response_code(201); echo json_encode($n,JSON_UNESCAPED_UNICODE); }
        elseif ($method === 'PUT' && $id) { foreach($pages as &$p){if((int)$p['id']===$id){$p=array_merge($p,$body);write_content('pages.json',$pages,$lang);echo json_encode($p,JSON_UNESCAPED_UNICODE);exit;}} echo json_encode(['error'=>'Not found']); }
        elseif ($method === 'DELETE' && $id) { $pages=array_values(array_filter($pages,fn($p)=>(int)$p['id']!==$id)); write_content('pages.json',$pages,$lang); echo json_encode(['deleted'=>true]); }
        break;
    case 'news':
        $news = read_content('news.json', [], $lang);
        if ($method === 'GET' && !$id) { $news=array_values(array_filter($news,fn($n)=>!isset($n['published'])||$n['published'])); usort($news,fn($a,$b)=>strtotime($b['date'])-strtotime($a['date'])); echo json_encode($news,JSON_UNESCAPED_UNICODE); }
        elseif ($method === 'GET' && $id) { $n=null; foreach($news as $nw){if((int)$nw['id']===$id){$n=$nw;break;}} echo $n?json_encode($n,JSON_UNESCAPED_UNICODE):json_encode(['error'=>'Not found']); }
        elseif ($method === 'POST') { $mx=empty($news)?0:max(array_column($news,'id')); $n=['id'=>$mx+1,'title'=>$body['title']??'','content'=>$body['content']??'','date'=>$body['date']??date('Y-m-d'),'published'=>true,'page_slug'=>'news']; $news[]=$n; write_content('news.json',$news,$lang); http_response_code(201); echo json_encode($n,JSON_UNESCAPED_UNICODE); }
        elseif ($method === 'DELETE' && $id) { $news=array_values(array_filter($news,fn($n)=>(int)$n['id']!==$id)); write_content('news.json',$news,$lang); echo json_encode(['deleted'=>true]); }
        break;
    case 'products':
        if (!extension_enabled('shop')||!db_available()) { echo json_encode(['error'=>'Shop not available']); break; }
        if ($method==='GET'&&!$id) { echo json_encode(db_select('shop_products','id,title,slug,price,old_price,stock,status,image,sku,short_desc','status=?',['active'],'created_at DESC',50),JSON_UNESCAPED_UNICODE); }
        elseif ($method==='GET'&&$id) { $p=db_find('shop_products',$id); echo $p?json_encode($p,JSON_UNESCAPED_UNICODE):json_encode(['error'=>'Not found']); }
        break;
    case 'stats':
        $st=['pages'=>count(read_content('pages.json',[],$lang)),'news'=>count(read_content('news.json',[],$lang)),'db'=>db_available()];
        if (db_available()) { try{$st['products']=db_count('shop_products');$st['orders']=db_count('shop_orders');$st['users']=db_count('users');}catch(Exception $e){} }
        echo json_encode($st,JSON_UNESCAPED_UNICODE);
        break;
    default:
        http_response_code(404); echo json_encode(['error'=>'Unknown: '.$resource,'available'=>['pages','news','products','stats']]);
    }
} catch (Exception $e) { http_response_code(500); echo json_encode(['error'=>$e->getMessage()]); }
