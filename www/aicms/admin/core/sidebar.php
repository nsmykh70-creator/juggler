<?php $current_page = basename($_SERVER['PHP_SELF']); ?>

<?php
// Load extensions to show/hide conditional items
require_once __DIR__ . '/extensions.php';
require_once __DIR__ . '/db.php';
$_ext_all = extensions_all();

// Count pending comments for notification badge
$_pending_comments = 0;
$_pending_bookings = 0;
$_pending_submissions = 0;
if (extension_enabled('comments') && db_available()) {
    try { $_pending_comments = db_count('comments', 'status = ?', ['pending']); } catch (Exception $e) {}
}
// Count new bookings
$_bookings_file = __DIR__ . '/../../data/bookings.json';
if (file_exists($_bookings_file)) {
    $_bookings = json_decode(file_get_contents($_bookings_file), true) ?: [];
    foreach ($_bookings as $b) {
        if (($b['status'] ?? 'new') === 'new') $_pending_bookings++;
    }
}
// Count new contact form submissions
$_submissions_file = __DIR__ . '/../../data/contact_submissions.json';
if (file_exists($_submissions_file)) {
    $_submissions = json_decode(file_get_contents($_submissions_file), true) ?: [];
    foreach ($_submissions as $sub) {
        if (($sub['status'] ?? 'new') === 'new') $_pending_submissions++;
    }
}

// Count low-stock products for sidebar badge
$_low_stock_count = 0;
if (extension_enabled('shop') && db_available()) {
    try {
        $_all_shop_products = db_select('shop_products', 'stock,low_stock_threshold,status', 'status = ?', ['active']);
        foreach ($_all_shop_products as $_p) {
            $_thr = (int)($_p['low_stock_threshold'] ?? 5);
            if ((int)$_p['stock'] <= $_thr) $_low_stock_count++;
        }
    } catch (Exception $e) {}
}

// Count pending reviews
$_pending_reviews = 0;
if (extension_enabled('shop') && db_available()) {
    try { $_pending_reviews = db_count('shop_reviews', 'status = ?', ['pending']); } catch (Exception $e) {}
}

// Count pending cafe bookings
$_pending_cafe = 0;
$_cafe_file = __DIR__ . '/../../data/cafe_bookings.json';
if (file_exists($_cafe_file)) {
    $_cafe_bookings = json_decode(file_get_contents($_cafe_file), true) ?: [];
    foreach ($_cafe_bookings as $cb) {
        if (($cb['status'] ?? 'new') === 'new') $_pending_cafe++;
    }
}

// Count new shop orders (JSON file)
$_pending_orders = 0;
$_orders_file = __DIR__ . '/../../data/shop_orders.json';
if (file_exists($_orders_file)) {
    $_orders_list = json_decode(file_get_contents($_orders_file), true) ?: [];
    foreach ($_orders_list as $o) {
        if (($o['status'] ?? 'new') === 'new') $_pending_orders++;
    }
}

