<?php
require_once __DIR__ . '/core/functions.php';
require_auth();
require_once __DIR__ . '/core/cache.php';

$pages = read_content('pages.json');
$ci_file = __DIR__ . '/../data/menu_items.json';
$custom_items = file_exists($ci_file) ? (json_decode(@file_get_contents($ci_file), true) ?: []) : [];

// Handle AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'set_parent') {
        $page_id = (int)($_POST['page_id'] ?? 0);
        $parent_id = $_POST['parent_id'] ?? '0';
        if (is_numeric($parent_id)) $parent_id = (int)$parent_id;
        if ((string)$page_id !== (string)$parent_id) {
            foreach ($pages as &$p) {
                if ((int)$p['id'] === $page_id) { $p['parent_id'] = $parent_id; break; }
            }
            unset($p);
            write_content('pages.json', $pages);
            cache_clear();
        }
        echo json_encode(['status'=>'success','message'=>'Родитель обновлён.']);
        exit;
    }

    if ($action === 'save_order') {
        $order = json_decode($_POST['order'] ?? '[]', true);
        if (is_array($order)) {
            $idx = 1;
            foreach ($order as $id) {
                if (strpos($id, 'custom_') === 0) {
                    $cid = (int)str_replace('custom_', '', $id);
                    foreach ($custom_items as &$ci) { if ((int)$ci['id'] === $cid) { $ci['menu_order'] = $idx++; break; } }
                    unset($ci);
                } else {
                    $pid = (int)$id;
                    foreach ($pages as &$p) { if ((int)$p['id'] === $pid) { $p['menu_order'] = $idx++; break; } }
                    unset($p);
                }
            }
            write_content('pages.json', $pages);
            file_put_contents($ci_file, json_encode($custom_items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            cache_clear();
        }
        echo json_encode(['status'=>'success','message'=>'Порядок сохранён!']);
        exit;
    }

    if ($action === 'save_children_order') {
        $order = json_decode($_POST['order'] ?? '[]', true);
        if (is_array($order)) {
            $idx = 1;
            foreach ($order as $id) {
                $pid = (int)$id;
                foreach ($pages as &$p) { if ((int)$p['id'] === $pid) { $p['menu_order'] = $idx++; break; } }
                unset($p);
            }
            write_content('pages.json', $pages);
            cache_clear();
        }
        echo json_encode(['status'=>'success','message'=>'Порядок подменю сохранён!']);
        exit;
    }

    if ($action === 'toggle_menu') {
        $page_id = (int)($_POST['page_id'] ?? 0);
        foreach ($pages as &$p) {
            if ((int)$p['id'] === $page_id) { $p['show_in_menu'] = empty($p['show_in_menu']); $vis = $p['show_in_menu']; break; }
        }
        unset($p);
        write_content('pages.json', $pages);
        cache_clear();
        echo json_encode(['status'=>'success','message'=>$vis?'Показана.':'Скрыта.']);
        exit;
    }

    if ($action === 'add_custom_item') {
        $title = trim($_POST['item_title'] ?? '');
        $url = trim($_POST['item_url'] ?? '#');
        if ($title) {
            $max_id = 0;
            foreach ($custom_items as $ci) { if ((int)$ci['id'] > $max_id) $max_id = (int)$ci['id']; }
            $custom_items[] = ['id'=>$max_id+1, 'title'=>$title, 'url'=>$url, 'menu_order'=>999];
            file_put_contents($ci_file, json_encode($custom_items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            cache_clear();
        }
        echo json_encode(['status'=>'success','message'=>'Пункт добавлен.']);
        exit;
    }

    if ($action === 'delete_custom_item') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        foreach ($pages as &$p) { if (($p['parent_id'] ?? '') === 'custom_'.$item_id) { $p['parent_id'] = 0; } }
        unset($p);
        write_content('pages.json', $pages);
        $custom_items = array_values(array_filter($custom_items, fn($i) => (int)$i['id'] !== $item_id));
        file_put_contents($ci_file, json_encode($custom_items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        cache_clear();
        echo json_encode(['status'=>'success','message'=>'Пункт удалён.']);
        exit;
    }
    echo json_encode(['status'=>'error','message'=>'Unknown']);
    exit;
}

// Build menu list
$menu_list = [];
$child_map = [];
foreach ($pages as $p) {
    $pid = $p['parent_id'] ?? 0;
    if ($pid === 0 || $pid === '0' || empty($pid)) {
        $menu_list[] = ['type'=>'page','id'=>$p['id'],'sort_id'=>(string)$p['id'],
            'title'=>$p['title'],'slug'=>$p['slug'],'show_in_menu'=>$p['show_in_menu'] ?? true,
            'menu_order'=>$p['menu_order'] ?? 0,'parent_id'=>0];
    } else {
        $key = is_string($pid) ? $pid : (int)$pid;
        if (!isset($child_map[$key])) $child_map[$key] = [];
        $child_map[$key][] = $p;
    }
}
foreach ($custom_items as $ci) {
    $menu_list[] = ['type'=>'custom','id'=>$ci['id'],'sort_id'=>'custom_'.$ci['id'],
        'title'=>$ci['title'],'slug'=>$ci['url'] ?? '#','show_in_menu'=>true,
        'menu_order'=>$ci['menu_order'] ?? 0,'parent_id'=>0];
}
usort($menu_list, function($a, $b) { return (int)($a['menu_order'] ?? 0) <=> (int)($b['menu_order'] ?? 0); });

// Build parent options
$parent_options_html = '<option value="0">— Верхний уровень —</option>';
foreach ($pages as $pp) { $parent_options_html .= '<option value="'.$pp['id'].'">↳ '.h($pp['title']).'</option>'; }
foreach ($custom_items as $ci) { $parent_options_html .= '<option value="custom_'.$ci['id'].'">🔗 '.h($ci['title']).'</option>'; }
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8"><title>Меню — AICMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
    <style>
    .menu-group { margin-bottom:2px; }
    .menu-row { transition:all 0.15s; }
    .menu-row:hover { background:#f1f5f9; }
    .drag-handle { cursor:move; }
    .menu-parent-row { background:#fef3c7 !important; border:1px solid #fde68a; border-radius:8px; }
    .menu-child-row { background:#fffbeb; border-left:3px solid #f59e0b; margin-left:32px; border-radius:6px; }
    .badge-custom { display:inline-block; padding:1px 6px; background:#f59e0b; color:#fff; border-radius:4px; font-size:10px; font-weight:600; }
    .sortable-ghost { opacity:0.4; }
    .sortable-drag { box-shadow:0 4px 12px rgba(0,0,0,0.15); }

    /* === Dark theme overrides ===
       The menu page uses light amber backgrounds (#fef3c7, #fffbeb) and
       dark amber text (text-amber-800/700/600) which are unreadable on
       the dark admin theme. These rules override the backgrounds to dark
       slate/amber tones and the text to light amber/white for proper
       contrast. */
    body.dark-theme .menu-row:hover { background:#334155; }
    body.dark-theme .menu-parent-row {
        background:rgba(245,158,11,0.15) !important;
        border:1px solid rgba(245,158,11,0.4) !important;
    }
    body.dark-theme .menu-child-row {
        background:rgba(245,158,11,0.08);
        border-left:3px solid #f59e0b;
    }
    body.dark-theme .menu-parent-row .text-amber-800,
    body.dark-theme .menu-parent-row span.text-amber-800 { color:#fbbf24 !important; }
    body.dark-theme .menu-child-row .text-amber-700,
    body.dark-theme .menu-child-row span.text-amber-700 { color:#fcd34d !important; }
    body.dark-theme .menu-parent-row code.text-amber-700,
    body.dark-theme .menu-parent-row code.bg-amber-200 { background:rgba(245,158,11,0.25) !important; color:#fbbf24 !important; }
    body.dark-theme .menu-child-row code.text-amber-600,
    body.dark-theme .menu-child-row code.bg-amber-100 { background:rgba(245,158,11,0.15) !important; color:#fcd34d !important; }
    body.dark-theme .menu-parent-row .text-amber-400 { color:#f59e0b !important; }
    body.dark-theme .menu-child-row .text-amber-300 { color:#fbbf24 !important; }
    body.dark-theme .menu-row .text-slate-700,
    body.dark-theme .menu-row .text-slate-600 { color:#e2e8f0 !important; }
    body.dark-theme .menu-row .text-slate-400,
    body.dark-theme .menu-row .text-slate-300 { color:#94a3b8 !important; }
    body.dark-theme .menu-row code.bg-slate-200,
    body.dark-theme .menu-row code.text-slate-500 { background:#334155 !important; color:#cbd5e1 !important; }
    body.dark-theme .menu-row .bg-slate-50 { background:#1e293b !important; }
    body.dark-theme .menu-row .bg-slate-50:hover { background:#334155 !important; }
    body.dark-theme .menu-row select.parent-select {
        background:#0f172a !important;
        color:#e2e8f0 !important;
        border-color:#475569 !important;
    }
    body.dark-theme .menu-row .border-slate-200 { border-color:#475569 !important; }
    body.dark-theme .menu-row .border-slate-300 { border-color:#475569 !important; }
    body.dark-theme .menu-row .border-green-200 { border-color:#16a34a !important; }
    body.dark-theme .menu-row .text-green-600 { color:#4ade80 !important; }
    body.dark-theme .children-container { border-left-color:#475569 !important; }
    body.dark-theme .bg-blue-50 { background:rgba(37,99,235,0.15) !important; }
    body.dark-theme .text-blue-700 { color:#93c5fd !important; }
    body.dark-theme .bg-white.rounded-xl { background:#1e293b !important; }
    </style>
</head>
<body class="bg-slate-100">
<script>try{if(localStorage.getItem('aicms_dark')==='1')document.body.classList.add('dark-theme');}catch(e){}</script>
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php
        $page_title = t('h_manage_menu');
        $breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('menu')]];
        include __DIR__ . '/core/topbar.php';
        ?>
        <div class="p-6 flex-1">
        <div id="save-status"></div>

        <div class="bg-blue-50 rounded-lg p-4 mb-4 text-sm text-blue-700">
            <i class="fas fa-info-circle mr-1"></i>
            <strong>Перетаскивайте</strong> пункты для изменения порядка (подпункты перемещаются вместе).
            Используйте <strong>выпадающий список</strong> для прикрепления/открепления подменю.
        </div>

        <!-- Add custom item -->
        <div class="bg-white rounded-xl shadow-sm p-4 mb-4">
            <div class="flex gap-2">
                <input type="text" id="ci-title" placeholder="Название (Дополнения)" class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm">
                <input type="text" id="ci-url" placeholder="URL" value="#" class="w-32 px-3 py-2 border border-slate-300 rounded-lg text-sm">
                <button id="ci-add" class="aicms-btn aicms-btn--primary aicms-btn--sm" title="Пункт меню"><i class="fas fa-plus"></i></button>
            </div>
        </div>

        <!-- Menu structure -->
        <div class="bg-white rounded-xl shadow-sm p-5">
            <div class="flex items-center justify-between mb-3">
                <h6 class="text-sm font-semibold text-slate-600">Структура меню</h6>
                <span id="save-status-text" class="text-xs text-slate-400">Порядок сохраняется автоматически</span>
            </div>
            <div id="menu-list" class="space-y-1">
                <?php foreach ($menu_list as $item): ?>
                    <?php
                    $is_custom = $item['type'] === 'custom';
                    $item_key = $is_custom ? 'custom_' . $item['id'] : (int)$item['id'];
                    $children = $child_map[$item_key] ?? [];
                    $current_parent = (string)($item['parent_id'] ?? '0');
                ?>
                <!-- Group: parent + its children (draggable as a unit) -->
                <div class="menu-group drag-handle" data-id="<?= h($item['sort_id']) ?>">
                    <!-- Parent row -->
                    <div class="menu-row <?= $is_custom ? 'menu-parent-row' : 'bg-slate-50' ?> rounded-lg p-3 flex items-center gap-3">
                        <i class="fas fa-grip-vertical <?= $is_custom ? 'text-amber-400' : 'text-slate-300' ?>"></i>
                        <?php if ($is_custom): ?>
                        <span class="badge-custom">МЕНЮ</span>
                        <?php else: ?>
                        <i class="fas fa-file text-slate-400 text-xs"></i>
                        <?php endif; ?>
                        <span class="flex-1 text-sm font-medium <?= $is_custom ? 'text-amber-800' : 'text-slate-700' ?>"><?= h($item['title']) ?></span>
                        <code class="text-xs px-1.5 py-0.5 <?= $is_custom ? 'bg-amber-200 text-amber-700' : 'bg-slate-200 text-slate-500' ?> rounded"><?= h($item['slug']) ?></code>
                        <?php if (!$is_custom): ?>
                        <select class="parent-select text-xs border border-slate-300 rounded px-2 py-1" data-page-id="<?= (int)$item['id'] ?>" data-current="<?= h($current_parent) ?>">
                            <?= $parent_options_html ?>
                        </select>
                        <button class="aicms-act-outline <?= !empty($item['show_in_menu']) ? 'aicms-act-outline--success' : 'aicms-act-outline--secondary' ?>" data-page-id="<?= (int)$item['id'] ?>"><i class="fas <?= !empty($item['show_in_menu']) ? 'fa-eye' : 'fa-eye-slash' ?>"></i><span class="btn-label">Показать/скрыть</span>
                        <?php else: ?>
                        <button class="aicms-act-outline aicms-act-outline--danger delete-ci" data-item-id="<?= (int)$item['id'] ?>"><i class="fas fa-trash"></i><span class="btn-label">Удалить</span></button>
                        <?php endif; ?>
                    </div>
                    <!-- Children container (sortable within) -->
                    <?php if (!empty($children)): ?>
                    <div class="children-container" style="margin-left:32px; border-left:2px dashed #cbd5e1; padding-left:8px;">
                        <?php foreach ($children as $child): ?>
                        <?php $child_parent = (string)($child['parent_id'] ?? '0'); ?>
                        <div class="menu-row menu-child-row child-draggable rounded-lg p-2 flex items-center gap-2" data-id="<?= (int)$child['id'] ?>">
                            <i class="fas fa-grip-vertical text-slate-300 text-xs child-handle"></i>
                            <i class="fas fa-level-down-alt text-amber-300 text-xs" style="transform:rotate(90deg);"></i>
                            <span class="flex-1 text-sm text-amber-700"><?= h($child['title']) ?></span>
                            <code class="text-xs px-1.5 py-0.5 bg-amber-100 rounded text-amber-600"><?= h($child['slug']) ?></code>
                            <select class="parent-select text-xs border border-slate-300 rounded px-2 py-1" data-page-id="<?= (int)$child['id'] ?>" data-current="<?= h($child_parent) ?>">
                                <?= $parent_options_html ?>
                            </select>
                            <button class="toggle-menu-btn text-xs px-2 py-1 rounded border <?= !empty($child['show_in_menu']) ? 'border-green-200 text-green-600' : 'border-slate-200 text-slate-400' ?>" data-page-id="<?= (int)$child['id'] ?>" title="Показать/скрыть"><i class="fas <?= !empty($child['show_in_menu']) ? 'fa-eye' : 'fa-eye-slash' ?>"></i></button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
<script>
var csrf = "<?= csrf_field_value() ?>";

function showToast(msg, type) {
    var d = document.createElement('div');
    d.className = 'fixed bottom-5 right-5 z-[1060] px-4 py-2 rounded-lg text-white text-sm ' + (type==='success'?'bg-green-600':'bg-red-600');
    d.textContent = msg; document.body.appendChild(d);
    setTimeout(function(){ d.remove(); }, 2500);
}

// Set selected values for all parent-select dropdowns
document.querySelectorAll('.parent-select').forEach(function(sel) {
    var current = sel.getAttribute('data-current') || '0';
    sel.value = current;
});

// === Drag-and-drop: .menu-group items (parent + children move together) ===
Sortable.create(document.getElementById('menu-list'), {
    animation: 150,
    ghostClass: 'sortable-ghost',
    dragClass: 'sortable-drag',
    draggable: '.menu-group',
    onEnd: function(evt) {
        var ids = [];
        document.querySelectorAll('#menu-list > .menu-group').forEach(function(el) { ids.push(el.dataset.id); });
        var fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', 'save_order');
        fd.append('order', JSON.stringify(ids));
        var statusEl = document.getElementById('save-status-text');
        statusEl.textContent = 'Сохранение...'; statusEl.className = 'text-xs text-amber-600';
        fetch('menu.php', {method:'POST', body:fd}).then(function(r){return r.json();}).then(function(data){
            statusEl.textContent = '✓ Сохранено'; statusEl.className = 'text-xs text-green-600';
            showToast(data.message || 'Сохранено', 'success');
            setTimeout(function(){ statusEl.textContent='Порядок сохраняется автоматически'; statusEl.className='text-xs text-slate-400'; }, 2000);
        });
    }
});

// === Drag-and-drop: children within each parent ===
document.querySelectorAll('.children-container').forEach(function(container) {
    Sortable.create(container, {
        animation: 150,
        ghostClass: 'sortable-ghost',
        dragClass: 'sortable-drag',
        draggable: '.child-draggable',
        handle: '.child-handle',
        onEnd: function(evt) {
            var ids = [];
            this.el.querySelectorAll('.child-draggable').forEach(function(el) { ids.push(el.dataset.id); });
            var fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('action', 'save_children_order');
            fd.append('order', JSON.stringify(ids));
            var statusEl = document.getElementById('save-status-text');
            statusEl.textContent = 'Сохранение...'; statusEl.className = 'text-xs text-amber-600';
            fetch('menu.php', {method:'POST', body:fd}).then(function(r){return r.json();}).then(function(data){
                statusEl.textContent = '✓ Сохранено'; statusEl.className = 'text-xs text-green-600';
                showToast(data.message || 'Сохранено', 'success');
                setTimeout(function(){ statusEl.textContent='Порядок сохраняется автоматически'; statusEl.className='text-xs text-slate-400'; }, 2000);
            });
        }
    });
});

// === Parent select dropdowns ===
document.querySelectorAll('.parent-select').forEach(function(sel) {
    // Prevent drag from starting on select
    sel.addEventListener('mousedown', function(e) { e.stopPropagation(); });
    sel.addEventListener('change', function() {
        var fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', 'set_parent');
        fd.append('page_id', this.dataset.pageId);
        fd.append('parent_id', this.value);
        var statusEl = document.getElementById('save-status-text');
        statusEl.textContent = 'Сохранение...'; statusEl.className = 'text-xs text-amber-600';
        fetch('menu.php', {method:'POST', body:fd}).then(function(r){return r.json();}).then(function(data){
            statusEl.textContent = '✓ Сохранено'; statusEl.className = 'text-xs text-green-600';
            showToast(data.message || 'Сохранено', 'success');
            setTimeout(function(){ location.reload(); }, 600);
        });
    });
});

// === Toggle visibility ===
document.querySelectorAll('.toggle-menu-btn').forEach(function(btn) {
    btn.addEventListener('mousedown', function(e) { e.stopPropagation(); });
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        var fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', 'toggle_menu');
        fd.append('page_id', this.dataset.pageId);
        var self = this;
        fetch('menu.php', {method:'POST', body:fd}).then(function(r){return r.json();}).then(function(data){
            showToast(data.message, 'success');
            var icon = self.querySelector('i');
            if (icon.classList.contains('fa-eye')) {
                icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash');
                self.classList.remove('border-green-200','text-green-600');
                self.classList.add('border-slate-200','text-slate-400');
            } else {
                icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye');
                self.classList.add('border-green-200','text-green-600');
                self.classList.remove('border-slate-200','text-slate-400');
            }
        });
    });
});

// === Add custom item ===
document.getElementById('ci-add').addEventListener('click', function() {
    var title = document.getElementById('ci-title').value.trim();
    var url = document.getElementById('ci-url').value.trim() || '#';
    if (!title) { showToast('Введите название', 'error'); return; }
    var fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('action', 'add_custom_item');
    fd.append('item_title', title);
    fd.append('item_url', url);
    fetch('menu.php', {method:'POST', body:fd}).then(function(r){return r.json();}).then(function(data){
        showToast(data.message, 'success');
        setTimeout(function(){ location.reload(); }, 500);
    });
});

// === Delete custom item ===
document.querySelectorAll('.delete-ci').forEach(function(btn) {
    btn.addEventListener('mousedown', function(e) { e.stopPropagation(); });
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        if (!confirm('Удалить пункт меню? Дочерние страницы открепятся.')) return;
        var fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', 'delete_custom_item');
        fd.append('item_id', this.dataset.itemId);
        fetch('menu.php', {method:'POST', body:fd}).then(function(r){return r.json();}).then(function(data){
            showToast(data.message, 'success');
            setTimeout(function(){ location.reload(); }, 500);
        });
    });
});
</script>
</body>
</html>
