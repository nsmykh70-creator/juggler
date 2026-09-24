<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/trash.php';
require_once __DIR__ . '/core/audit.php';
require_auth();

$all_news = read_content('news.json');

// Handle POST actions (categories, bulk ops, reorder)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    // --- Bulk actions (publish / hide / delete) ---
    $bulk_action = $_POST['action'] ?? '';
    if (in_array($bulk_action, ['bulk_publish', 'bulk_hide', 'bulk_delete'], true)) {
        $ids_raw = isset($_POST['ids']) ? explode(',', $_POST['ids']) : [];
        $ids = array_values(array_filter(array_map('intval', $ids_raw), fn($i) => $i > 0));
        if (!empty($ids)) {
            $all_news = read_content('news.json');
            $id_set = array_flip($ids);

            if ($bulk_action === 'bulk_delete') {
                $survivors = [];
                foreach ($all_news as $n) {
                    if (isset($n['id']) && isset($id_set[(int)$n['id']])) {
                        // Move to trash before removing
                        trash_move('news', $n, ADMIN_LANG);
                        audit_log('delete_news', "id={$n['id']} title='" . ($n['title'] ?? '') . "' (bulk moved to trash)");
                    } else {
                        $survivors[] = $n;
                    }
                }
                $all_news = array_values($survivors);
                set_flash_message(str_replace('{$count}', count($ids), t('msg_news_deleted')), 'success');
            } elseif ($bulk_action === 'bulk_publish') {
                foreach ($all_news as &$n) {
                    if (isset($n['id']) && isset($id_set[(int)$n['id']])) {
                        $n['published'] = true;
                        $n['status'] = 'published';
                    }
                }
                unset($n);
                set_flash_message(str_replace('{$count}', count($ids), t('msg_news_published')), 'success');
            } elseif ($bulk_action === 'bulk_hide') {
                foreach ($all_news as &$n) {
                    if (isset($n['id']) && isset($id_set[(int)$n['id']])) {
                        $n['published'] = false;
                        $n['status'] = 'draft';
                    }
                }
                unset($n);
                set_flash_message(str_replace('{$count}', count($ids), t('msg_news_hidden')), 'success');
            }
            write_content('news.json', $all_news);
            require_once __DIR__ . '/core/cache.php'; cache_clear();
            header('Location: news.php'); exit;
        }
        // No valid ids — defensive fallback (JS already prevents this)
        header('Location: news.php'); exit;
    }

    // --- Category create / delete ---
    if (($_POST['form_type'] ?? '') === 'news_cat_save' && db_available()) {
        $cat_title = trim($_POST['cat_title'] ?? '');
        if (!empty($cat_title)) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $cat_title));
            try {
                db_insert('news_categories', ['title' => $cat_title, 'slug' => $slug, 'lang' => ADMIN_LANG, 'position' => 0]);
                set_flash_message(t('msg_news_cat_created'), 'success');
            } catch (Exception $e) { set_flash_message(str_replace('{$message}', $e->getMessage(), t('err_general')), 'danger'); }
        }
        header('Location: news.php'); exit;
    }
    if (($_POST['form_type'] ?? '') === 'news_cat_delete' && db_available()) {
        try { db_delete('news_categories', (int)($_POST['id'] ?? 0)); set_flash_message(t('msg_news_cat_deleted'), 'success'); } catch (Exception $e) {}
        header('Location: news.php'); exit;
    }
    // Handle reorder via AJAX
    if (($_POST['form_type'] ?? '') === 'reorder' && !empty($_POST['order'])) {
        $order = explode(',', $_POST['order']);
        $order = array_map('intval', $order);
        $news = read_content('news.json');
        $reordered = [];
        foreach ($order as $id) { foreach ($news as $n) { if ((int)$n['id'] === $id) { $reordered[] = $n; break; } } }
        foreach ($news as $n) { $found = false; foreach ($reordered as $r) { if ((int)$r['id'] === (int)$n['id']) { $found = true; break; } } if (!$found) $reordered[] = $n; }
        write_content('news.json', $reordered);
        require_once __DIR__ . '/core/cache.php'; cache_clear();
        header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit;
    }
}

// Load news categories
$news_categories = [];
if (db_available()) {
    try { $news_categories = db_select('news_categories', '*', '', [], 'position ASC, title ASC'); } catch (Exception $e) {}
}

ob_start();
?>
<a href="edit_news.php" class="aicms-icon-btn aicms-icon-btn--primary"><i class="fas fa-plus"></i><span class="btn-label"><?= t('add_news_btn') ?></span></a>
<a href="import_news.php" class="aicms-icon-btn aicms-icon-btn--ghost"><i class="fas fa-file-import"></i><span class="btn-label">Импорт из RSS/CSV</span></a>
<span class="ml-auto text-sm text-slate-500 self-center">
    <?= t('news_count_label') ?> <strong class="text-slate-700"><?= count($all_news) ?></strong> · <?= t('categories_count') ?> <strong class="text-slate-700"><?= count($news_categories) ?></strong>
</span>
<?php
$action_panel = ob_get_clean();