// Group sidebar items into logical sections
$sidebar_sections = [
    t('section_content') => [
        ['url'=>'index.php',         'icon'=>'fa-tachometer-alt', 'label'=>t('dashboard'),  'active'=>$current_page==='index.php'],
        ['url'=>'pages.php',         'icon'=>'fa-file-alt',       'label'=>t('pages'),      'active'=>in_array($current_page,['pages.php','edit_page.php','page_history.php'])],
        ['url'=>'news.php',          'icon'=>'fa-newspaper',      'label'=>t('news'),       'active'=>in_array($current_page,['news.php','edit_news.php'])],
    ],
    t('section_extensions') => [
        ['url'=>'extensions.php',          'icon'=>'fa-puzzle-piece',    'label'=>t('extensions'),    'active'=>$current_page==='extensions.php'],
        ['url'=>'contact_submissions.php', 'icon'=>'fa-envelope',        'label'=>t('messages'),     'active'=>$current_page==='contact_submissions.php', 'badge'=>$_pending_submissions],
        ['url'=>'shop.php',                'icon'=>'fa-shopping-bag',    'label'=>'Магазин',         'active'=>in_array($current_page,['shop.php','shop_orders.php','shop_stock.php','shop_reviews.php']), 'ext'=>'shop'],
        ['url'=>'shop_orders.php',         'icon'=>'fa-clipboard-list',  'label'=>'Заказы',          'active'=>$current_page==='shop_orders.php', 'badge'=>$_pending_orders, 'ext'=>'shop'],
        ['url'=>'shop_stock.php',          'icon'=>'fa-boxes',           'label'=>'Склад',           'active'=>$current_page==='shop_stock.php', 'badge'=>$_low_stock_count, 'ext'=>'shop'],
        ['url'=>'shop_reviews.php',        'icon'=>'fa-star',            'label'=>'Отзывы',          'active'=>$current_page==='shop_reviews.php', 'badge'=>$_pending_reviews, 'ext'=>'shop'],
        ['url'=>'bookings.php',            'icon'=>'fa-hotel',           'label'=>'Бронирование',    'active'=>$current_page==='bookings.php', 'badge'=>$_pending_bookings, 'ext'=>'booking'],
        ['url'=>'cafe_bookings.php',       'icon'=>'fa-utensils',        'label'=>'Кафе',            'active'=>$current_page==='cafe_bookings.php', 'badge'=>$_pending_cafe, 'ext'=>'cafe'],
        ['url'=>'comments.php',            'icon'=>'fa-comments',        'label'=>'Комментарии',     'active'=>$current_page==='comments.php', 'badge'=>$_pending_comments, 'ext'=>'comments'],
        ['url'=>'forum_admin.php',         'icon'=>'fa-users',           'label'=>'Форум',           'active'=>$current_page==='forum_admin.php', 'ext'=>'forum'],
    ],
    t('section_structure') => [
        ['url'=>'media.php',         'icon'=>'fa-photo-video',    'label'=>t('media'),      'active'=>in_array($current_page,['media.php'])],
        ['url'=>'filemanager.php',   'icon'=>'fa-folder-open',     'label'=>t('files'),      'active'=>$current_page==='filemanager.php'],
        ['url'=>'menu.php',          'icon'=>'fa-bars',           'label'=>t('menu'),       'active'=>$current_page==='menu.php'],
        ['url'=>'modules.php',       'icon'=>'fa-cubes',          'label'=>t('modules'),    'active'=>in_array($current_page,['modules.php','edit_module.php','module_settings_admin.php'])],
        ['url'=>'widget_zones.php',  'icon'=>'fa-th-large',       'label'=>t('widget_zones'), 'active'=>$current_page==='widget_zones.php'],
    ],
    t('section_design') => [
        ['url'=>'themes.php',        'icon'=>'fa-palette',        'label'=>t('themes'),     'active'=>in_array($current_page,['themes.php','templates.php'])],
    ],
    t('section_system') => [
        ['url'=>'users.php',         'icon'=>'fa-users',          'label'=>t('users'),      'active'=>$current_page==='users.php'],
        ['url'=>'audit.php',         'icon'=>'fa-clipboard-list', 'label'=>t('audit_log'),  'active'=>$current_page==='audit.php'],
        ['url'=>'backups.php',       'icon'=>'fa-history',        'label'=>t('backups'),    'active'=>in_array($current_page,['backups.php','restore.php'])],
        ['url'=>'trash.php',         'icon'=>'fa-trash-restore',  'label'=>t('trash'),       'active'=>$current_page==='trash.php'],
        ['url'=>'settings.php',      'icon'=>'fa-cogs',           'label'=>t('settings'),   'active'=>in_array($current_page,['settings.php','localization.php'])],
    ],
];
?>
<?php if ($_pending_comments > 0 || $_pending_submissions > 0 || $_pending_bookings > 0 || $_pending_cafe > 0 || $_pending_orders > 0): ?>
<!-- Pending comments notification (read by aicms-ui.js toast) -->
<meta name="aicms-pending-comments" content="<?= (int)$_pending_comments ?>">
<meta name="aicms-pending-bookings" content="<?= (int)$_pending_bookings ?>">
<meta name="aicms-pending-submissions" content="<?= (int)$_pending_submissions ?>">
<meta name="aicms-pending-cafe" content="<?= (int)$_pending_cafe ?>">
<meta name="aicms-pending-orders" content="<?= (int)$_pending_orders ?>">
<?php endif; ?>
<!-- CSRF token for AJAX polling (used by aicms-ui.js on all admin pages) -->
<meta name="aicms-csrf-token" content="<?= csrf_field_value() ?>">
<nav id="sidebar" class="bg-slate-800 text-white">
    <!-- Logo + collapse -->
    <div class="flex items-center justify-between px-4 py-4 border-b border-slate-700/50">
        <a href="index.php" class="flex items-center gap-2.5 text-white no-decoration">
            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-blue-900/30 shrink-0">
                <i class="fas fa-cube text-white text-base"></i>
            </div>
            <span class="sidebar-logo-text text-lg font-bold tracking-tight">AICMS</span>
        </a>
        <button onclick="window.toggleSidebar()" class="hidden lg:flex w-8 h-8 items-center justify-center rounded-md text-slate-400 hover:bg-slate-700 hover:text-white transition" >
            <i class="fas fa-bars text-sm"></i>
        </button>
        <button onclick="window.closeMobileSidebar()" class="lg:hidden w-8 h-8 flex items-center justify-center rounded-md text-slate-400 hover:bg-slate-700 hover:text-white transition" >
            <i class="fas fa-times text-sm"></i>
        </button>
    </div>

    <!-- Language switcher -->
    <?php $_cur_lang_name = strtoupper(ADMIN_LANG);
    foreach (SUPPORTED_LANGS as $_l) { if (($_l['code'] ?? '') === ADMIN_LANG) { $_cur_lang_name = $_l['name'] ?? strtoupper(ADMIN_LANG); break; } } ?>
    <div class="sidebar-lang-switcher px-3 pt-3 relative" x-data="{open:false}">
        <button type="button" class="w-full flex items-center gap-1.5 text-xs text-slate-300 border border-slate-600 rounded-lg px-2 py-2 hover:bg-slate-700 hover:text-white transition" @click="open=!open" aria-haspopup="listbox" :aria-expanded="open">
            <i class="fas fa-globe"></i>
            <span class="flex-1 text-left truncate"><?= h($_cur_lang_name) ?></span>
            <i class="fas fa-chevron-down text-[10px] transition-transform" :class="open ? 'rotate-180' : ''"></i>
        </button>
        <div class="sidebar-lang-panel absolute top-full mt-1 left-0 right-0 rounded-lg shadow-lg py-1 z-50" x-show="open" @click.outside="open=false" x-cloak>
            <?php foreach (SUPPORTED_LANGS as $lang): ?>
            <a class="sidebar-lang-item block px-3 py-1.5 text-sm text-slate-200 no-underline" :class="'<?= h($lang['code']) ?>' === '<?= ADMIN_LANG ?>' ? 'sidebar-lang-active text-white' : ''" href="?set_lang=<?= h($lang['code']) ?>"><?= h($lang['name']) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Navigation -->
    <ul class="flex flex-col list-none p-0 m-0 px-2 pb-2 flex-1 overflow-y-auto" id="sidebar-nav">
        <?php foreach ($sidebar_sections as $section_title => $items): ?>
        <li class="sidebar-section" data-section="<?= h($section_title) ?>">
            <div class="sidebar-section-title"><?= h($section_title) ?></div>
            <ul class="list-none p-0 m-0">
                <?php foreach ($items as $item):
                    // Skip if extension item is disabled
                    if (!empty($item['ext']) && empty($_ext_all[$item['ext']]['enabled'])) continue;
                    // Compute badge count for this item
                    $badge = $item['badge'] ?? 0;
                    if ($item['url'] === 'extensions.php') {
                        $badge = $_pending_comments + $_pending_bookings;
                    }
                ?>
                <li class="sidebar-item-wrap" data-label="<?= h(mb_strtolower($item['label'])) ?>">
                    <a href="<?= $item['url'] ?>" class="sidebar-item <?= $item['active']?'active':'' ?>">
                        <i class="fas <?= $item['icon'] ?>"></i>
                        <span class="sidebar-label"><?= h($item['label']) ?></span>
                        <?php if (!empty($badge) && $badge > 0): ?>
                        <span class="ml-auto text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-red-500 text-white animate-pulse"><?= $badge > 99 ? '99+' : $badge ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- Footer -->
    <div class="mt-auto p-2 border-t border-slate-700/50">
        <div class="sidebar-user-info mb-2">
            <div class="w-9 h-9 rounded-full bg-gradient-to-br from-slate-500 to-slate-700 flex items-center justify-center text-white font-bold shrink-0">
                <?= h(mb_substr($_SESSION['admin'] ?? 'A', 0, 1, 'UTF-8')) ?>
            </div>
            <div class="sidebar-user-info-text min-w-0 flex-1">
                <div class="text-sm font-semibold text-white truncate"><?= h($_SESSION['admin']) ?></div>
                <div class="text-[11px] text-slate-400"><?= t('administrator') ?></div>
            </div>
        </div>
        <form method="POST" action="logout.php" style="margin:0;">
            <?= csrf_field() ?>
            <button type="submit" class="w-full flex items-center justify-center gap-1.5 h-9 px-3 rounded-lg bg-red-600 hover:bg-red-700 text-white no-underline text-xs font-semibold transition" style="border:none;cursor:pointer;">
                <i class="fas fa-sign-out-alt"></i>
                <span class="sidebar-label"><?= t('logout') ?></span>
            </button>
        </form>
    </div>
