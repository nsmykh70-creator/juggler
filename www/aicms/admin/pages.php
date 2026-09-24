<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/trash.php';
require_once __DIR__ . '/core/audit.php';
require_auth();

// Handle bulk actions (publish / hide / delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['bulk_publish', 'bulk_hide', 'bulk_delete'], true)) {
        $ids_raw = isset($_POST['ids']) ? explode(',', $_POST['ids']) : [];
        $ids = array_values(array_filter(array_map('intval', $ids_raw), fn($i) => $i > 0));
        if (!empty($ids)) {
            $all_pages = read_content('pages.json');
            $id_set = array_flip($ids);

            if ($action === 'bulk_delete') {
                $survivors = [];
                foreach ($all_pages as $p) {
                    if (isset($p['id']) && isset($id_set[(int)$p['id']])) {
                        // Move to trash before removing
                        trash_move('page', $p, ADMIN_LANG);
                        audit_log('delete_page', "id={$p['id']} title='" . ($p['title'] ?? '') . "' lang=" . ADMIN_LANG . " (bulk moved to trash)");
                    } else {
                        $survivors[] = $p;
                    }
                }
                $all_pages = array_values($survivors);
                set_flash_message(str_replace('{$count}', count($ids), t('msg_pages_deleted')), 'success');
            } elseif ($action === 'bulk_publish') {
                foreach ($all_pages as &$p) {
                    if (isset($p['id']) && isset($id_set[(int)$p['id']])) {
                        $p['published'] = true;
                        $p['status'] = 'published';
                    }
                }
                unset($p);
                set_flash_message(str_replace('{$count}', count($ids), t('msg_pages_published')), 'success');
            } elseif ($action === 'bulk_hide') {
                foreach ($all_pages as &$p) {
                    if (isset($p['id']) && isset($id_set[(int)$p['id']])) {
                        $p['published'] = false;
                        $p['status'] = 'draft';
                    }
                }
                unset($p);
                set_flash_message(str_replace('{$count}', count($ids), t('msg_pages_hidden')), 'success');
            }
            write_content('pages.json', $all_pages);
            require_once __DIR__ . '/core/cache.php'; cache_clear();
            header('Location: pages.php'); exit;
        }
        // No valid ids — defensive fallback (JS already prevents this)
        header('Location: pages.php'); exit;
    }
    // Handle reorder via AJAX
    if (($_POST['form_type'] ?? '') === 'reorder' && !empty($_POST['order'])) {
        $order = explode(',', $_POST['order']);
        $order = array_map('intval', $order);
        $pages = read_content('pages.json');
        $reordered = [];
        foreach ($order as $id) { foreach ($pages as $p) { if ((int)$p['id'] === $id) { $reordered[] = $p; break; } } }
        // Add any pages not in order at the end
        foreach ($pages as $p) { $found = false; foreach ($reordered as $r) { if ((int)$r['id'] === (int)$p['id']) { $found = true; break; } } if (!$found) $reordered[] = $p; }
        write_content('pages.json', $reordered);
        require_once __DIR__ . '/core/cache.php'; cache_clear();
        header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit;
    }
}

$all_pages = read_content('pages.json');

// Build action panel HTML for the topbar
ob_start();
?>
<a href="edit_page.php" class="aicms-icon-btn aicms-icon-btn--primary"><i class="fas fa-plus"></i><span class="btn-label"><?= t('create_page_btn') ?></span></a>
<form method="POST" class="inline">
    <?= csrf_field() ?>
    <button type="submit" name="create_news_page" class="aicms-icon-btn aicms-icon-btn--info"><i class="fas fa-newspaper"></i><span class="btn-label"><?= t('page_news_link') ?></span></button>
</form>
<a href="pages.php?clear_cache=true&csrf_token=<?= csrf_field_value() ?>" class="aicms-icon-btn aicms-icon-btn--ghost" " onclick="return confirm('<?= t('confirm_cache_clear') ?>');"><i class="fas fa-broom"></i><span class="btn-label"><?= t('clear_cache') ?></span></a>
<a href="export_import.php?action=export" class="aicms-icon-btn aicms-icon-btn--ghost"><i class="fas fa-download"></i><span class="btn-label">Экспорт</span></a>
<span class="ml-auto text-sm text-slate-500 self-center">
    <?= t('total_pages') ?> <strong class="text-slate-700"><?= count($all_pages) ?></strong>
