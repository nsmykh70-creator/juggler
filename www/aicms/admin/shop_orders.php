<?php
/**
 * AICMS Admin — Shop Orders Management
 */
require_once __DIR__ . '/core/functions.php';
require_auth();
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/extensions.php';
require_once __DIR__ . '/core/cache.php';

if (!extension_enabled('shop')) {
    set_flash_message('Магазин отключён.', 'warning');
    header('Location: index.php'); exit;
}

$orders_file = __DIR__ . '/../data/shop_orders.json';
$orders = file_exists($orders_file) ? (json_decode(file_get_contents($orders_file), true) ?: []) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? 'status';
    $status = $_POST['status'] ?? '';

    if ($action === 'delete' && $id > 0) {
        $orders = array_values(array_filter($orders, fn($o) => ($o['id'] ?? 0) !== $id));
        file_put_contents($orders_file, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        set_flash_message('Заказ удалён.', 'success');
    } elseif ($id > 0 && in_array($status, ['new','processing','shipped','done','cancelled'])) {
        foreach ($orders as &$o) { if (($o['id'] ?? 0) === $id) { $o['status'] = $status; break; } }
        unset($o);
        file_put_contents($orders_file, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        set_flash_message('Статус заказа обновлён.', 'success');
    }
    cache_clear();
    header('Location: shop_orders.php'); exit;
}

usort($orders, fn($a,$b) => strtotime($b['created'] ?? '') <=> strtotime($a['created'] ?? ''));
$st_labels = [
    'new'         => ['Новый',       'bg-blue-100 text-blue-800'],
    'processing'  => ['В обработке', 'bg-amber-100 text-amber-800'],
    'shipped'     => ['Отправлен',   'bg-purple-100 text-purple-800'],
    'done'        => ['Завершён',    'bg-green-100 text-green-800'],
    'cancelled'   => ['Отменён',     'bg-red-100 text-red-800'],
];
$page_title = 'Заказы магазина';
$breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>'Заказы']];
$action_panel = '<a href="shop.php" class="aicms-icon-btn aicms-icon-btn--secondary"><i class="fas fa-shopping-bag"></i><span class="btn-label">Товары</span></a>';
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8">
    <title>Заказы магазина — AICMS</title>
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

        <h1 class="text-2xl font-bold text-slate-800 mb-1">Заказы магазина</h1>
        <p class="text-sm text-slate-500 mb-6">Управление заказами</p>

        <?php if (empty($orders)): ?>
        <div class="bg-blue-50 text-blue-700 rounded-lg p-4 flex items-center gap-2">
            <i class="fas fa-info-circle"></i> Заказов пока нет
        </div>
        <?php else: ?>
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50">
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Клиент</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Состав</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Сумма</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Дата</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Статус</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                    <?php foreach ($orders as $o):
                        $st = $st_labels[$o['status'] ?? 'new'] ?? $st_labels['new'];
                        $items = $o['items'] ?? [];
                        $total = $o['total'] ?? 0;
                    ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-sm text-slate-500">#<?= $o['id'] ?? '' ?></td>
                            <td class="px-4 py-3">
                                <strong class="text-sm text-slate-800"><?= h($o['name'] ?? '') ?></strong>
                                <?php if (!empty($o['email'])): ?><br><a href="mailto:<?= h($o['email']) ?>" class="text-xs text-slate-500 no-underline">📧 <?= h($o['email']) ?></a><?php endif; ?>
                                <?php if (!empty($o['phone'])): ?><br><span class="text-xs text-slate-500">📞 <?= h($o['phone']) ?></span><?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600">
                                <?php if (!empty($items)): foreach ($items as $item): ?>
                                <div><?= h($item['title'] ?? $item['name'] ?? '') ?> × <?= (int)($item['qty'] ?? 1) ?></div>
                                <?php endforeach; endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm font-bold text-slate-800"><?= number_format($total, 0, ',', ' ') ?> ₽</td>
                            <td class="px-4 py-3 text-sm text-slate-500"><?= h(date('d.m.Y H:i', strtotime($o['created'] ?? 'now'))) ?></td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full <?= $st[1] ?>"><?= $st[0] ?></span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2 justify-end">
                                    <form method="POST" class="inline"><?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $o['id'] ?? 0 ?>">
                                        <select name="status" class="text-sm border border-slate-300 rounded-lg px-2 py-1" onchange="this.form.submit()">
                                            <option value="new" <?= ($o['status']??'new')==='new'?'selected':'' ?>>Новый</option>
                                            <option value="processing" <?= ($o['status']??'')==='processing'?'selected':'' ?>>В обработке</option>
                                            <option value="shipped" <?= ($o['status']??'')==='shipped'?'selected':'' ?>>Отправлен</option>
                                            <option value="done" <?= ($o['status']??'')==='done'?'selected':'' ?>>Завершён</option>
                                            <option value="cancelled" <?= ($o['status']??'')==='cancelled'?'selected':'' ?>>Отменён</option>
                                        </select>
                                    </form>
                                    <form method="POST" class="inline" onsubmit="return confirm('Удалить заказ?')"><?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $o['id'] ?? 0 ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-trash"></i><span class="btn-label">Удалить</span></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
</body>
</html>