</nav>

<!-- Mobile overlay -->
<div class="sidebar-overlay" id="sidebar-overlay" onclick="window.closeMobileSidebar()"></div>

<script>
// Sidebar collapse/expand (desktop)
(function() {
    try {
        var saved = localStorage.getItem('aicms_sidebar_collapsed');
        if (saved === '1') document.getElementById('sidebar').classList.add('collapsed');
    } catch(e) {}
})();

window.toggleSidebar = function() {
    var s = document.getElementById('sidebar');
    s.classList.toggle('collapsed');
    try {
        localStorage.setItem('aicms_sidebar_collapsed', s.classList.contains('collapsed') ? '1' : '0');
    } catch(e) {}
};

// Mobile sidebar
window.openMobileSidebar = function() {
    var s = document.getElementById('sidebar');
    var o = document.getElementById('sidebar-overlay');
    s.classList.add('is-mobile-open');
    if (o) o.classList.add('is-visible');
    document.body.style.overflow = 'hidden';
};
window.closeMobileSidebar = function() {
    var s = document.getElementById('sidebar');
    var o = document.getElementById('sidebar-overlay');
    s.classList.remove('is-mobile-open');
    if (o) o.classList.remove('is-visible');
    document.body.style.overflow = '';
};

// Sidebar search filter
window.filterSidebar = function(q) {
    q = q.trim().toLowerCase();
    var sections = document.querySelectorAll('#sidebar-nav .sidebar-section');
    sections.forEach(function(sec) {
        var items = sec.querySelectorAll('.sidebar-item-wrap');
        var visibleCount = 0;
        items.forEach(function(it) {
            var lbl = it.getAttribute('data-label') || '';
            if (!q || lbl.indexOf(q) !== -1) {
                it.style.display = '';
                visibleCount++;
            } else {
                it.style.display = 'none';
            }
        });
        // Hide section title if no items visible
        sec.style.display = visibleCount ? '' : 'none';
    });
};
</script>
