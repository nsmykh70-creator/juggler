<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/extensions.php';
require_once __DIR__ . '/core/db.php';
require_auth();

// Handle toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $ext_name = $_POST['extension'] ?? '';
    $action = $_POST['action'] ?? '';
    $forum_action = $_POST['forum_action'] ?? '';

    // ===== Forum admin actions =====
    if ($forum_action && db_available()) {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $redirect = 'extensions.php?tab=forum';

        if ($forum_action === 'delete_topic' && $item_id > 0) {
            db_delete('forum_topics', $item_id);
            db_query('DELETE FROM ' . db_table('forum_posts') . ' WHERE topic_id = ?', [$item_id]);
            set_flash_message('Тема удалена.', 'success');
        } elseif ($forum_action === 'pin_topic' && $item_id > 0) {
            $topic = db_find('forum_topics', $item_id);
            if ($topic) { db_update('forum_topics', ['is_pinned' => $topic['is_pinned'] ? 0 : 1], ['id' => $item_id]); set_flash_message($topic['is_pinned'] ? 'Откреплено.' : 'Закреплено.', 'success'); }
        } elseif ($forum_action === 'close_topic' && $item_id > 0) {
            $topic = db_find('forum_topics', $item_id);
            if ($topic) { db_update('forum_topics', ['is_closed' => $topic['is_closed'] ? 0 : 1], ['id' => $item_id]); set_flash_message($topic['is_closed'] ? 'Открыто.' : 'Закрыто.', 'success'); }
        } elseif ($forum_action === 'delete_post' && $item_id > 0) {
            $post = db_find('forum_posts', $item_id);
            if ($post) { db_delete('forum_posts', $item_id); $topic = db_find('forum_topics', $post['topic_id']); if ($topic) { db_update('forum_topics', ['replies_count' => max(0, (int)$topic['replies_count'] - 1)], ['id' => $post['topic_id']]); } set_flash_message('Ответ удалён.', 'success'); }
        } elseif ($forum_action === 'hide_post' && $item_id > 0) {
            $post = db_find('forum_posts', $item_id);
            if ($post) { $ns = $post['status'] === 'visible' ? 'hidden' : 'visible'; db_update('forum_posts', ['status' => $ns], ['id' => $item_id]); set_flash_message($ns === 'hidden' ? 'Скрыто.' : 'Показано.', 'success'); }
        } elseif ($forum_action === 'add_category') {
            $ct = trim($_POST['cat_title'] ?? '');
            if ($ct) { $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $ct)); $mx = 0; foreach (db_select('forum_categories', 'position') as $r) { $mx = max($mx, (int)$r['position']); } db_insert('forum_categories', ['title' => $ct, 'slug' => $slug, 'description' => trim($_POST['cat_desc'] ?? ''), 'position' => $mx + 1]); set_flash_message('Категория создана.', 'success'); }
        } elseif ($forum_action === 'delete_category' && $item_id > 0) {
            db_delete('forum_categories', $item_id);
            set_flash_message('Категория удалена.', 'success');
        }

        // Preserve query params for pagination
        $params = [];
        if (!empty($_GET['fp'])) $params[] = 'fp=' . (int)$_GET['fp'];
        if (!empty($_GET['fq'])) $params[] = 'fq=' . urlencode($_GET['fq']);
        if (!empty($_GET['pp'])) $params[] = 'pp=' . (int)$_GET['pp'];
        if (!empty($_GET['pstatus'])) $params[] = 'pstatus=' . urlencode($_GET['pstatus']);
        if ($params) $redirect .= '&' . implode('&', $params);

        header('Location: ' . $redirect);
        exit;
    }

    if (in_array($ext_name, array_keys(extensions_defaults()))) {
        if ($action === 'enable') {
            extension_set_enabled($ext_name, true);
            // Auto-create DB tables if needed
            if (in_array($ext_name, ['shop', 'comments', 'forum']) && db_available()) {
                db_reset();
                db(); // triggers db_auto_create_tables()
            }
            set_flash_message(str_replace('{$title}', extension_get_config($ext_name)['title'], t('msg_extension_enabled')), 'success');
        } elseif ($action === 'disable') {
            extension_set_enabled($ext_name, false);
            set_flash_message(str_replace('{$title}', extension_get_config($ext_name)['title'], t('msg_extension_disabled')), 'success');
        }
    }
    header('Location: extensions.php'); exit;
}

$extensions = extensions_all();
$db_ok = db_available();

ob_start();
?>
<span class="text-sm text-slate-500 self-center">
    <?= t('extensions_count') ?> <strong class="text-slate-700"><?= count(array_filter($extensions, fn($e) => $e['enabled'])) ?></strong> <?= t('extensions_of') ?> <?= count($extensions) ?>
    · <?= $db_ok ? '<span class="text-green-600">'.t('db_connected').'</span>' : '<span class="text-red-600">'.t('db_not_connected_short').'</span>' ?>
