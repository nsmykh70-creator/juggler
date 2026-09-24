<?php
require_once __DIR__ . '/core/functions.php';
require_auth();
$zones_file = __DIR__ . '/../data/widget_zones.json';
$zones = file_exists($zones_file) ? (json_decode(file_get_contents($zones_file), true) ?: []) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $zone_name = $_POST['zone_name'] ?? ''; $module_shortcode = trim($_POST['module_shortcode'] ?? '');
    $action = $_POST['action'] ?? 'add';
    
    if ($action === 'delete' && !empty($_POST['zone']) && isset($_POST['index'])) {
        // Delete module from zone (POST-based, CSRF-protected)
        $zone = $_POST['zone']; $index = (int)$_POST['index'];
        if (isset($zones[$zone][$index])) {
            array_splice($zones[$zone], $index, 1);
            if (empty($zones[$zone])) unset($zones[$zone]);
            file_put_contents($zones_file, json_encode($zones, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            require_once __DIR__ . '/core/cache.php'; cache_clear();
            set_flash_message(t('msg_module_removed_zone'), 'success');
        }
    } elseif ($action === 'reorder' && !empty($_POST['zone'])) {
        // Reorder modules in zone
        $zone = $_POST['zone'];
        $order = json_decode($_POST['order'] ?? '[]', true);
        if (is_array($order) && isset($zones[$zone])) {
            $zones[$zone] = $order;
            file_put_contents($zones_file, json_encode($zones, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            require_once __DIR__ . '/core/cache.php'; cache_clear();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success']);
            exit;
        }
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Invalid order']);
        exit;
    } elseif (!empty($zone_name) && !empty($module_shortcode)) {
        // Add module to zone
        if (!isset($zones[$zone_name])) $zones[$zone_name] = [];
        $zones[$zone_name][] = $module_shortcode;
        file_put_contents($zones_file, json_encode($zones, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        require_once __DIR__ . '/core/cache.php'; cache_clear();
        set_flash_message(t('msg_module_added_zone'), 'success');
    }
    header('Location: widget_zones.php'); exit;
}
$modules = array_map(fn($f) => basename($f, '.php'), glob(__DIR__ . '/../modules/*.php'));
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8"><title><?= t('title_widget_zones') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
</head>
<body class="bg-slate-100">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php
        $page_title = t('h_widget_zones');
        $breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('widget_zones')]];
        $action_panel = '<p class="text-sm text-slate-500 self-center">'.t('widget_zones_help').'</p>';
        include __DIR__ . '/core/topbar.php';
        ?>
        <div class="p-6 flex-1">
        <?php display_flash_message(); ?>

        <!-- Add module to zone -->
        <div class="bg-white rounded-xl shadow-sm p-5 mb-4">
            <form method="POST" class="flex flex-wrap items-end gap-3"><?= csrf_field() ?>
                <div class="flex-1 min-w-[150px]"><label class="block text-sm font-medium text-slate-700 mb-1"><?= t('zone_label') ?></label><input type="text" name="zone_name" list="zone_names" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" placeholder="<?= t('zone_ph') ?>" required><datalist id="zone_names"><?php foreach (array_keys($zones) as $z): ?><option value="<?= h($z) ?>"><?php endforeach; ?></datalist></div>
                <div class="flex-[2] min-w-[200px]"><label class="block text-sm font-medium text-slate-700 mb-1"><?= t('module_label') ?></label><input type="text" name="module_shortcode" list="module_shortcodes" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" placeholder="<?= t('module_ph') ?>" required><datalist id="module_shortcodes"><?php foreach ($modules as $m): ?><option value="{{module:<?= h($m) ?>}}"><?php endforeach; ?></datalist></div>
                <button type="submit" class="aicms-icon-btn aicms-icon-btn--primary"><i class="fas fa-plus"></i><span class="btn-label"><?= t('add_btn') ?></span></button>
            </form>
        </div>

        <?php if (empty($zones)): ?>
        <div class="bg-blue-50 text-blue-700 rounded-lg p-4 flex items-center gap-2"><i class="fas fa-info-circle"></i><?= t('no_zones') ?></div>
        <?php else: foreach ($zones as $zone_name => $module_list): ?>
        <div class="bg-white rounded-xl shadow-sm mb-3 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200 flex justify-between items-center"><h5 class="font-semibold text-slate-700 m-0"><code class="px-2 py-0.5 bg-slate-100 rounded text-blue-600">{{zone:<?= h($zone_name) ?>}}</code></h5><span class="px-2 py-0.5 text-xs bg-slate-200 text-slate-600 rounded-full"><?= count($module_list) ?> <?= t('modules_count') ?></span></div>
            <div class="p-0 zone-modules" data-zone="<?= h($zone_name) ?>">
                <?php if (empty($module_list)): ?><p class="px-5 py-3 text-slate-400 text-sm m-0"><?= t('zone_empty') ?></p>
                <?php else: foreach ($module_list as $i => $shortcode): ?>
                <div class="zone-module-item px-5 py-2.5 border-b border-slate-100 last:border-0 flex justify-between items-center" draggable="true" data-zone="<?= h($zone_name) ?>" data-index="<?= $i ?>">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-grip-vertical text-slate-300 cursor-grab drag-handle" title="Drag to reorder"></i>
                        <code class="text-sm text-slate-600"><?= h($shortcode) ?></code>
                    </div>
                    <form method="POST" class="inline" onsubmit="return confirm('<?= t('delete_simple_confirm') ?>')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="zone" value="<?= h($zone_name) ?>"><input type="hidden" name="index" value="<?= $i ?>"><button type="submit" class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-trash"></i><span class="btn-label"><?= t('delete') ?></span></button></form>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>
        </div>
    </div>
</div>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
<script>
// Drag-and-drop reordering of modules in zones
document.querySelectorAll('.zone-modules').forEach(function(container) {
    var zone = container.getAttribute('data-zone');
    var dragItem = null;
    
    container.addEventListener('dragstart', function(e) {
        var item = e.target.closest('.zone-module-item');
        if (!item) return;
        dragItem = item;
        setTimeout(function() { item.style.opacity = '0.4'; }, 0);
        e.dataTransfer.effectAllowed = 'move';
    });
    
    container.addEventListener('dragend', function(e) {
        var item = e.target.closest('.zone-module-item');
        if (item) item.style.opacity = '';
        container.querySelectorAll('.zone-module-item').forEach(function(el) {
            el.style.borderTop = '';
        });
        dragItem = null;
    });
    
    container.addEventListener('dragover', function(e) {
        e.preventDefault();
        if (!dragItem) return;
        var afterElement = null;
        var items = container.querySelectorAll('.zone-module-item:not([style*="opacity: 0.4"])');
        items.forEach(function(el) {
            var rect = el.getBoundingClientRect();
            var offset = e.clientY - rect.top - rect.height / 2;
            if (offset < 0) {
                el.style.borderTop = '2px solid #2563eb';
                afterElement = el;
            } else {
                el.style.borderTop = '';
            }
        });
        if (afterElement) {
            container.insertBefore(dragItem, afterElement);
        } else {
            container.appendChild(dragItem);
        }
    });
    
    container.addEventListener('drop', function(e) {
        e.preventDefault();
        // Collect new order
        var items = container.querySelectorAll('.zone-module-item code');
        var newOrder = [];
        items.forEach(function(code) { newOrder.push(code.textContent); });
        
        // Save new order via AJAX
        var fd = new FormData();
        fd.append('action', 'reorder');
        fd.append('zone', zone);
        fd.append('order', JSON.stringify(newOrder));
        fd.append('csrf_token', '<?= csrf_field_value() ?>');
        
        fetch('widget_zones.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.status === 'success' && window.AicmsToast) {
                    AicmsToast.show('Order saved', 'success');
                }
            })
            .catch(function() {});
    });
});
</script>
</body>
</html>
