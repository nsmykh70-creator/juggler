<?php
/**
 * AICMS Admin — Shop Reviews Moderation
 * =======================================
 * Approve / reject / delete product reviews.
 */
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/extensions.php';
require_once __DIR__ . '/core/cache.php';
require_once __DIR__ . '/core/audit.php';
require_auth();

if (!extension_enabled('shop')) {
    set_flash_message('Магазин отключён.', 'warning');
    header('Location: index.php'); exit;
}
if (!db_available()) {
    set_flash_message('Магазин требует базу данных.', 'danger');
    header('Location: settings.php?tab=database'); exit;
}

// === Handle POST ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($id > 0 && in_array($action, ['approve','reject','delete'], true)) {
        $review = db_find('shop_reviews', $id);
        if ($review) {
            if ($action === 'approve') {
                db_update('shop_reviews', ['status' => 'approved'], ['id' => $id]);
                audit_log('review_approve', "id={$id} product_id=" . $review['product_id']);
                set_flash_message('Отзыв одобрен и опубликован.', 'success');
            } elseif ($action === 'reject') {
                db_update('shop_reviews', ['status' => 'pending'], ['id' => $id]);
                audit_log('review_reject', "id={$id}");
                set_flash_message('Отзыв отправлен обратно на модерацию.', 'success');
            } elseif ($action === 'delete') {
                db_delete('shop_reviews', $id);
                audit_log('review_delete', "id={$id}");
                set_flash_message('Отзыв удалён.', 'success');
            }
            cache_clear();
        }
    }
    header('Location: shop_reviews.php'); exit;
}

// === Filters ===
$status_filter = $_GET['status'] ?? 'pending';
if (!in_array($status_filter, ['pending','approved','all'], true)) $status_filter = 'pending';

// === Get reviews ===
try {
    if ($status_filter === 'all') {
        $reviews = db_select('shop_reviews', '*', '', [], 'created_at DESC', 100);
    } else {
        $reviews = db_select('shop_reviews', '*', 'status = ?', [$status_filter], 'created_at DESC', 100);
    }
} catch (Exception $e) {
    $reviews = [];
}

// Build product map
$all_products = db_select('shop_products', 'id,title', '', [], 'title ASC');
$prod_map = [];
foreach ($all_products as $p) $prod_map[$p['id']] = $p['title'];

// Counts
$pending_count = db_count('shop_reviews', 'status = ?', ['pending']);
$approved_count = db_count('shop_reviews', 'status = ?', ['approved']);

$page_title = 'Отзывы магазина';
$breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>'Магазин','url'=>'shop.php'],['label'=>'Отзывы']];
$action_panel = '<a href="shop.php" class="aicms-icon-btn aicms-icon-btn--secondary"><i class="fas fa-arrow-left"></i><span class="btn-label">К товарам</span></a>';
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8">
    <title>Отзывы — AICMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
</head>
<body class="bg-slate-100">
<script>try{if(localStorage.getItem('aicms_dark')==='1')document.body.classList.add('dark-theme');}catch(e){}</script>
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php include __DIR__ . '/core/topbar.php'; ?>
        <div class="p-6 flex-1">
        <?php display_flash_message(); ?>

        <!-- Filter tabs -->
        <div class="flex border-b border-slate-200 mb-4">
            <a href="shop_reviews.php?status=pending" class="px-4 py-2 text-sm font-medium border-b-2 <?= $status_filter==='pending'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700' ?>">
                <i class="fas fa-clock mr-1"></i> На модерации
                <?php if ($pending_count > 0): ?><span class="ml-1 px-1.5 py-0.5 text-xs font-bold bg-amber-100 text-amber-700 rounded-full"><?= $pending_count ?></span><?php endif; ?>
            </a>
            <a href="shop_reviews.php?status=approved" class="px-4 py-2 text-sm font-medium border-b-2 <?= $status_filter==='approved'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700' ?>">
                <i class="fas fa-check-circle mr-1"></i> Опубликованные
                <span class="ml-1 px-1.5 py-0.5 text-xs font-bold bg-green-100 text-green-700 rounded-full"><?= $approved_count ?></span>
            </a>
            <a href="shop_reviews.php?status=all" class="px-4 py-2 text-sm font-medium border-b-2 <?= $status_filter==='all'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700' ?>">
                <i class="fas fa-list mr-1"></i> Все
            </a>
        </div>

        <?php if (empty($reviews)): ?>
        <div class="bg-white rounded-xl shadow-sm p-12 text-center text-slate-400">
            <i class="fas fa-comments text-4xl text-slate-300 mb-3 block"></i>
            <p class="m-0">Отзывов в этой категории нет.</p>
        </div>
        <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($reviews as $r):
                $rating = (int)($r['rating'] ?? 5);
                $prod_title = $prod_map[$r['product_id']] ?? 'Товар #' . $r['product_id'];
            ?>
            <div class="bg-white rounded-xl shadow-sm p-4">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white font-bold text-sm">
                            <?= h(mb_substr($r['author_name'] ?? '?', 0, 1, 'UTF-8')) ?>
                        </div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1 flex-wrap">
                            <strong class="text-sm text-slate-800"><?= h($r['author_name'] ?? 'Аноним') ?></strong>
                            <span class="text-xs text-slate-400">·</span>
                            <a href="mailto:<?= h($r['author_email'] ?? '') ?>" class="text-xs text-blue-600 hover:underline"><?= h($r['author_email'] ?? '') ?></a>
                            <span class="text-xs text-slate-400">·</span>
                            <span class="text-xs text-slate-400"><?= h(date('d.m.Y H:i', strtotime($r['created_at'] ?? 'now'))) ?></span>
                            <?php if ($r['status'] === 'pending'): ?>
                            <span class="ml-auto px-2 py-0.5 text-xs font-semibold rounded-full bg-amber-100 text-amber-800">На модерации</span>
                            <?php else: ?>
                            <span class="ml-auto px-2 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-800">Опубликован</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-2 mb-2">
                            <div class="flex" title="<?= $rating ?>/5">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star text-sm <?= $i <= $rating ? 'text-amber-400' : 'text-slate-200' ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <a href="shop.php?tab=products&edit=<?= (int)$r['product_id'] ?>" class="text-xs text-slate-500 hover:text-blue-600">→ <?= h($prod_title) ?></a>
                        </div>
                        <div class="text-sm text-slate-700 mb-3"><?= nl2br(h($r['content'] ?? '')) ?></div>
                        <div class="flex items-center gap-2">
                            <?php if ($r['status'] === 'pending'): ?>
                            <form method="POST" class="inline"><?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="aicms-btn aicms-btn--success aicms-btn--sm"><i class="fas fa-check"></i> Одобрить</button>
                            </form>
                            <?php else: ?>
                            <form method="POST" class="inline"><?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="action" value="reject">
                                <button type="submit" class="aicms-btn aicms-btn--ghost aicms-btn--sm"><i class="fas fa-undo"></i> Снять с публикации</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" class="inline" onsubmit="return confirm('Удалить отзыв безвозвратно?')"><?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="aicms-btn aicms-btn--danger aicms-btn--sm"><i class="fas fa-trash"></i> Удалить</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
</body>
</html>