</span>
<?php $action_panel = ob_get_clean();
$page_title = t('h_extensions');
$breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('extensions')]];
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8">
    <title><?= t('title_extensions') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
</head>
<body class="bg-slate-100">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php include __DIR__ . '/core/topbar.php'; ?>
        <div class="p-6 flex-1" x-data="{tab:'<?= isset($_GET['tab']) ? h($_GET['tab']) : 'shop' ?>'}">
            <?php display_flash_message(); ?>

            <!-- Extension tabs -->
            <div class="flex flex-wrap gap-1 mb-4 border-b border-slate-200">
                <?php
                // Compute pending counts for each extension
                $ext_badges = [];
                if (db_available()) {
                    if (extension_enabled('comments')) {
                        $ext_badges['comments'] = db_count('comments', 'status = ?', ['pending']);
                    }
                    if (extension_enabled('forum')) {
                        $ext_badges['forum'] = db_count('forum_posts', 'status = ?', ['hidden']);
                    }
                }
                // Shop: count pending orders
                $_orders_file = __DIR__ . '/../data/shop_orders.json';
                if (file_exists($_orders_file) && extension_enabled('shop')) {
                    $_orders = json_decode(@file_get_contents($_orders_file), true) ?: [];
                    foreach ($_orders as $o) { if (($o['status'] ?? 'new') === 'new') $ext_badges['shop'] = ($ext_badges['shop'] ?? 0) + 1; }
                }
                // Booking: count new bookings
                $_bookings_file = __DIR__ . '/../data/bookings.json';
                if (file_exists($_bookings_file) && extension_enabled('booking')) {
                    $_bookings = json_decode(@file_get_contents($_bookings_file), true) ?: [];
                    foreach ($_bookings as $b) { if (($b['status'] ?? 'new') === 'new') $ext_badges['booking'] = ($ext_badges['booking'] ?? 0) + 1; }
                }
                ?>
                <?php foreach ($extensions as $name => $ext): ?>
                <button @click="tab='<?= $name ?>'" :class="tab==='<?= $name ?>'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-sm font-medium border-b-2 transition-all flex items-center gap-2">
                    <i class="fas <?= $ext['icon'] ?>"></i> <?= h($ext['title']) ?>
                    <?php if ($ext['enabled']): ?>
                    <span class="inline-block w-2 h-2 rounded-full bg-green-500"></span>
                    <?php endif; ?>
                    <?php if (!empty($ext_badges[$name]) && $ext_badges[$name] > 0): ?>
                    <span class="ml-1 inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 text-xs font-bold text-white bg-red-500 rounded-full animate-pulse"><?= $ext_badges[$name] > 99 ? '99+' : $ext_badges[$name] ?></span>
                    <?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($extensions as $name => $ext): ?>
            <div x-show="tab==='<?= $name ?>'" x-cloak class="bg-white rounded-xl shadow-sm p-6">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center text-xl <?= $ext['enabled'] ? 'bg-green-50 text-green-600' : 'bg-slate-100 text-slate-400' ?>">
                            <i class="fas <?= $ext['icon'] ?>"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-slate-800 m-0"><?= h($ext['title']) ?></h3>
                            <p class="text-sm text-slate-500 mt-1 mb-0"><?= h($ext['desc']) ?></p>
                            <div class="mt-2 flex items-center gap-3 text-xs">
                                <span class="<?= $ext['enabled'] ? 'text-green-600' : 'text-slate-400' ?>">
                                    <i class="fas fa-circle text-[8px]"></i>
                                    <?= $ext['enabled'] ? t('enabled_label') : t('disabled_label') ?>
                                </span>
                                <?php if (in_array($name, ['shop','comments','forum'])): ?>
                                <span class="<?= $db_ok ? 'text-green-600' : 'text-red-600' ?>">
                                    <i class="fas fa-database text-[10px]"></i>
                                    <?= $db_ok ? t('db_ready') : t('db_not_ready') ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div>
                        <?php if ($ext['enabled']): ?>
                        <form method="POST" class="inline" onsubmit="return confirm(<?= json_encode(str_replace('{$title}', $ext['title'], t('disable_confirm')), JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_APOS) ?>);">
                            <?= csrf_field() ?>
                            <input type="hidden" name="extension" value="<?= $name ?>">
                            <input type="hidden" name="action" value="disable">
                            <button type="submit" class="aicms-btn aicms-btn--danger aicms-btn--sm" title="<?= t('turn_off') ?>"><i class="fas fa-toggle-off"></i></button>
                        </form>
                        <?php else: ?>
                        <form method="POST" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="extension" value="<?= $name ?>">
                            <input type="hidden" name="action" value="enable">
                            <button type="submit" class="aicms-btn aicms-btn--success aicms-btn--sm" title="<?= t('turn_on') ?>"><i class="fas fa-toggle-on"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <hr class="border-slate-100 my-4">

                <!-- Extension-specific info -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-slate-50 rounded-lg p-4">
                        <h4 class="text-sm font-semibold text-slate-700 mb-2"><i class="fas fa-info-circle text-blue-500 mr-1"></i><?= t('description') ?></h4>
                        <?php if ($name === 'shop'): ?>
                        <p class="text-sm text-slate-600 m-0 mb-2"><?= t('shop_desc') ?></p>
                        <p class="text-xs text-slate-500 m-0"><strong><?= t('shortcode') ?>:</strong> <code class="px-1.5 py-0.5 bg-white rounded text-blue-600">{{module:shop}}</code></p>
                        <?php elseif ($name === 'comments'): ?>
                        <p class="text-sm text-slate-600 m-0 mb-2"><?= t('comments_desc') ?></p>
                        <p class="text-xs text-slate-500 m-0"><strong><?= t('shortcode') ?>:</strong> <code class="px-1.5 py-0.5 bg-white rounded text-blue-600">{{module:comments}}</code> — <?= t('comments_shortcode_hint') ?></p>
                        <?php elseif ($name === 'forum'): ?>
                        <p class="text-sm text-slate-600 m-0 mb-2"><?= t('forum_desc') ?></p>
                        <p class="text-xs text-slate-500 m-0"><strong><?= t('shortcode') ?>:</strong> <code class="px-1.5 py-0.5 bg-white rounded text-blue-600">{{module:forum}}</code> — <?= t('forum_shortcode_hint') ?></p>
                        <?php elseif ($name === 'booking'): ?>
                        <p class="text-sm text-slate-600 m-0 mb-2"><?= t('booking_desc') ?></p>
                        <p class="text-xs text-slate-500 m-0"><strong><?= t('shortcode') ?>:</strong> <code class="px-1.5 py-0.5 bg-white rounded text-blue-600">{{module:booking}}</code> — <?= t('booking_shortcode_hint') ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="bg-slate-50 rounded-lg p-4">
                        <h4 class="text-sm font-semibold text-slate-700 mb-2"><i class="fas fa-link text-blue-500 mr-1"></i><?= t('related_sections') ?></h4>
                        <div class="flex flex-col gap-1.5 text-sm">
                            <?php if ($name === 'shop'): ?>
                            <a href="shop.php" class="text-blue-600 hover:underline"><i class="fas fa-arrow-right text-xs mr-1"></i> <?= t('manage_products') ?></a>
                            <a href="shop_orders.php" class="text-blue-600 hover:underline"><i class="fas fa-clipboard-list text-xs mr-1"></i> Заказы</a>
                            <a href="shop_stock.php" class="text-blue-600 hover:underline"><i class="fas fa-boxes text-xs mr-1"></i> Управление складом</a>
                            <a href="shop_reviews.php" class="text-blue-600 hover:underline"><i class="fas fa-star text-xs mr-1"></i> Отзывы (модерация)</a>
                            <a href="module_settings_admin.php?module=shop" class="text-blue-600 hover:underline"><i class="fas fa-cog text-xs mr-1"></i> <?= t('shop_settings') ?></a>
                            <?php elseif ($name === 'comments'): ?>
                            <a href="comments.php" class="text-blue-600 hover:underline"><i class="fas fa-arrow-right text-xs mr-1"></i> <?= t('comments_moderation') ?></a>
                            <a href="module_settings_admin.php?module=comments" class="text-blue-600 hover:underline"><i class="fas fa-cog text-xs mr-1"></i> <?= t('comments_settings') ?></a>
                            <?php elseif ($name === 'forum'): ?>
                            <a href="forum_admin.php" class="text-blue-600 hover:underline"><i class="fas fa-arrow-right text-xs mr-1"></i> Управление форумом</a>
                            <a href="module_settings_admin.php?module=forum" class="text-blue-600 hover:underline"><i class="fas fa-cog text-xs mr-1"></i> <?= t('forum_settings') ?></a>
                            <?php elseif ($name === 'booking'): ?>
                            <a href="bookings.php" class="text-blue-600 hover:underline"><i class="fas fa-arrow-right text-xs mr-1"></i> Заявки на бронирование</a>
                            <a href="module_settings_admin.php?module=booking" class="text-blue-600 hover:underline"><i class="fas fa-cog text-xs mr-1"></i> <?= t('booking_settings') ?></a>
                            <?php elseif ($name === 'cafe'): ?>
                            <a href="cafe_bookings.php" class="text-blue-600 hover:underline"><i class="fas fa-arrow-right text-xs mr-1"></i> Заявки кафе</a>
                            <a href="module_settings_admin.php?module=cafe" class="text-blue-600 hover:underline"><i class="fas fa-cog text-xs mr-1"></i> Настройки кафе</a>
                            <?php endif; ?>
                            <a href="users.php" class="text-blue-600 hover:underline"><i class="fas fa-users text-xs mr-1"></i> <?= t('site_users_link') ?></a>
                        </div>
                    </div>
                </div>

                <?php if (!$ext['enabled']): ?>
                <div class="mt-4 p-3 bg-amber-50 rounded-lg text-sm text-amber-800">
                    <i class="fas fa-exclamation-triangle mr-1"></i> <?= t('extension_disabled_warn') ?>
                </div>
                <?php elseif (in_array($name, ['shop','comments','forum']) && !$db_ok): ?>
                <div class="mt-4 p-3 bg-red-50 rounded-lg text-sm text-red-800">
                    <i class="fas fa-database mr-1"></i> <a href="settings.php?tab=database" class="underline font-semibold"><?= t('extension_enabled_no_db_warn') ?></a>
                </div>
                <?php endif; ?>

                <?php if ($name === 'forum' && $ext['enabled'] && $db_ok): ?>
                <!-- ===== FORUM ADMIN PANEL (embedded) ===== -->
                <hr class="border-slate-100 my-4">
                <div x-data="{ftab:'topics'}" class="mt-2">
                    <!-- Stats -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                        <?php
                        $f_stats = [
                            'topics' => db_count('forum_topics'),
                            'posts' => db_count('forum_posts'),
                            'categories' => db_count('forum_categories'),
                            'hidden' => db_count('forum_posts', 'status = ?', ['hidden']),
                        ];
                        ?>
                        <div class="bg-blue-50 rounded-lg p-3 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center"><i class="fas fa-list"></i></div>
                            <div><div class="text-xl font-bold text-slate-800"><?= $f_stats['topics'] ?></div><div class="text-xs text-slate-500">Тем</div></div>
                        </div>
                        <div class="bg-green-50 rounded-lg p-3 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-green-100 text-green-600 flex items-center justify-center"><i class="fas fa-comments"></i></div>
                            <div><div class="text-xl font-bold text-slate-800"><?= $f_stats['posts'] ?></div><div class="text-xs text-slate-500">Ответов</div></div>
                        </div>
                        <div class="bg-purple-50 rounded-lg p-3 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-purple-100 text-purple-600 flex items-center justify-center"><i class="fas fa-folder"></i></div>
                            <div><div class="text-xl font-bold text-slate-800"><?= $f_stats['categories'] ?></div><div class="text-xs text-slate-500">Категорий</div></div>
                        </div>
                        <div class="bg-red-50 rounded-lg p-3 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-red-100 text-red-600 flex items-center justify-center"><i class="fas fa-eye-slash"></i></div>
                            <div><div class="text-xl font-bold text-slate-800"><?= $f_stats['hidden'] ?></div><div class="text-xs text-slate-500">Скрытых</div></div>
                        </div>
                    </div>

                    <!-- Sub-tabs -->
                    <div class="flex gap-1 mb-3 border-b border-slate-200">
                        <button @click="ftab='topics'" :class="ftab==='topics'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-sm font-medium border-b-2 transition-all"><i class="fas fa-list mr-1"></i> Темы</button>
                        <button @click="ftab='posts'" :class="ftab==='posts'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-sm font-medium border-b-2 transition-all"><i class="fas fa-comments mr-1"></i> Ответы</button>
                        <button @click="ftab='categories'" :class="ftab==='categories'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-sm font-medium border-b-2 transition-all"><i class="fas fa-folder mr-1"></i> Категории</button>
                    </div>

                    <!-- Topics sub-tab -->
                    <div x-show="ftab==='topics'" x-cloak>
                        <?php
                        $f_page = max(1, (int)($_GET['fp'] ?? 1));
                        $f_per = 15;
                        $f_off = ($f_page - 1) * $f_per;
                        $f_search = trim($_GET['fq'] ?? '');
                        $f_where = $f_search ? 'title LIKE ? OR author_name LIKE ?' : '';
                        $f_params = $f_search ? ['%' . $f_search . '%', '%' . $f_search . '%'] : [];
                        $f_total = db_count('forum_topics', $f_where, $f_params);
                        $f_topics = db_select('forum_topics', '*', $f_where, $f_params, 'is_pinned DESC, created_at DESC', $f_per, $f_off);
                        $f_cats = db_select('forum_categories', '*', '', [], 'position ASC');
                        $f_catmap = [];
                        foreach ($f_cats as $fc) { $f_catmap[$fc['id']] = $fc['title']; }
                        ?>
                        <form method="GET" class="mb-3 flex gap-2">
                            <input type="hidden" name="tab" value="forum">
                            <input type="text" name="fq" value="<?= h($f_search) ?>" placeholder="Поиск тем..." class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm">
                            <button type="submit" class="aicms-btn aicms-btn--primary aicms-btn--sm"><i class="fas fa-search"></i></button>
                            <?php if ($f_search): ?><a href="extensions.php?tab=forum" class="aicms-btn aicms-btn--secondary aicms-btn--sm" title="Сброс"><i class="fas fa-times"></i></a><?php endif; ?>
                        </form>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-slate-200 text-slate-500 text-xs uppercase">
                                    <th class="px-2 py-2 text-left">Тема</th>
                                    <th class="px-2 py-2 text-left">Автор</th>
                                    <th class="px-2 py-2 text-center">Отв.</th>
                                    <th class="px-2 py-2 text-center">Просм.</th>
                                    <th class="px-2 py-2 text-center">Действия</th>
                                </tr></thead>
                                <tbody>
                                <?php if (empty($f_topics)): ?>
                                    <tr><td colspan="5" class="text-center py-6 text-slate-400">Тем нет</td></tr>
                                <?php else: foreach ($f_topics as $ft): ?>
                                    <tr class="border-b border-slate-100 hover:bg-slate-50">
                                        <td class="px-2 py-2">
                                            <?php if ($ft['is_pinned']): ?><i class="fas fa-thumbtack text-amber-500 text-xs"></i><?php endif; ?>
                                            <?php if ($ft['is_closed']): ?><i class="fas fa-lock text-red-500 text-xs"></i><?php endif; ?>
                                            <a href="../?page=forum&faction=topic&id=<?= $ft['id'] ?>" target="_blank" class="text-slate-800 font-medium hover:text-blue-600"><?= h($ft['title']) ?></a>
                                            <?php if (isset($f_catmap[$ft['category_id']]) && $ft['category_id']): ?><span class="ml-1 text-[10px] bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded"><?= h($f_catmap[$ft['category_id']]) ?></span><?php endif; ?>
                                        </td>
                                        <td class="px-2 py-2 text-slate-600"><?= h($ft['author_name']) ?></td>
                                        <td class="px-2 py-2 text-center text-slate-600"><?= (int)$ft['replies_count'] ?></td>
                                        <td class="px-2 py-2 text-center text-slate-600"><?= (int)$ft['views'] ?></td>
                                        <td class="px-2 py-2">
                                            <div class="flex justify-center gap-1">
                                                <form method="POST" class="inline"><?= csrf_field() ?>
                                                    <input type="hidden" name="forum_action" value="pin_topic">
                                                    <input type="hidden" name="item_id" value="<?= $ft['id'] ?>">
                                                    <button class="aicms-act-outline aicms-act-outline--warning"><i class="fas fa-thumbtack"></i><span class="btn-label"><?= $ft[is_pinned] ? Открепить : Закрепить ?></span></button>
                                                </form>
                                                <form method="POST" class="inline"><?= csrf_field() ?>
                                                    <input type="hidden" name="forum_action" value="close_topic">
                                                    <input type="hidden" name="item_id" value="<?= $ft['id'] ?>">
                                                    <button class="aicms-act-outline aicms-act-outline--secondary"><i class="fas fa-<?= $ft['is_closed'] ? 'lock-open' : 'lock' ?>"></i><span class="btn-label"><?= $ft[is_closed] ? Открыть : Закрыть ?></span>
                                                </form>
                                                <form method="POST" class="inline" onsubmit="return confirm('Удалить тему?')"><?= csrf_field() ?>
                                                    <input type="hidden" name="forum_action" value="delete_topic">
                                                    <input type="hidden" name="item_id" value="<?= $ft['id'] ?>">
                                                    <button class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-trash"></i><span class="btn-label">Удалить</span></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php
                        $f_pages = max(1, ceil($f_total / $f_per));
                        if ($f_pages > 1):
                        ?>
                        <div class="flex justify-center gap-1 mt-3">
                            <?php for ($i = 1; $i <= $f_pages; $i++): ?>
                            <a href="extensions.php?tab=forum&fp=<?= $i ?><?= $f_search ? '&fq=' . urlencode($f_search) : '' ?>" class="px-3 py-1 text-xs rounded border <?= $i == $f_page ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-200' ?>"><?= $i ?></a>
                            <?php endfor; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Posts sub-tab -->
                    <div x-show="ftab==='posts'" x-cloak>
                        <?php
                        $p_page = max(1, (int)($_GET['pp'] ?? 1));
                        $p_per = 15;
                        $p_off = ($p_page - 1) * $p_per;
                        $p_filter = $_GET['pstatus'] ?? '';
                        $p_where = $p_filter ? 'status = ?' : '';
                        $p_params = $p_filter ? [$p_filter] : [];
                        $p_total = db_count('forum_posts', $p_where, $p_params);
                        $f_posts = db_select('forum_posts', '*', $p_where, $p_params, 'created_at DESC', $p_per, $p_off);
                        $tids = array_unique(array_column($f_posts, 'topic_id'));
                        $tmap = [];
                        if (!empty($tids)) {
                            $ph = implode(',', array_fill(0, count($tids), '?'));
                            foreach (db_query('SELECT id, title FROM ' . db_table('forum_topics') . ' WHERE id IN (' . $ph . ')', $tids) as $td) { $tmap[$td['id']] = $td['title']; }
                        }
                        ?>
                        <form method="GET" class="mb-3 flex gap-2 items-center">
                            <select name="pstatus" class="px-3 py-2 border border-slate-300 rounded-lg text-sm" onchange="this.form.submit()">
                                <option value="">Все ответы</option>
                                <option value="visible" <?= $p_filter === 'visible' ? 'selected' : '' ?>>Видимые</option>
                                <option value="hidden" <?= $p_filter === 'hidden' ? 'selected' : '' ?>>Скрытые</option>
                            </select>
                            <?php if ($p_filter): ?><a href="extensions.php?tab=forum" class="aicms-btn aicms-btn--secondary aicms-btn--sm" title="Сброс"><i class="fas fa-times"></i></a><?php endif; ?>
                        </form>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-slate-200 text-slate-500 text-xs uppercase">
                                    <th class="px-2 py-2 text-left">Текст</th>
                                    <th class="px-2 py-2 text-left">Автор</th>
                                    <th class="px-2 py-2 text-left">Тема</th>
                                    <th class="px-2 py-2 text-center">Статус</th>
                                    <th class="px-2 py-2 text-center">Действия</th>
                                </tr></thead>
                                <tbody>
                                <?php if (empty($f_posts)): ?>
                                    <tr><td colspan="5" class="text-center py-6 text-slate-400">Ответов нет</td></tr>
                                <?php else: foreach ($f_posts as $fp): ?>
                                    <tr class="border-b border-slate-100 hover:bg-slate-50">
                                        <td class="px-2 py-2 text-slate-700 max-w-xs truncate"><?= h(mb_substr($fp['content'], 0, 60)) ?>...</td>
                                        <td class="px-2 py-2 text-slate-600"><?= h($fp['author_name']) ?></td>
                                        <td class="px-2 py-2"><?php if (isset($tmap[$fp['topic_id']])): ?><a href="../?page=forum&faction=topic&id=<?= $fp['topic_id'] ?>" target="_blank" class="text-blue-600 hover:underline"><?= h(mb_substr($tmap[$fp['topic_id']], 0, 30)) ?></a><?php else: ?><span class="text-slate-400">—</span><?php endif; ?></td>
                                        <td class="px-2 py-2 text-center"><?php if ($fp['status'] === 'visible'): ?><span class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700">Видим</span><?php else: ?><span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700">Скрыт</span><?php endif; ?></td>
                                        <td class="px-2 py-2">
                                            <div class="flex justify-center gap-1">
                                                <form method="POST" class="inline"><?= csrf_field() ?>
                                                    <input type="hidden" name="forum_action" value="hide_post">
                                                    <input type="hidden" name="item_id" value="<?= $fp['id'] ?>">
                                                    <button class="aicms-act-outline aicms-act-outline--secondary"><i class="fas fa-<?= $fp['status'] === 'visible' ? 'eye-slash' : 'eye' ?>"></i><span class="btn-label"><?= $fp[status] === visible ? Скрыть : Показать ?></span>
                                                </form>
                                                <form method="POST" class="inline" onsubmit="return confirm('Удалить ответ?')"><?= csrf_field() ?>
                                                    <input type="hidden" name="forum_action" value="delete_post">
                                                    <input type="hidden" name="item_id" value="<?= $fp['id'] ?>">
                                                    <button class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-trash"></i><span class="btn-label">Удалить</span></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php
                        $p_pages = max(1, ceil($p_total / $p_per));
                        if ($p_pages > 1):
                        ?>
                        <div class="flex justify-center gap-1 mt-3">
                            <?php for ($i = 1; $i <= $p_pages; $i++): ?>
                            <a href="extensions.php?tab=forum&pp=<?= $i ?><?= $p_filter ? '&pstatus=' . $p_filter : '' ?>" class="px-3 py-1 text-xs rounded border <?= $i == $p_page ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-200' ?>"><?= $i ?></a>
                            <?php endfor; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Categories sub-tab -->
                    <div x-show="ftab==='categories'" x-cloak>
                        <div class="mb-4 p-4 bg-slate-50 rounded-lg border border-slate-200">
                            <h4 class="font-semibold text-slate-700 mb-2 text-sm">Создать категорию</h4>
                            <form method="POST" class="flex flex-col md:flex-row gap-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="forum_action" value="add_category">
                                <input type="text" name="cat_title" placeholder="Название" required class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm">
                                <input type="text" name="cat_desc" placeholder="Описание" class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm">
                                <button type="submit" class="aicms-btn aicms-btn--primary aicms-btn--sm" title="Создать"><i class="fas fa-plus"></i></button>
                            </form>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-slate-200 text-slate-500 text-xs uppercase">
                                    <th class="px-2 py-2 text-left">Название</th>
                                    <th class="px-2 py-2 text-left">Описание</th>
                                    <th class="px-2 py-2 text-center">Тем</th>
                                    <th class="px-2 py-2 text-center">Действие</th>
                                </tr></thead>
                                <tbody>
                                <?php $f_cats_all = db_select('forum_categories', '*', '', [], 'position ASC'); ?>
                                <?php if (empty($f_cats_all)): ?>
                                    <tr><td colspan="4" class="text-center py-6 text-slate-400">Категорий нет</td></tr>
                                <?php else: foreach ($f_cats_all as $fc): $fc_count = db_count('forum_topics', 'category_id = ?', [$fc['id']]); ?>
                                    <tr class="border-b border-slate-100 hover:bg-slate-50">
                                        <td class="px-2 py-2 font-medium text-slate-800"><?= h($fc['title']) ?></td>
                                        <td class="px-2 py-2 text-slate-600"><?= h($fc['description'] ?? '—') ?></td>
                                        <td class="px-2 py-2 text-center text-slate-600"><?= $fc_count ?></td>
                                        <td class="px-2 py-2 text-center">
                                            <form method="POST" class="inline" onsubmit="return confirm('Удалить категорию?')"><?= csrf_field() ?>
                                                <input type="hidden" name="forum_action" value="delete_category">
                                                <input type="hidden" name="item_id" value="<?= $fc['id'] ?>">
                                                <button class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-trash"></i><span class="btn-label">Удалить</span></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($name === 'booking' && $ext['enabled'] && $db_ok): ?>
                <!-- ===== HOTEL ROOMS MANAGEMENT ===== -->
                <hr class="border-slate-100 my-4">
                <div x-data="{htab:'rooms'}" class="mt-2">
                    <div class="flex gap-1 mb-3 border-b border-slate-200">
                        <button @click="htab='rooms'" :class="htab==='rooms'?'border-blue-600 text-blue-600':'border-transparent text-slate-500'" class="px-4 py-2 text-sm font-medium border-b-2"><i class="fas fa-bed mr-1"></i> Номера</button>
                        <button @click="htab='cats'" :class="htab==='cats'?'border-blue-600 text-blue-600':'border-transparent text-slate-500'" class="px-4 py-2 text-sm font-medium border-b-2"><i class="fas fa-folder mr-1"></i> Категории</button>
                    </div>

                    <!-- Rooms sub-tab -->
                    <div x-show="htab==='rooms'">
                        <?php $h_rooms = db_select('rooms', '*', '', [], 'position ASC'); $h_cats = db_select('room_categories', '*', '', [], 'position ASC'); $h_cm = []; foreach ($h_cats as $hc) { $h_cm[$hc['id']] = $hc['title']; } ?>
                        <div class="mb-4 p-4 bg-slate-50 rounded-lg border border-slate-200">
                            <h4 class="font-semibold text-slate-700 mb-2 text-sm">Добавить номер</h4>
                            <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-2">
                                <?= csrf_field() ?><input type="hidden" name="room_action" value="add_room">
                                <input type="text" name="room_title" placeholder="Название *" required class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
                                <select name="room_category" class="px-3 py-2 border border-slate-300 rounded-lg text-sm"><option value="0">— Без категории —</option><?php foreach ($h_cats as $hc): ?><option value="<?= $hc['id'] ?>"><?= h($hc['title']) ?></option><?php endforeach; ?></select>
                                <input type="number" name="room_price" step="0.01" placeholder="Цена ₽" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
                                <input type="number" name="room_capacity" value="2" min="1" placeholder="Чел." class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
                                <input type="text" name="room_photo" placeholder="Фото URL" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
                                <input type="text" name="room_amenities" placeholder="Удобства (через запятую)" class="px-3 py-2 border border-slate-300 rounded-lg text-sm md:col-span-2">
                                <button type="submit" class="aicms-btn aicms-btn--primary aicms-btn--sm" title="Добавить"><i class="fas fa-plus"></i></button>
                                <textarea name="room_desc" rows="1" placeholder="Описание" class="md:col-span-3 px-3 py-2 border border-slate-300 rounded-lg text-sm"></textarea>
                            </form>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <?php if (empty($h_rooms)): ?><p class="text-slate-400 text-center py-4 col-span-3">Номеров нет</p>
                            <?php else: foreach ($h_rooms as $hr): ?>
                            <div class="border border-slate-200 rounded-lg p-3">
                                <?php if (!empty($hr['photo'])): ?><img src="<?= h($hr['photo']) ?>" class="w-full h-24 object-cover rounded mb-2" onerror="this.style.display='none'"><?php endif; ?>
                                <div class="font-semibold text-sm text-slate-800"><?= h($hr['title']) ?> <span class="text-blue-600"><?= number_format($hr['price'], 0, ',', ' ') ?>₽</span></div>
                                <div class="text-xs text-slate-500"><?= (int)$hr['capacity'] ?> чел. <?php if (isset($h_cm[$hr['category_id']]) && $hr['category_id']): ?>· <?= h($h_cm[$hr['category_id']]) ?><?php endif; ?></div>
                                <?php if (!empty($hr['amenities'])): ?><div class="mt-1"><?php foreach (explode(',', $hr['amenities']) as $am): ?><span class="text-[10px] bg-slate-100 px-1.5 py-0.5 rounded mr-1"><?= h(trim($am)) ?></span><?php endforeach; ?></div><?php endif; ?>
                                <form method="POST" class="inline mt-2" onsubmit="return confirm('Удалить номер?')"><?= csrf_field() ?><input type="hidden" name="room_action" value="delete_room"><input type="hidden" name="item_id" value="<?= $hr['id'] ?>"><button class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-trash"></i><span class="btn-label">Удалить</span></button></form>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>

                    <!-- Categories sub-tab -->
                    <div x-show="htab==='cats'" x-cloak>
                        <div class="mb-4 p-4 bg-slate-50 rounded-lg border border-slate-200">
                            <form method="POST" class="flex gap-2"><?= csrf_field() ?><input type="hidden" name="room_action" value="add_category"><input type="text" name="cat_title" placeholder="Название категории" required class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm"><input type="text" name="cat_desc" placeholder="Описание" class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm"><button class="aicms-btn aicms-btn--primary aicms-btn--sm"><i class="fas fa-plus"></i></button></form>
                        </div>
                        <?php foreach ($h_cats as $hc): $rc = db_count('rooms', 'category_id = ?', [$hc['id']]); ?>
                        <div class="flex items-center justify-between py-2 border-b border-slate-100"><span class="text-sm font-medium"><?= h($hc['title']) ?> <span class="text-xs text-slate-400">(<?= $rc ?> номеров)</span></span><form method="POST" class="inline" onsubmit="return confirm('Удалить?')"><?= csrf_field() ?><input type="hidden" name="room_action" value="delete_category"><input type="hidden" name="item_id" value="<?= $hc['id'] ?>"><button class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-trash"></i><span class="btn-label">Удалить</span></button></form></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($name === 'cafe' && $ext['enabled'] && $db_ok): ?>
                <!-- ===== CAFE TABLES MANAGEMENT ===== -->
                <hr class="border-slate-100 my-4">
                <div class="mt-2">
                    <?php $c_tables = db_select('cafe_tables', '*', '', [], 'position ASC'); ?>
                    <div class="mb-4 p-4 bg-slate-50 rounded-lg border border-slate-200">
                        <h4 class="font-semibold text-slate-700 mb-2 text-sm">Добавить столик</h4>
                        <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-2">
                            <?= csrf_field() ?><input type="hidden" name="cafe_action" value="add_table">
                            <input type="text" name="table_title" placeholder="Название *" required class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
                            <input type="number" name="table_capacity" value="4" min="1" placeholder="Чел." class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
                            <input type="text" name="table_location" placeholder="Зал/этаж" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
                            <input type="text" name="table_photo" placeholder="Фото URL" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
                            <input type="text" name="table_desc" placeholder="Описание" class="md:col-span-3 px-3 py-2 border border-slate-300 rounded-lg text-sm">
                            <button type="submit" class="aicms-btn aicms-btn--primary aicms-btn--sm" title="Добавить"><i class="fas fa-plus"></i></button>
                        </form>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <?php if (empty($c_tables)): ?><p class="text-slate-400 text-center py-4 col-span-4">Столиков нет</p>
                        <?php else: foreach ($c_tables as $ct): ?>
                        <div class="border border-slate-200 rounded-lg p-3">
                            <?php if (!empty($ct['photo'])): ?><img src="<?= h($ct['photo']) ?>" class="w-full h-20 object-cover rounded mb-2" onerror="this.style.display='none'"><?php endif; ?>
                            <div class="font-semibold text-sm text-slate-800"><?= h($ct['title']) ?></div>
                            <div class="text-xs text-slate-500"><?= (int)$ct['capacity'] ?> чел.<?php if (!empty($ct['location'])): ?> · <?= h($ct['location']) ?><?php endif; ?></div>
                            <?php if ($ct['status'] === 'inactive'): ?><span class="text-[10px] text-red-500">Скрыт</span><?php endif; ?>
                            <form method="POST" class="inline mt-2" onsubmit="return confirm('Удалить столик?')"><?= csrf_field() ?><input type="hidden" name="cafe_action" value="delete_table"><input type="hidden" name="item_id" value="<?= $ct['id'] ?>"><button class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-trash"></i><span class="btn-label">Удалить</span></button></form>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
</body>
</html>
