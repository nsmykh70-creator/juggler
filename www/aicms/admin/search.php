<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/db.php';
require_auth();

$query = trim($_GET['q'] ?? '');
$results = [];
$total_by_type = [];

if (strlen($query) >= 2) {
    // Search logic is implemented inline below (lines 24-111).
    // We do NOT include api_search.php here because that file outputs
    // JSON and would cause "headers already sent" errors when we try
    // to render the HTML page below.
}

// --- Inline search logic (kept here for standalone page rendering) ---
if (strlen($query) >= 2) {
    $q_lower = mb_strtolower($query);
    $limit = 100;

    function _sp_snippet($content, $q) {
        if (empty($content)) return '';
        $text = trim(strip_tags($content));
        $text = preg_replace('/\s+/', ' ', $text);
        if (empty($text)) return '';
        $pos = mb_stripos($text, $q);
        if ($pos === false) return mb_substr($text, 0, 160) . (mb_strlen($text) > 160 ? '…' : '');
        $start = max(0, $pos - 80);
        $snippet = ($start > 0 ? '… ' : '') . mb_substr($text, $start, 200) . (mb_strlen($text) > $start + 200 ? '…' : '');
        return preg_replace('/(' . preg_quote($q, '/') . ')/i', '<mark>$1</mark>', htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8'));
    }

    // Pages
    foreach (SUPPORTED_LANGS as $lang_info) {
        $pages = read_content('pages.json', [], $lang_info['code']);
        foreach ($pages as $p) {
            $match = mb_strpos(mb_strtolower($p['title'] ?? ''), $q_lower) !== false
                  || mb_strpos(mb_strtolower($p['slug'] ?? ''), $q_lower) !== false
                  || mb_strpos(mb_strtolower(strip_tags($p['content'] ?? '')), $q_lower) !== false;
            if ($match) {
                $results[] = ['type'=>'page','type_label'=>t('page_type') . ' (' . $lang_info['code'] . ')','icon'=>'fa-file-alt','id'=>$p['id'],'title'=>$p['title'] ?: $p['slug'],'lang'=>$lang_info['code'],'url'=>'edit_page.php?id='.$p['id'].'&lang='.$lang_info['code'],'snippet'=>_sp_snippet($p['content'] ?? '', $query)];
                $total_by_type['page'] = ($total_by_type['page'] ?? 0) + 1;
            }
        }
    }

    // News
    foreach (SUPPORTED_LANGS as $lang_info) {
        $news = read_content('news.json', [], $lang_info['code']);
        foreach ($news as $n) {
            $match = mb_strpos(mb_strtolower($n['title'] ?? ''), $q_lower) !== false
                  || mb_strpos(mb_strtolower($n['tags'] ?? ''), $q_lower) !== false
                  || mb_strpos(mb_strtolower(strip_tags($n['content'] ?? '')), $q_lower) !== false;
            if ($match) {
                $results[] = ['type'=>'news','type_label'=>t('news_type') . ' (' . $lang_info['code'] . ')','icon'=>'fa-newspaper','id'=>$n['id'],'title'=>$n['title'],'lang'=>$lang_info['code'],'url'=>'edit_news.php?id='.$n['id'],'snippet'=>_sp_snippet($n['content'] ?? '', $query)];
                $total_by_type['news'] = ($total_by_type['news'] ?? 0) + 1;
            }
        }
    }

    // Modules
    foreach (glob(__DIR__ . '/../modules/*.php') as $mod_path) {
        $name = basename($mod_path, '.php');
        if (mb_strpos(mb_strtolower($name), $q_lower) !== false) {
            $results[] = ['type'=>'module','type_label'=>t('module_type'),'icon'=>'fa-puzzle-piece','title'=>$name,'url'=>'edit_module.php?module='.$name,'snippet'=>t('shortcode_label') . ' {{module:' . $name . '}}'];
            $total_by_type['module'] = ($total_by_type['module'] ?? 0) + 1;
        }
    }

    // DB-backed
    if (db_available()) {
        try {
            foreach (db_select('shop_products', 'id, title, sku, description', '', [], 'title ASC', 200) as $p) {
                $match = mb_strpos(mb_strtolower($p['title']), $q_lower) !== false
                      || mb_strpos(mb_strtolower($p['sku'] ?? ''), $q_lower) !== false
                      || mb_strpos(mb_strtolower($p['description'] ?? ''), $q_lower) !== false;
                if ($match) {
                    $results[] = ['type'=>'product','type_label'=>t('product_type') ?? 'Товар','icon'=>'fa-box','title'=>$p['title'],'url'=>'shop.php?tab=products&edit='.$p['id'],'snippet'=>_sp_snippet($p['description'] ?? '', $query)];
                    $total_by_type['product'] = ($total_by_type['product'] ?? 0) + 1;
                }
            }
        } catch (Exception $e) {}
        try {
            foreach (db_select('users', 'id, username, email, display_name', '', [], 'username ASC', 200) as $u) {
                $match = mb_strpos(mb_strtolower($u['username']), $q_lower) !== false
                      || mb_strpos(mb_strtolower($u['email']), $q_lower) !== false
                      || mb_strpos(mb_strtolower($u['display_name'] ?? ''), $q_lower) !== false;
                if ($match) {
                    $results[] = ['type'=>'user','type_label'=>t('user_type') ?? 'Пользователь','icon'=>'fa-user','title'=>$u['display_name'] ?: $u['username'],'url'=>'users.php?edit='.$u['id'],'snippet'=>'@'.$u['username'].' · '.$u['email']];
                    $total_by_type['user'] = ($total_by_type['user'] ?? 0) + 1;
                }
            }
        } catch (Exception $e) {}
    }

    // Sort: title matches first
    usort($results, function($a, $b) use ($q_lower) {
        $a_t = mb_strpos(mb_strtolower($a['title']), $q_lower) !== false;
        $b_t = mb_strpos(mb_strtolower($b['title']), $q_lower) !== false;
        if ($a_t && !$b_t) return -1;
        if (!$a_t && $b_t) return 1;
        return strcmp($a['title'], $b['title']);
    });
}

$page_title = t('h_search');
$breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('h_search')]];
ob_start();
?>
<form method="GET" class="flex gap-2 max-w-xl flex-1">
    <input type="text" name="q" class="flex-1 h-10 px-4 border border-slate-300 rounded-lg text-base focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" value="<?= h($query) ?>" placeholder="<?= t('search_ph') ?>" autofocus>
    <button type="submit" class="aicms-icon-btn aicms-icon-btn--primary"><i class="fas fa-search"></i><span class="btn-label"><?= t('search_btn') ?></span></button>