$page_title = t('news_management');
$breadcrumb = [
    ['label' => t('dashboard'), 'url' => 'index.php'],
    ['label' => t('news')],
];
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8">
    <title><?= t('title_news') ?> (<?= strtoupper(ADMIN_LANG) ?>)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
</head>
<body class="bg-slate-100">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php include __DIR__ . '/core/topbar.php'; ?>
        <div class="p-6 flex-1">
            <?php display_flash_message(); ?>

            <!-- News categories (compact) -->
            <?php if (!empty($news_categories) || db_available()): ?>
            <div class="bg-white rounded-xl shadow-sm p-4 mb-4">
                <div class="flex items-center gap-2 flex-wrap">
                    <h4 class="text-sm font-semibold text-slate-700 m-0 mr-2"><i class="fas fa-tags text-blue-500"></i> <?= t('news_categories') ?></h4>
                    <?php foreach ($news_categories as $nc):
                        $count = 0;
                        foreach ($all_news as $n) { if (($n['category'] ?? '') === $nc['slug']) $count++; }
                    ?>
                    <span class="inline-flex items-center gap-1 px-2 py-1 bg-blue-50 text-blue-700 rounded-lg text-xs font-medium">
                        <?= h($nc['title']) ?> <span class="text-blue-400">(<?= $count ?>)</span>
                        <form method="POST" class="inline" onsubmit="return confirm('<?= t('delete_category_confirm') ?>');"><?= csrf_field() ?><input type="hidden" name="form_type" value="news_cat_delete"><input type="hidden" name="id" value="<?= $nc['id'] ?>"><button type="submit" class="text-blue-400 hover:text-red-600 ml-1">&times;</button></form>
                    </span>
                    <?php endforeach; ?>
                    <form method="POST" class="inline-flex gap-1"><?= csrf_field() ?><input type="hidden" name="form_type" value="news_cat_save"><input type="text" name="cat_title" placeholder="<?= t('new_category_ph') ?>" class="text-xs px-2 py-1 border border-slate-300 rounded-lg w-32"><button type="submit" class="text-xs px-2 py-1 bg-blue-600 text-white rounded-lg">+</button></form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Bulk action bar (visible only when ≥1 row is selected) -->
            <div class="bg-slate-800 text-white rounded-lg shadow-lg p-3 mb-3 hidden" id="bulkActionBar">
                <div class="flex items-center gap-3 flex-wrap">
                    <span class="text-sm">
                        <i class="fas fa-check-square mr-1"></i>
                        <span id="bulkCount">0</span> <?= t('selected_count') ?>
                    </span>
                    <button type="button" class="aicms-btn aicms-btn--success aicms-btn--sm" onclick="bulkAction('publish')">
                        <i class="fas fa-eye"></i> <?= t('publish') ?>
                    </button>
                    <button type="button" class="aicms-btn aicms-btn--warning aicms-btn--sm" onclick="bulkAction('hide')">
                        <i class="fas fa-eye-slash"></i> <?= t('hide') ?>
                    </button>
                    <button type="button" class="aicms-btn aicms-btn--danger aicms-btn--sm" onclick="bulkAction('delete')">
                        <i class="fas fa-trash"></i> <?= t('delete') ?>
                    </button>
                    <button type="button" class="aicms-btn aicms-btn--secondary aicms-btn--sm ml-auto" onclick="clearSelection()">
                        <i class="fas fa-times"></i> <?= t('clear_selection') ?>
                    </button>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <?php if (!empty($all_news)): ?>
                <div class="px-4 py-2 border-b border-slate-100 flex items-center gap-2 flex-wrap">
                    <span class="text-xs text-slate-400 ml-auto"><i class="fas fa-grip-vertical"></i> <?= t('drag_sort_hint') ?></span>
                </div>
                <?php endif; ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50">
                                <th class="px-4 py-3 w-8"><input type="checkbox" id="select-all-checkbox" class="w-4 h-4 rounded" onclick="selectAll(this.checked)"></th>
                                <th class="px-4 py-3 w-8"></th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider"><?= t('status') ?></th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider"><?= t('title') ?></th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider"><?= t('category') ?></th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider"><?= t('date') ?></th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider"><?= t('actions') ?></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <?php if (empty($all_news)): ?>
                            <tr>
                                <td colspan="8" class="px-4 py-12 text-center text-slate-400">
                                    <i class="fas fa-newspaper text-4xl text-slate-300 mb-3 block"></i>
                                    <?= t('no_news_short') ?><br>
                                    <?= t('no_news_hint') ?>
                                </td>
                            </tr>
                            <?php else: foreach ($all_news as $item): ?>
                            <tr class="hover:bg-slate-50 transition-colors aicms-row-actions <?= (!isset($item['published']) || $item['published'] === true) ? '' : 'bg-amber-50' ?>" data-id="<?= $item['id'] ?>">
                                <td class="px-4 py-3"><input type="checkbox" class="row-checkbox w-4 h-4 rounded" value="<?= (int)$item['id'] ?>" onchange="toggleRow(<?= (int)$item['id'] ?>, this.checked)"></td>
                                <td class="px-2 py-3 text-center text-slate-300 hover:text-slate-500 drag-handle" title="<?= t('drag_sort_hint') ?>" style="cursor:grab;"><i class="fas fa-grip-vertical"></i></td>
                                <td class="px-4 py-3 text-sm text-slate-500 font-mono"><?= $item['id'] ?></td>
                                <td class="px-4 py-3">
                                    <?php if (!isset($item['published']) || $item['published'] === true): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-800"><i class="fas fa-circle text-[6px]"></i> <?= t('publ_short') ?></span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold rounded-full bg-slate-200 text-slate-600"><i class="fas fa-circle text-[6px]"></i> <?= t('hidden') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <a href="edit_news.php?id=<?= $item['id'] ?>" class="text-sm font-medium text-slate-800 hover:text-blue-600 no-underline"><?= h($item['title']) ?></a>
                                    <?php if (!empty($item['tags'])): ?><br><small class="text-xs text-slate-400"><?= h($item['tags']) ?></small><?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <?php if (!empty($item['category'])): ?>
                                    <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-blue-100 text-blue-800"><?= h($item['category']) ?></span>
                                    <?php else: ?>
                                    <span class="text-slate-300 text-xs">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-500"><?= isset($item['date']) ? date('d.m.Y', strtotime($item['date'])) : '-' ?></td>
                                <td class="px-4 py-3">
                                    <div class="aicms-table-actions">
                                        <form action="toggle_news_status.php" method="POST" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                            <?php if (!isset($item['published']) || $item['published'] === true): ?>
                                                <button type="submit" class="aicms-act-outline aicms-act-outline--warning"><i class="fas fa-eye-slash"></i><span class="btn-label"><?= t('hide') ?></span></button>
                                            <?php else: ?>
                                                <button type="submit" class="aicms-act-outline aicms-act-outline--success"><i class="fas fa-eye"></i><span class="btn-label"><?= t('publish') ?></span></button>
                                            <?php endif; ?>
                                        </form>
                                        <a href="edit_news.php?id=<?= $item['id'] ?>" class="aicms-act-outline aicms-act-outline--primary" ><i class="fas fa-edit"></i><span class="btn-label"><?= t('edit') ?></span></a>
                                        <form action="delete_news.php" method="POST" class="inline" onsubmit="return confirm('<?= t('confirm_delete') ?>');">
                                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $item['id'] ?>">
                                            <button type="submit" class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-trash"></i><span class="btn-label"><?= t('delete') ?></span></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Hidden form used to submit bulk actions -->
