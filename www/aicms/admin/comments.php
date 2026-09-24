<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/extensions.php';
require_auth();

if (!extension_enabled('comments')) {
    set_flash_message(t('msg_comments_disabled'), 'warning');
    header('Location: extensions.php'); exit;
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    // Single comment action (encoded as "action:id" in button value)
    if (!empty($_POST['single'])) {
        $parts = explode(':', $_POST['single'], 2);
        $action = $parts[0] ?? '';
        $id = (int)($parts[1] ?? 0);
        if ($id > 0 && db_available()) {
            if ($action === 'approve') { db_update('comments', ['status'=>'approved'], ['id'=>$id]); set_flash_message(t('msg_comment_approved'),'success'); }
            elseif ($action === 'spam') { db_update('comments', ['status'=>'spam'], ['id'=>$id]); set_flash_message(t('msg_comment_spam'),'success'); }
            elseif ($action === 'delete') { db_delete('comments', $id); set_flash_message(t('msg_comment_deleted'),'success'); }
        }
        header('Location: comments.php'); exit;
    }

    // Batch action
    $batch_action = $_POST['batch_action'] ?? '';
    $ids = $_POST['ids'] ?? [];
    if (is_string($ids)) $ids = [$ids];
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, fn($id) => $id > 0);

    if (!empty($batch_action) && !empty($ids) && db_available()) {
        $idList = implode(',', $ids);
        if ($batch_action === 'approve') {
            db_query('UPDATE ' . db_table('comments') . ' SET status = ? WHERE id IN (' . $idList . ')', ['approved']);
            set_flash_message(str_replace('{$count}', count($ids), t('msg_comments_approved')), 'success');
        } elseif ($batch_action === 'spam') {
            db_query('UPDATE ' . db_table('comments') . ' SET status = ? WHERE id IN (' . $idList . ')', ['spam']);
            set_flash_message(str_replace('{$count}', count($ids), t('msg_comments_spam')), 'success');
        } elseif ($batch_action === 'delete') {
            db_query('DELETE FROM ' . db_table('comments') . ' WHERE id IN (' . $idList . ')');
            set_flash_message(str_replace('{$count}', count($ids), t('msg_comments_deleted')), 'success');
        }
        header('Location: comments.php'); exit;
    }
}

$pending = db_available() ? db_select('comments', '*', 'status = ?', ['pending'], 'created_at DESC') : [];
$approved = db_available() ? db_select('comments', '*', 'status = ?', ['approved'], 'created_at DESC', 50) : [];
$spam = db_available() ? db_select('comments', '*', 'status = ?', ['spam'], 'created_at DESC', 20) : [];
$pending_count = count($pending);

ob_start();
?>
<span class="text-sm text-slate-500 self-center">
    <?= t('comments_pending') ?> <strong class="text-amber-600"><?= $pending_count ?></strong> · <?= t('comments_approved') ?> <strong class="text-green-600"><?= count($approved) ?></strong><?php if(!empty($spam)): ?> · <?= t('comments_spam') ?> <strong class="text-red-600"><?= count($spam) ?></strong><?php endif; ?>