</form>
<?php $action_panel = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8"><title><?= t('title_search') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
    <style>
        .search-result-card { transition: all 0.15s; }
        .search-result-card:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(0,0,0,0.06); }
        .search-result-card mark { background: #fef9c3; color: #854d0e; padding: 0 2px; border-radius: 2px; font-weight: 600; }
        .search-filters button { transition: all 0.15s; }
        .search-filters button.active { background: #2563eb; color: #fff; border-color: #2563eb; }
    </style>
</head>
<body class="bg-slate-100">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php include __DIR__ . '/core/topbar.php'; ?>
        <div class="p-6 flex-1">

        <?php if (strlen($query) >= 2): ?>
        <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
            <p class="text-sm text-slate-500 m-0">
                <?= t('search_results') ?>
                <strong class="text-slate-700"><?= count($results) ?></strong>
                <?= t('search_by_query') ?>«<span class="text-slate-700 font-medium"><?= h($query) ?></span>»
            </p>
            <?php if (!empty($total_by_type)): ?>
            <div class="search-filters flex gap-1 flex-wrap" id="searchFilters">
                <button class="aicms-btn aicms-btn--ghost aicms-btn--sm active" data-filter="all">Все (<?= count($results) ?>)</button>
                <?php foreach ($total_by_type as $type => $count): ?>
                <button class="aicms-btn aicms-btn--ghost aicms-btn--sm" data-filter="<?= h($type) ?>"><?= h($type) ?> (<?= $count ?>)</button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if (empty($results)): ?>
        <div class="bg-blue-50 text-blue-700 rounded-lg p-4 flex items-center gap-2">
            <i class="fas fa-info-circle"></i> <?= t('search_no_results') ?>
        </div>
        <?php else: ?>
        <div class="space-y-2" id="searchResults">
            <?php foreach ($results as $r): ?>
            <a href="<?= h($r['url']) ?>" class="search-result-card block bg-white rounded-lg shadow-sm p-4 no-underline group" data-type="<?= h($r['type']) ?>">
                <div class="flex justify-between items-start gap-3">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1 flex-wrap">
                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full
                                <?= $r['type']==='page'?'bg-blue-100 text-blue-800':
                                  ($r['type']==='news'?'bg-green-100 text-green-800':
                                  ($r['type']==='product'?'bg-purple-100 text-purple-800':
                                  ($r['type']==='user'?'bg-pink-100 text-pink-800':'bg-amber-100 text-amber-800'))) ?>">
                                <i class="fas <?= h($r['icon']) ?>"></i>
                                <?= h($r['type_label']) ?>
                            </span>
                            <strong class="text-sm text-slate-800 group-hover:text-blue-600"><?= h($r['title']) ?></strong>
                            <?php if (!empty($r['lang'])): ?>
                            <span class="text-xs px-1.5 py-0.5 bg-slate-100 text-slate-500 rounded uppercase"><?= h($r['lang']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($r['snippet'])): ?>
                        <div class="text-sm text-slate-500 line-clamp-2"><?= $r['snippet'] ?></div>
                        <?php endif; ?>
                    </div>
                    <i class="fas fa-chevron-right text-slate-300 group-hover:text-blue-500 mt-1"></i>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="bg-blue-50 text-blue-700 rounded-lg p-4 flex items-center gap-2"><i class="fas fa-info-circle"></i> <?= t('search_min_chars') ?></div>
        <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
<script>
// Type filter buttons
document.querySelectorAll('#searchFilters button').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('#searchFilters button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        var filter = btn.dataset.filter;
        document.querySelectorAll('#searchResults a').forEach(function(card) {
            if (filter === 'all' || card.dataset.type === filter) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    });
});
</script>
</body>
</html>
