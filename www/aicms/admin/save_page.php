<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/versioning.php';
require_once __DIR__ . '/core/audit.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash_message('Ошибка безопасности.', 'danger'); header('Location: pages.php'); exit;
}

// Handle "Create news page" button — creates a page with slug 'news'
// that automatically displays all published news items.
if (isset($_POST['create_news_page'])) {
    $lang_to_save = ADMIN_LANG;
    if (!in_array($lang_to_save, array_column(SUPPORTED_LANGS, 'code'))) $lang_to_save = 'ru';
    $pages = read_content('pages.json', [], $lang_to_save);
    // Check if news page already exists
    $exists = false;
    foreach ($pages as $p) {
        if (isset($p['slug']) && $p['slug'] === 'news') { $exists = $p['id']; break; }
    }
    if ($exists) {
        set_flash_message('Страница новостей уже существует (ID ' . $exists . ').', 'info');
        header('Location: edit_page.php?id=' . $exists); exit;
    }
    $max_id = empty($pages) ? 0 : max(array_column($pages, 'id'));
    $new_id = $max_id + 1;
    $new_page = [
        'id'         => $new_id,
        'title'      => 'Новости',
        'slug'       => 'news',
        'content'    => '<p>На этой странице автоматически отображаются все опубликованные новости.</p>',
        'template'   => '',
        'custom'     => [],
        'created'    => date('Y-m-d H:i:s'),
        'meta_title' => 'Новости',
        'meta_description' => 'Последние новости сайта',
        'published'  => true,
        'status'     => 'published',
    ];
    $pages[] = $new_page;
    write_content('pages.json', $pages, $lang_to_save);
    require_once __DIR__ . '/core/cache.php';
    cache_clear();
    audit_log('create_page', "id={$new_id} title='Новости' (auto) lang={$lang_to_save}");
    set_flash_message('Страница «Новости» создана! Она автоматически отображает все опубликованные новости. Теперь добавьте её в меню (Меню → перетащите «Новости» в нужное место).', 'success');
    header('Location: edit_page.php?id=' . $new_id); exit;
}

$lang_to_save = $_POST['lang'] ?? ADMIN_LANG;
if (!in_array($lang_to_save, array_column(SUPPORTED_LANGS, 'code'))) { set_flash_message('Недопустимый язык.','danger');header('Location: pages.php');exit; }

$pages = read_content('pages.json', [], $lang_to_save);
$id = (int)($_POST['id'] ?? 0);
$title = trim($_POST['title'] ?? '');
$slug = trim($_POST['slug'] ?? '');
$content = $_POST['content'] ?? '';
$template = trim($_POST['template'] ?? '');
$meta_title = trim($_POST['meta_title'] ?? '');
$meta_description = trim($_POST['meta_description'] ?? '');
// Visibility: a new page is published by default (visible on site).
// The checkbox 'published' controls visibility without deleting the page.
$published = isset($_POST['published']) ? (bool)$_POST['published'] : true;
// Draft/Scheduled: status field extends the published boolean.
// status = 'published' (default) | 'draft' (hidden, work in progress) | 'scheduled' (auto-publish at publish_at)
$status = $_POST['status'] ?? 'published';
$publish_at = trim($_POST['publish_at'] ?? '');
if (!in_array($status, ['published', 'draft', 'scheduled'])) $status = 'published';
// If status is draft or scheduled, force published=false so it doesn't show on site yet
if ($status === 'draft' || $status === 'scheduled') $published = false;
// Menu settings
$parent_id = $_POST['parent_id'] ?? '0';
// Convert numeric strings to int, keep 'custom_N' as string
if (is_numeric($parent_id)) $parent_id = (int)$parent_id;
$show_in_menu = ($_POST['show_in_menu'] ?? '1') !== '0';
$custom_fields = [];
if(isset($_POST['custom_fields']['key'])) { foreach($_POST['custom_fields']['key'] as $i => $key) { $key=trim($key);$val=trim($_POST['custom_fields']['value'][$i]??'');if(!empty($key)){$custom_fields[$key]=$val;}}}
if(empty($title) || empty($slug)){ set_flash_message('Заголовок и slug обязательны.','danger');header('Location: '.($_SERVER['HTTP_REFERER']??'pages.php'));exit;}

$is_edit = false;
$new_page = null;
if($id > 0) {
    $found = false;
    foreach($pages as &$p){ if($p['id'] === $id){
        // Save version snapshot BEFORE overwriting
        version_save('page', $p, $lang_to_save);
        $p['title']=$title;$p['slug']=$slug;$p['content']=$content;$p['template']=$template;
        $p['custom']=$custom_fields;$p['meta_title']=$meta_title;$p['meta_description']=$meta_description;
        $p['published']=$published;$p['status']=$status;
        $p['parent_id']=$parent_id;$p['show_in_menu']=$show_in_menu;
        $p['updated_at'] = date('Y-m-d H:i:s');
        if (!empty($publish_at)) $p['publish_at']=$publish_at; else unset($p['publish_at']);
        $found = true; $is_edit = true; $new_page = $p; break;
    }}
    if (!$found) {
        // ID was passed but page not found — fall through to create logic
        $id = 0;
    }
    if ($found) {
        audit_log('edit_page', "id={$id} title='{$title}' lang={$lang_to_save} status={$status}");
        set_flash_message('Страница обновлена!', 'success');
    }
}
if ($id === 0) {
    $max_id = empty($pages) ? 0 : max(array_column($pages,'id'));
    $new_page = ['id'=>$max_id+1,'title'=>$title,'slug'=>$slug,'content'=>$content,'template'=>$template,'custom'=>$custom_fields,'created'=>date('Y-m-d H:i:s'),'meta_title'=>$meta_title,'meta_description'=>$meta_description,'published'=>$published,'status'=>$status,'parent_id'=>$parent_id,'show_in_menu'=>$show_in_menu,'menu_order'=>count($pages)+1];
    if (!empty($publish_at)) $new_page['publish_at']=$publish_at;
    $pages[] = $new_page; $id = $new_page['id'];
    audit_log('create_page', "id={$id} title='{$title}' lang={$lang_to_save} status={$status}");
    set_flash_message('Страница создана!', 'success');
}
write_content('pages.json', $pages, $lang_to_save);

// Plugin hook: after page is saved
if (function_exists('do_hook') && $new_page) {
    do_hook('after_save_page', $new_page, $lang_to_save);
}

// Очистка кэша — use the new cache system
require_once __DIR__ . '/core/cache.php';
cache_clear();

header('Location: edit_page.php?id=' . $id);
exit;