</span>
<?php
$action_panel = ob_get_clean();

$page_title = t('manage_pages');
$breadcrumb = [
    ['label' => t('dashboard'), 'url' => 'index.php'],
    ['label' => t('manage_pages')],
];
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8">
    <title><?= t('manage_pages') ?> (<?= strtoupper(ADMIN_LANG) ?>) — AICMS</title>
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
                <?php if (!empty($all_pages)): ?>
                <div class="px-4 py-2 border-b border-slate-100 flex items-center gap-2 flex-wrap">
                    <span class="text-xs text-slate-400 ml-auto"><i class="fas fa-arrows-alt"></i> <?= t('drag_sort_hint') ?></span>
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
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Slug</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider"><?= t('date') ?></th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider"><?= t('actions') ?></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <?php if (empty($all_pages)): ?>
                            <tr>
                                <td colspan="8" class="px-4 py-12 text-center text-slate-400">
                                    <i class="fas fa-file-alt text-4xl text-slate-300 mb-3 block"></i>
                                    <?= t('no_pages_short') ?><br>
                                    <?= t('no_pages_hint') ?>
                                </td>
                            </tr>
                            <?php else: foreach ($all_pages as $page): ?>
                            <tr class="hover:bg-slate-50 transition-colors aicms-row-actions <?= (!isset($page['published']) || $page['published'] === true) ? '' : 'bg-amber-50' ?>" data-id="<?= $page['id'] ?>">
                                <td class="px-4 py-3"><input type="checkbox" class="row-checkbox w-4 h-4 rounded" value="<?= (int)$page['id'] ?>" onchange="toggleRow(<?= (int)$page['id'] ?>, this.checked)"></td>
                                <td class="px-2 py-3 text-center text-slate-300 hover:text-slate-500 drag-handle" title="<?= t('drag_sort_hint') ?>" style="cursor:grab;"><i class="fas fa-grip-vertical"></i></td>
                                <td class="px-4 py-3 text-sm text-slate-500 font-mono"><?= $page['id'] ?></td>
                                <td class="px-4 py-3">
                                    <?php if (!isset($page['published']) || $page['published'] === true): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-800"><i class="fas fa-circle text-[6px]"></i> <?= t('publ_short') ?></span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold rounded-full bg-slate-200 text-slate-600"><i class="fas fa-circle text-[6px]"></i> <?= t('hidden') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <a href="edit_page.php?id=<?= $page['id'] ?>" class="text-sm font-medium text-slate-800 hover:text-blue-600 no-underline"><?= h($page['title']) ?></a>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 text-xs font-mono bg-slate-100 text-slate-600 rounded"><?= h($page['slug']) ?></span>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-500"><?= isset($page['created']) ? date('d.m.Y', strtotime($page['created'])) : '-' ?></td>
                                <td class="px-4 py-3">
                                    <div class="aicms-table-actions">
                                        <form action="toggle_page_status.php" method="POST" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $page['id'] ?>">
                                            <?php if (!isset($page['published']) || $page['published'] === true): ?>
                                                <button type="submit" class="aicms-act-outline aicms-act-outline--warning"><i class="fas fa-eye-slash"></i><span class="btn-label"><?= t('hide') ?></span></button>
                                            <?php else: ?>
                                                <button type="submit" class="aicms-act-outline aicms-act-outline--success"><i class="fas fa-eye"></i><span class="btn-label"><?= t('publish') ?></span></button>
                                            <?php endif; ?>
                                        </form>
                                        <a href="../?lang=<?= ADMIN_LANG ?>&page=<?= h($page['slug'])?>" target="_blank" class="aicms-act-outline aicms-act-outline--info" ><i class="fa fa-external-link-alt"></i><span class="btn-label"><?= t('open_on_site') ?></span></a>
                                        <a href="edit_page.php?id=<?= $page['id'] ?>" class="aicms-act-outline aicms-act-outline--primary" ><i class="fas fa-edit"></i><span class="btn-label"><?= t('edit') ?></span></a>
                                        <form action="delete_page.php" method="POST" class="inline" onsubmit="return confirm('<?= t('are_you_sure') ?>');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $page['id'] ?>">
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
    // Keep "select all" checkbox in sync
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
            fetch('pages.php', { method: 'POST', body: fd }).then(function(r) { return r.json(); }).then(function(d) { if(d.ok) { /* toast */ } }).catch(function(e) {});
        }
    });
}
</script>
</body>
</html>
