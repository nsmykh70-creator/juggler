<?php
/**
 * AICMS Admin — Shop Stock Management
 * ====================================
 * Stock movements log, low-stock alerts, manual stock adjustments.
 * Requires MySQL/SQLite.
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
    set_flash_message('Магазин требует базу данных (MySQL или SQLite).', 'danger');
    header('Location: settings.php?tab=database'); exit;
}

// === Handle POST actions ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $form_type = $_POST['form_type'] ?? '';

    if ($form_type === 'adjust_stock') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $change = (int)($_POST['change_qty'] ?? 0);
        $reason = $_POST['reason'] ?? 'manual';
        $note = trim($_POST['note'] ?? '');
        if ($pid > 0 && $change !== 0) {
            $product = db_find('shop_products', $pid);
            if ($product) {
                $new_stock = max(0, (int)$product['stock'] + $change);
                db_update('shop_products', ['stock' => $new_stock], ['id' => $pid]);
                // Record movement
                try {
                    db_insert('shop_stock_movements', [
                        'product_id' => $pid,
                        'change_qty' => $change,
                        'reason'     => $reason,
                        'order_id'   => 0,
                        'note'       => mb_substr($note, 0, 500),
                        'user'       => $_SESSION['admin'] ?? 'admin',
                    ]);
                } catch (Exception $e) {}
                audit_log('stock_adjust', "product_id={$pid} change={$change} reason={$reason}");
                set_flash_message('Склад обновлён: ' . ($change > 0 ? '+' : '') . $change . ' ед.', 'success');
            }
        }
        header('Location: shop_stock.php' . ($pid ? '?product=' . $pid : '')); exit;
    }

    if ($form_type === 'set_threshold') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $threshold = max(0, (int)($_POST['threshold'] ?? 5));
        if ($pid > 0) {
            try {
                db_update('shop_products', ['low_stock_threshold' => $threshold], ['id' => $pid]);
                set_flash_message('Порог предупреждения установлен: ' . $threshold . ' ед.', 'success');
            } catch (Exception $e) {
                set_flash_message('Не удалось сохранить порог (возможно, нет колонки).', 'danger');
            }
        }
        header('Location: shop_stock.php'); exit;
    }

    if ($form_type === 'bulk_adjust') {
        // Bulk adjustment: change stock for multiple products at once (inventory)
        $products_input = $_POST['products'] ?? [];
        $reason = $_POST['bulk_reason'] ?? 'inventory';
        $note = trim($_POST['bulk_note'] ?? '');
        $count_updated = 0;
        foreach ($products_input as $pid => $data) {
            $pid = (int)$pid;
            $new_qty = isset($data['new_stock']) ? (int)$data['new_stock'] : null;
            if ($new_qty === null || $pid <= 0) continue;
            $product = db_find('shop_products', $pid);
            if (!$product) continue;
            $old_qty = (int)$product['stock'];
            if ($new_qty === $old_qty) continue;
            $change = $new_qty - $old_qty;
            db_update('shop_products', ['stock' => $new_qty], ['id' => $pid]);
            try {
                db_insert('shop_stock_movements', [
                    'product_id' => $pid,
                    'change_qty' => $change,
                    'reason'     => $reason,
                    'order_id'   => 0,
                    'note'       => mb_substr($note, 0, 500),
                    'user'       => $_SESSION['admin'] ?? 'admin',
                ]);
            } catch (Exception $e) {}
            $count_updated++;
        }
        audit_log('stock_bulk_adjust', "updated={$count_updated} reason={$reason}");
        set_flash_message('Инвентаризация: обновлено ' . $count_updated . ' товаров.', 'success');
        header('Location: shop_stock.php?tab=inventory'); exit;
    }
}

// === Filters ===
$filter_product = isset($_GET['product']) ? (int)$_GET['product'] : 0;
$tab = $_GET['tab'] ?? 'overview';

// === Get data ===
// All products with stock info
$all_products = db_select('shop_products', 'id,title,sku,stock,price,status,low_stock_threshold', '1=1', [], 'title ASC');

// Low-stock products (stock <= threshold)
$low_stock = [];
foreach ($all_products as $p) {
    $threshold = (int)($p['low_stock_threshold'] ?? 5);
    if ((int)$p['stock'] <= $threshold && $p['status'] === 'active') {
        $low_stock[] = $p;
    }
}

// Out-of-stock
$out_of_stock = array_filter($all_products, fn($p) => (int)$p['stock'] === 0 && $p['status'] === 'active');

// Recent stock movements (last 50)
try {
    if ($filter_product > 0) {
        $movements = db_select('shop_stock_movements', '*', 'product_id = ?', [$filter_product], 'created_at DESC', 50);
    } else {
        $movements = db_select('shop_stock_movements', '*', '', [], 'created_at DESC', 50);
    }
} catch (Exception $e) {
    $movements = [];
}

// Build product map for movement display
$prod_map = [];
foreach ($all_products as $p) $prod_map[$p['id']] = $p;

// Stats
$total_products = count($all_products);
$total_stock_value = 0;
foreach ($all_products as $p) $total_stock_value += (float)$p['price'] * (int)$p['stock'];

$page_title = 'Склад магазина';
$breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>'Магазин','url'=>'shop.php'],['label'=>'Склад']];
$action_panel = '<a href="shop.php" class="aicms-icon-btn aicms-icon-btn--secondary"><i class="fas fa-arrow-left"></i><span class="btn-label">К товарам</span></a>';
$action_panel .= '<a href="shop_stock.php?tab=inventory" class="aicms-icon-btn aicms-icon-btn--info"><i class="fas fa-clipboard-check"></i><span class="btn-label">Инвентаризация</span></a>';
$action_panel .= '<a href="shop_stock.php?tab=alerts" class="aicms-icon-btn aicms-icon-btn--' . (count($low_stock) > 0 ? 'danger' : 'ghost') . '"><i class="fas fa-bell"></i><span class="btn-label">Предупреждения</span>' . (count($low_stock) > 0 ? '<span class="ml-auto text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-red-500 text-white">' . count($low_stock) . '</span>' : '') . '</a>';
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8">
    <title>Склад — AICMS</title>
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

        <!-- Stats cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <div class="bg-white rounded-xl shadow-sm p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center"><i class="fas fa-boxes"></i></div>
                <div><div class="text-xl font-bold text-slate-800"><?= $total_products ?></div><div class="text-xs text-slate-500">Товаров</div></div>
            </div>
            <div class="bg-white rounded-xl shadow-sm p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center"><i class="fas fa-exclamation-triangle"></i></div>
                <div><div class="text-xl font-bold text-slate-800"><?= count($low_stock) ?></div><div class="text-xs text-slate-500">Мало на складе</div></div>
            </div>
            <div class="bg-white rounded-xl shadow-sm p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-red-50 text-red-600 flex items-center justify-center"><i class="fas fa-times-circle"></i></div>
                <div><div class="text-xl font-bold text-slate-800"><?= count($out_of_stock) ?></div><div class="text-xs text-slate-500">Нет в наличии</div></div>
            </div>
            <div class="bg-white rounded-xl shadow-sm p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-green-50 text-green-600 flex items-center justify-center"><i class="fas fa-coins"></i></div>
                <div><div class="text-xl font-bold text-slate-800"><?= number_format($total_stock_value, 0, ',', ' ') ?> ₽</div><div class="text-xs text-slate-500">Сумма склада</div></div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex border-b border-slate-200 mb-4">
            <a href="shop_stock.php?tab=overview" class="px-4 py-2 text-sm font-medium border-b-2 <?= $tab==='overview'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700' ?>"><i class="fas fa-list mr-1"></i> Движение</a>
            <a href="shop_stock.php?tab=alerts" class="px-4 py-2 text-sm font-medium border-b-2 <?= $tab==='alerts'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700' ?>"><i class="fas fa-bell mr-1"></i> Предупреждения <?= count($low_stock) > 0 ? '<span class="ml-1 px-1.5 py-0.5 text-xs font-bold bg-red-100 text-red-700 rounded-full">' . count($low_stock) . '</span>' : '' ?></a>
            <a href="shop_stock.php?tab=inventory" class="px-4 py-2 text-sm font-medium border-b-2 <?= $tab==='inventory'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700' ?>"><i class="fas fa-clipboard-check mr-1"></i> Инвентаризация</a>
        </div>

        <?php if ($tab === 'overview'): ?>
        <!-- ===== MOVEMENTS LOG ===== -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200 font-semibold text-slate-700 flex items-center gap-2">
                <i class="fas fa-history text-blue-600"></i>
                <span>История движения товаров</span>
                <?php if ($filter_product > 0 && isset($prod_map[$filter_product])): ?>
                <span class="text-xs text-slate-400">· фильтр: <?= h($prod_map[$filter_product]['title']) ?></span>
                <a href="shop_stock.php" class="ml-2 text-xs text-blue-600">сбросить ×</a>
                <?php endif; ?>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50">
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Дата</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Товар</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Изменение</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Причина</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Комментарий</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Пользователь</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <?php if (empty($movements)): ?>
                        <tr><td colspan="6" class="px-4 py-12 text-center text-slate-400">
                            <i class="fas fa-inbox text-4xl text-slate-300 mb-3 block"></i>
                            Движений пока нет.
                        </td></tr>
                        <?php else: foreach ($movements as $m):
                            $prod = $prod_map[$m['product_id']] ?? null;
                            $reason_labels = [
                                'order'       => ['Заказ',           'bg-blue-100 text-blue-700'],
                                'manual'      => ['Ручное',          'bg-slate-100 text-slate-700'],
                                'inventory'   => ['Инвентаризация',  'bg-purple-100 text-purple-700'],
                                'return'      => ['Возврат',         'bg-amber-100 text-amber-700'],
                                'damage'      => ['Списано',         'bg-red-100 text-red-700'],
                                'restock'     => ['Поставка',        'bg-green-100 text-green-700'],
                            ];
                            $rl = $reason_labels[$m['reason'] ?? 'manual'] ?? $reason_labels['manual'];
                        ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-sm text-slate-500"><?= h(date('d.m.Y H:i', strtotime($m['created_at'] ?? 'now'))) ?></td>
                            <td class="px-4 py-3 text-sm">
                                <?php if ($prod): ?>
                                <a href="shop.php?tab=products&edit=<?= (int)$prod['id'] ?>" class="text-slate-800 font-medium hover:text-blue-600 no-underline"><?= h($prod['title']) ?></a>
                                <br><small class="text-slate-400"><?= h($prod['sku'] ?? '') ?></small>
                                <?php else: ?>
                                <span class="text-slate-400">Товар #<?= (int)$m['product_id'] ?> удалён</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <span class="font-mono font-bold <?= $m['change_qty'] > 0 ? 'text-green-600' : 'text-red-600' ?>">
                                    <?= $m['change_qty'] > 0 ? '+' : '' ?><?= (int)$m['change_qty'] ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full <?= $rl[1] ?>"><?= $rl[0] ?></span>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600"><?= h(mb_substr($m['note'] ?? '', 0, 80)) ?: '—' ?></td>
                            <td class="px-4 py-3 text-sm text-slate-500"><?= h($m['user'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Quick adjust form -->
        <div class="bg-white rounded-xl shadow-sm p-5 mt-4 max-w-md">
            <h4 class="font-semibold text-slate-700 mb-3 flex items-center gap-2"><i class="fas fa-plus-circle text-blue-600"></i> Быстрая корректировка</h4>
            <form method="POST" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="form_type" value="adjust_stock">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Товар</label>
                    <select name="product_id" required class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">— Выберите товар —</option>
                        <?php foreach ($all_products as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $filter_product === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['title']) ?> (на складе: <?= (int)$p['stock'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Изменение (+/−)</label>
                        <input type="number" name="change_qty" required value="0" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Причина</label>
                        <select name="reason" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="manual">Ручное изменение</option>
                            <option value="restock">Поставка</option>
                            <option value="return">Возврат</option>
                            <option value="damage">Списано (брак)</option>
                            <option value="inventory">Инвентаризация</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Комментарий</label>
                    <input type="text" name="note" maxlength="500" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500" placeholder="Например: приёмка по накладной №123">
                </div>
                <button type="submit" class="aicms-btn aicms-btn--success w-full"><i class="fas fa-check"></i> Применить</button>
            </form>
        </div>

        <?php elseif ($tab === 'alerts'): ?>
        <!-- ===== LOW STOCK ALERTS ===== -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200 font-semibold text-slate-700 flex items-center gap-2">
                <i class="fas fa-bell text-amber-600"></i>
                <span>Товары с низким остатком</span>
                <span class="ml-auto text-xs text-slate-400"><?= count($low_stock) ?> шт.</span>
            </div>
            <?php if (empty($low_stock)): ?>
            <div class="px-5 py-12 text-center text-slate-400">
                <i class="fas fa-check-circle text-4xl text-green-300 mb-3 block"></i>
                Все товары в достатке.
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50">
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Товар</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">SKU</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Остаток</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Порог</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Статус</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <?php foreach ($low_stock as $p):
                            $stock = (int)$p['stock'];
                            $thr = (int)($p['low_stock_threshold'] ?? 5);
                            $is_out = $stock === 0;
                        ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <a href="shop.php?tab=products&edit=<?= (int)$p['id'] ?>" class="text-sm font-medium text-slate-800 hover:text-blue-600 no-underline"><?= h($p['title']) ?></a>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-500 font-mono"><?= h($p['sku'] ?? '—') ?></td>
                            <td class="px-4 py-3 text-sm">
                                <span class="font-bold <?= $is_out ? 'text-red-600' : 'text-amber-600' ?>"><?= $stock ?></span>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-500"><?= $thr ?></td>
                            <td class="px-4 py-3">
                                <?php if ($is_out): ?>
                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-800">Нет в наличии</span>
                                <?php else: ?>
                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-amber-100 text-amber-800">Заканчивается</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <form method="POST" class="inline-flex gap-1 items-center">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="form_type" value="adjust_stock">
                                    <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                                    <input type="hidden" name="reason" value="restock">
                                    <input type="number" name="change_qty" value="10" class="w-16 px-2 py-1 text-sm border border-slate-300 rounded-lg" title="Сколько добавить">
                                    <button type="submit" class="aicms-icon-btn aicms-icon-btn--success" title="Пополнить"><i class="fas fa-plus"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Thresholds config -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden mt-4">
            <div class="px-5 py-3 border-b border-slate-200 font-semibold text-slate-700 flex items-center gap-2">
                <i class="fas fa-sliders-h text-blue-600"></i>
                <span>Пороги предупреждений</span>
            </div>
            <div class="p-4">
                <p class="text-sm text-slate-500 mb-3 m-0">Установите порог — когда остаток товара опустится ниже этого значения, он появится в предупреждениях.</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <?php foreach ($all_products as $p): ?>
                    <form method="POST" class="flex items-center gap-2 bg-slate-50 p-2 rounded-lg">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_type" value="set_threshold">
                        <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-slate-800 truncate"><?= h($p['title']) ?></div>
                            <div class="text-xs text-slate-400">Остаток: <?= (int)$p['stock'] ?> ед.</div>
                        </div>
                        <input type="number" name="threshold" value="<?= (int)($p['low_stock_threshold'] ?? 5) ?>" min="0" max="9999" class="w-20 px-2 py-1 text-sm border border-slate-300 rounded-lg" title="Пограничное значение">
                        <button type="submit" class="aicms-icon-btn aicms-icon-btn--primary" title="Сохранить"><i class="fas fa-save"></i></button>
                    </form>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php elseif ($tab === 'inventory'): ?>
        <!-- ===== INVENTORY (bulk stocktake) ===== -->
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-4 text-sm text-amber-800">
            <i class="fas fa-info-circle mr-1"></i>
            <strong>Инвентаризация.</strong>
            Пересчитайте фактические остатки и введите их в поле «Факт». При сохранении система автоматически создаст записи движения с причиной «Инвентаризация» для каждого изменённого товара.
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="form_type" value="bulk_adjust">
            <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-4">
                <div class="px-5 py-3 border-b border-slate-200 font-semibold text-slate-700 flex items-center gap-2">
                    <i class="fas fa-clipboard-check text-blue-600"></i>
                    <span>Список товаров</span>
                    <span class="ml-auto text-xs text-slate-400"><?= count($all_products) ?> шт.</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50">
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Товар</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">SKU</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">В системе</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Факт (пересчёт)</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Расхождение</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <?php foreach ($all_products as $p):
                                $stock = (int)$p['stock'];
                            ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 text-sm font-medium text-slate-800"><?= h($p['title']) ?></td>
                                <td class="px-4 py-3 text-sm text-slate-500 font-mono"><?= h($p['sku'] ?? '—') ?></td>
                                <td class="px-4 py-3 text-sm text-slate-700"><?= $stock ?></td>
                                <td class="px-4 py-3">
                                    <input type="number" name="products[<?= (int)$p['id'] ?>][new_stock]" value="<?= $stock ?>" data-system-stock="<?= $stock ?>" class="w-24 px-2 py-1 text-sm border border-slate-300 rounded-lg inv-input" oninput="updateDiff(this)">
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <span class="diff-display font-mono font-bold" data-system="<?= $stock ?>">0</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm p-4 max-w-md">
                <div class="mb-3">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Причина</label>
                    <input type="hidden" name="bulk_reason" value="inventory">
                    <input type="text" value="Инвентаризация" disabled class="w-full px-3 py-2 border border-slate-200 rounded-lg bg-slate-50 text-slate-500">
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Комментарий</label>
                    <input type="text" name="bulk_note" maxlength="500" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500" placeholder="Например: плановая инвентаризация за август">
                </div>
                <button type="submit" class="aicms-btn aicms-btn--success w-full" onclick="return confirm('Применить результаты инвентаризации? Будут созданы записи движения для изменённых товаров.')"><i class="fas fa-check"></i> Применить инвентаризацию</button>
            </div>
        </form>
        <script>
        function updateDiff(input) {
            var system = parseInt(input.dataset.systemStock, 10) || 0;
            var actual = parseInt(input.value, 10) || 0;
            var diff = actual - system;
            var display = input.closest('tr').querySelector('.diff-display');
            display.textContent = diff > 0 ? '+' + diff : diff;
            display.style.color = diff > 0 ? '#16a34a' : (diff < 0 ? '#dc2626' : '#64748b');
        }
        </script>
        <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
</body>
</html>