<form id="bulkForm" method="POST" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="">
    <input type="hidden" name="ids" value="">
</form>

<script src="assets/js/aicms-ui.js?v=20260812"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
// ---- Bulk selection state ----
var selectedIds = new Set();
function updateBulkBar() {
    var bar = document.getElementById('bulkActionBar');
    var count = selectedIds.size;
    if (!bar) return;
    bar.classList.toggle('hidden', count === 0);
    var countEl = document.getElementById('bulkCount');
    if (countEl) countEl.textContent = count;
    var sa = document.getElementById('select-all-checkbox');
    if (sa) {
        var visible = document.querySelectorAll('.row-checkbox').length;
        sa.checked = (visible > 0 && count === visible);
    }
}
function toggleRow(id, checked) {
    id = parseInt(id, 10);
    if (checked) selectedIds.add(id); else selectedIds.delete(id);
    updateBulkBar();
}
function selectAll(checked) {
    document.querySelectorAll('.row-checkbox').forEach(function(cb) {
        cb.checked = checked;
        var id = parseInt(cb.value, 10);
        if (checked) selectedIds.add(id); else selectedIds.delete(id);
    });
    updateBulkBar();
}
function clearSelection() {
    selectedIds.clear();
    document.querySelectorAll('.row-checkbox').forEach(function(cb) { cb.checked = false; });
    var sa = document.getElementById('select-all-checkbox');
    if (sa) sa.checked = false;
    updateBulkBar();
}
function bulkAction(action) {
    if (selectedIds.size === 0) return;
    if (action === 'delete' && !confirm('<?= t('are_you_sure') ?>')) return;
    var form = document.getElementById('bulkForm');
    form.querySelector('[name=action]').value = 'bulk_' + action;
    form.querySelector('[name=ids]').value = Array.from(selectedIds).join(',');
    form.submit();
}
</script>
<script>
// ---- Drag-and-drop sorting (preserved) ----
var tbody = document.querySelector('tbody.divide-y');
if (tbody) {
    Sortable.create(tbody, {
        handle: '.drag-handle',
        animation: 150,
        ghostClass: 'bg-blue-50',
        onEnd: function() {
            var ids = [];
            tbody.querySelectorAll('tr[data-id]').forEach(function(tr) { ids.push(tr.dataset.id); });
            var fd = new FormData();
            fd.append('form_type', 'reorder');
            fd.append('order', ids.join(','));
            fd.append('csrf_token', '<?= csrf_field_value() ?>');
            fetch('news.php', { method: 'POST', body: fd }).then(function(r) { return r.json(); }).then(function(d) { if(d.ok) { /* toast */ } }).catch(function(e) {});
        }
    });
}
</script>
</body>
</html>
