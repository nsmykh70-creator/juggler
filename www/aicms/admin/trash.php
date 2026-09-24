<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/trash.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'restore') {
        $filename = $_POST['filename'] ?? '';
        $new_id = trash_restore($filename);
        if ($new_id) {
            require_once __DIR__ . '/core/cache.php'; cache_clear();
            set_flash_message(t('msg_trash_restored'), 'success');
        } else { set_flash_message(t('msg_trash_restore_error'), 'danger'); }
    } elseif ($action === 'delete_permanent') {
        trash_delete_permanent($_POST['filename'] ?? '');
        set_flash_message(t('msg_trash_deleted'), 'success');
    } elseif ($action === 'empty_trash') {
        trash_empty();
        set_flash_message(t('msg_trash_emptied'), 'success');
    }
    header('Location: trash.php'); exit;
}
$trash_items = trash_list();
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8"><title><?= t('title_trash') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
</head>
<body class="bg-slate-100">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php
        $page_title = t('trash');
        $breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('trash')]];
        ob_start();
        if (!empty($trash_items)):
        ?>
        <form method="POST" onsubmit="return confirm('<?= t('empty_trash_confirm') ?>');">
            <?= csrf_field() ?><input type="hidden" name="action" value="empty_trash">
            <button type="submit" class="aicms-icon-btn aicms-icon-btn--danger"><i class="fas fa-trash"></i><span class="btn-label"><?= t('empty_trash') ?></span></button>
        </form>
        <?php endif;
        $action_panel = ob_get_clean();
        include __DIR__ . '/core/topbar.php';
        ?>
        <div class="p-6 flex-1">
        <?php display_flash_message(); ?>
        <?php if (empty($trash_items)): ?>
        <div class="bg-blue-50 text-blue-700 rounded-lg p-4 flex items-center gap-2"><i class="fas fa-info-circle"></i><?= t('trash_empty') ?></div>
        <?php else: ?>
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto"><table class="w-full">
                <thead><tr class="border-b border-slate-200 bg-slate-50">
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('type') ?></th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('title') ?></th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('language') ?></th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('deleted') ?></th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase"><?= t('actions') ?></th>
                </tr></thead>
                <tbody class="divide-y divide-slate-200">
                <?php foreach ($trash_items as $item): $m = $item['__trash_meta']; ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3"><span class="px-2 py-0.5 text-xs font-semibold rounded-full <?= $m['type']==='page'?'bg-blue-100 text-blue-800':'bg-green-100 text-green-800' ?>"><i class="fas fa-<?= $m['type']==='page'?'file-alt':'newspaper' ?>"></i> <?= $m['type']==='page'?t('page_type'):t('news_type') ?></span></td>
                    <td class="px-4 py-3 text-sm font-medium text-slate-800"><?= h($m['title']) ?></td>
                    <td class="px-4 py-3"><code class="text-xs px-1.5 py-0.5 bg-slate-100 rounded"><?= h($m['lang']) ?></code></td>
                    <td class="px-4 py-3 text-sm text-slate-500"><?= h($m['deleted_at']) ?></td>
                    <td class="px-4 py-3"><div class="flex gap-1.5 justify-end">
                        <form method="POST" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="filename" value="<?= h($m['filename']) ?>"><button class="aicms-act-outline aicms-act-outline--success"><i class="fas fa-undo"></i><span class="btn-label"><?= t('restore_tip') ?></span></button></form>
                        <form method="POST" class="inline" onsubmit="return confirm('<?= t('delete_forever_confirm') ?>');"><?= csrf_field() ?><input type="hidden" name="action" value="delete_permanent"><input type="hidden" name="filename" value="<?= h($m['filename']) ?>"><button class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-times"></i><span class="btn-label"><?= t('delete_forever_tip') ?></span></button></form>
                    </div></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
</body>
</html>