</span>
<?php $action_panel = ob_get_clean();
$page_title = t('h_comments');
$breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('h_comments')]];
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8"><title><?= t('title_comments') ?></title>
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

        <?php if (!db_available()): ?>
        <div class="bg-amber-50 text-amber-700 rounded-lg p-4 flex items-center gap-2"><i class="fas fa-exclamation-triangle"></i><?= t('db_not_connected') ?></div>
        <?php else: ?>

        <!-- ===== PENDING ===== -->
        <form method="POST" id="pendingForm">
        <?= csrf_field() ?>
        <div class="bg-white rounded-xl shadow-sm mb-4 overflow-hidden">
            <div class="px-5 py-3 bg-amber-100 text-amber-800 font-semibold text-sm border-b border-amber-200 flex justify-between items-center flex-wrap gap-2">
                <span><i class="fas fa-clock mr-1"></i><?= str_replace('{$count}', $pending_count, t('comments_pending_tab')) ?></span>
                <?php if (!empty($pending)): ?>
                <div class="flex gap-2 flex-wrap">
                    <button type="button" onclick="toggleAll('pendingForm', true)" class="text-xs px-2 py-1 bg-white rounded border border-amber-300 hover:bg-amber-50"><?= t('select_all') ?></button>
                    <button type="button" onclick="toggleAll('pendingForm', false)" class="text-xs px-2 py-1 bg-white rounded border border-amber-300 hover:bg-amber-50"><?= t('clear_selection') ?></button>
                    <button type="submit" name="batch_action" value="approve" class="text-xs px-3 py-1 bg-green-600 text-white rounded hover:bg-green-700"><i class="fas fa-check mr-1"></i><?= t('approve_selected') ?></button>
                    <button type="submit" name="batch_action" value="spam" class="text-xs px-3 py-1 bg-amber-600 text-white rounded hover:bg-amber-700"><i class="fas fa-ban mr-1"></i><?= t('to_spam') ?></button>
                    <button type="submit" name="batch_action" value="delete" class="text-xs px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700" onclick="return confirm('<?= t('delete_selected_confirm') ?>')"><i class="fas fa-trash mr-1"></i><?= t('delete_selected_btn') ?></button>
                </div>
                <?php endif; ?>
            </div>
            <?php if (empty($pending)): ?>
                <p class="px-5 py-6 text-slate-400 text-sm m-0 text-center">
                    <i class="fas fa-inbox text-3xl text-slate-300 mb-2 block"></i>
                    <?= t('no_comments_pending') ?>
                </p>
            <?php else: foreach ($pending as $c): ?>
            <div class="px-5 py-3 border-b border-slate-100 flex justify-between items-start gap-3 hover:bg-slate-50">
                <div class="flex items-start gap-3 flex-1 min-w-0">
                    <input type="checkbox" name="ids[]" value="<?= $c['id'] ?>" class="mt-1 w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer flex-shrink-0">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <strong class="text-sm text-slate-800"><?= h($c['author_name']) ?></strong>
                            <?php if(!empty($c['rating'])): ?><span class="text-amber-500 text-xs"><?= str_repeat('★', $c['rating']) ?></span><?php endif; ?>
                            <small class="text-slate-400"><?= t('news_id_label') ?><?= $c['target_id'] ?> · <?= h($c['created_at']) ?></small>
                        </div>
                        <p class="text-sm text-slate-600 mt-1 mb-0"><?= nl2br(h($c['content'])) ?></p>
                    </div>
                </div>
                <div class="flex gap-1 flex-shrink-0">
                    <button type="submit" name="single" value="approve:<?= $c['id'] ?>" class="aicms-act-outline aicms-act-outline--success" ><i class="fas fa-check"></i><span class="btn-label"><?= t('approve_tip') ?></span></button>
                    <button type="submit" name="single" value="spam:<?= $c['id'] ?>" class="aicms-act-outline aicms-act-outline--warning" ><i class="fas fa-ban"></i><span class="btn-label"><?= t('spam_tip') ?></span></button>
                    <button type="submit" name="single" value="delete:<?= $c['id'] ?>" class="aicms-act-outline aicms-act-outline--danger" " onclick="return confirm('<?= t('delete_comment_confirm') ?>')"><i class="fas fa-trash"></i><span class="btn-label"><?= t('delete') ?></span></button>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
        </form>

        <!-- ===== APPROVED ===== -->
        <form method="POST" id="approvedForm">
        <?= csrf_field() ?>
        <div class="bg-white rounded-xl shadow-sm mb-4 overflow-hidden">
            <div class="px-5 py-3 bg-green-100 text-green-800 font-semibold text-sm border-b border-green-200 flex justify-between items-center flex-wrap gap-2">
                <span><i class="fas fa-check-circle mr-1"></i><?= str_replace('{$count}', count($approved), t('comments_approved_tab')) ?></span>
                <?php if (!empty($approved)): ?>
                <div class="flex gap-2 flex-wrap">
                    <button type="button" onclick="toggleAll('approvedForm', true)" class="text-xs px-2 py-1 bg-white rounded border border-green-300 hover:bg-green-50"><?= t('select_all') ?></button>
                    <button type="submit" name="batch_action" value="delete" class="text-xs px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700" onclick="return confirm('<?= t('delete_selected_confirm') ?>')"><i class="fas fa-trash mr-1"></i><?= t('delete_selected_btn') ?></button>
                </div>
                <?php endif; ?>
            </div>
            <?php if (empty($approved)): ?>
                <p class="px-5 py-4 text-slate-400 text-sm m-0 text-center"><?= t('no_comments_approved') ?></p>
            <?php else: foreach ($approved as $c): ?>
            <div class="px-5 py-2 border-b border-slate-100 flex justify-between items-center gap-3 hover:bg-slate-50">
                <div class="flex items-center gap-3 flex-1 min-w-0">
                    <input type="checkbox" name="ids[]" value="<?= $c['id'] ?>" class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer flex-shrink-0">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <strong class="text-sm text-slate-800"><?= h($c['author_name']) ?></strong>
                            <?php if(!empty($c['rating'])): ?><span class="text-amber-500 text-xs"><?= str_repeat('★', $c['rating']) ?></span><?php endif; ?>
                            <small class="text-slate-400">· #<?= $c['target_id'] ?> · <?= h($c['created_at']) ?></small>
                        </div>
                        <p class="text-xs text-slate-500 m-0 truncate"><?= h(mb_substr($c['content'],0,120)) ?><?= mb_strlen($c['content'])>120?'...':'' ?></p>
                    </div>
                </div>
                <button type="submit" name="single" value="delete:<?= $c['id'] ?>" class="aicms-act-outline aicms-act-outline--danger flex-shrink-0" " onclick="return confirm('<?= t('delete_simple_confirm') ?>')"><i class="fas fa-trash"></i><span class="btn-label"><?= t('delete') ?></span></button>
            </div>
            <?php endforeach; endif; ?>
        </div>
        </form>

        <!-- ===== SPAM ===== -->
        <?php if (!empty($spam)): ?>
        <form method="POST" id="spamForm">
        <?= csrf_field() ?>
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-5 py-3 bg-red-100 text-red-800 font-semibold text-sm border-b border-red-200 flex justify-between items-center flex-wrap gap-2">
                <span><i class="fas fa-ban mr-1"></i><?= str_replace('{$count}', count($spam), t('comments_spam_tab')) ?></span>
                <div class="flex gap-2 flex-wrap">
                    <button type="button" onclick="toggleAll('spamForm', true)" class="text-xs px-2 py-1 bg-white rounded border border-red-300 hover:bg-red-50"><?= t('select_all') ?></button>
                    <button type="submit" name="batch_action" value="delete" class="text-xs px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700" onclick="return confirm('<?= t('delete_selected_confirm') ?>')"><i class="fas fa-trash mr-1"></i><?= t('delete_selected_btn') ?></button>
                </div>
            </div>
            <?php foreach ($spam as $c): ?>
            <div class="px-5 py-2 border-b border-slate-100 flex justify-between items-center gap-3">
                <div class="flex items-center gap-3 flex-1 min-w-0">
                    <input type="checkbox" name="ids[]" value="<?= $c['id'] ?>" class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer flex-shrink-0">
                    <small class="text-slate-400 truncate"><?= h($c['author_name']) ?>: <?= h(mb_substr($c['content'],0,100)) ?></small>
                </div>
                <button type="submit" name="single" value="delete:<?= $c['id'] ?>" class="aicms-act-outline aicms-act-outline--danger flex-shrink-0" ><i class="fas fa-trash"></i><span class="btn-label"><?= t('delete') ?></span></button>
            </div>
            <?php endforeach; ?>
        </div>
        </form>
        <?php endif; ?>
        <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
<script>
function toggleAll(formId, checked) {
    document.querySelectorAll('#' + formId + ' input[type="checkbox"]').forEach(function(cb) { cb.checked = checked; });
}
</script>
</body>
</html>
