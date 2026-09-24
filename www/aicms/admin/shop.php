<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/extensions.php';
require_once __DIR__ . '/core/shop_helpers.php';
require_auth();

if (!extension_enabled('shop')) {
    set_flash_message('Дополнение «Магазин» выключено. Включите его на странице Дополнения.', 'warning');
    header('Location: extensions.php'); exit;
}

$tab = $_GET['tab'] ?? 'dashboard';

// ===== Handle POST actions =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $form_type = $_POST['form_type'] ?? '';

    // Save / update product
    if ($form_type === 'product_save' && db_available()) {
        $id = (int)($_POST['id'] ?? 0);
        // Handle main image upload
        $image_url = trim($_POST['image'] ?? '');
        $uploaded = shop_upload_image('image_file');
        if ($uploaded) $image_url = $uploaded;
        // Handle gallery upload
        $gallery_urls = shop_upload_gallery('gallery_files');
        $existing_gallery = trim($_POST['existing_gallery'] ?? '');
        $all_gallery = array_filter(array_map('trim', explode("\n", $existing_gallery)));
        $all_gallery = array_merge($all_gallery, $gallery_urls);
        $gallery_json = !empty($all_gallery) ? json_encode(array_values($all_gallery), JSON_UNESCAPED_UNICODE) : '';

        $data = [
            'title' => trim($_POST['title'] ?? ''), 'slug' => trim($_POST['slug'] ?? ''),
            'description' => $_POST['description'] ?? '', 'short_desc' => trim($_POST['short_desc'] ?? ''),
            'price' => (float)str_replace(',', '.', $_POST['price'] ?? 0),
            'old_price' => (float)str_replace(',', '.', $_POST['old_price'] ?? 0),
            'cost_price' => (float)str_replace(',', '.', $_POST['cost_price'] ?? 0),
            'sku' => trim($_POST['sku'] ?? ''), 'brand' => trim($_POST['brand'] ?? ''),
            'image' => $image_url, 'gallery' => $gallery_json,
            'category_id' => (int)($_POST['category_id'] ?? 0),
            'stock' => (int)($_POST['stock'] ?? 0),
            'weight' => (float)str_replace(',', '.', $_POST['weight'] ?? 0),
            'status' => $_POST['status'] ?? 'active', 'position' => (int)($_POST['position'] ?? 0),
        ];
        if (empty($data['slug'])) $data['slug'] = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $data['title']));

        if ($id > 0) {
            db_update('shop_products', $data, ['id'=>$id]);
            set_flash_message('Товар обновлён.','success');
        } else {
            db_insert('shop_products', $data);
            set_flash_message('Товар создан.','success');
        }
        header('Location: shop.php?tab=products'); exit;
    }

    // Delete product
    if ($form_type === 'product_delete' && db_available()) {
        db_delete('shop_products', (int)($_POST['id'] ?? 0));
        set_flash_message('Товар удалён.','success');
        header('Location: shop.php?tab=products'); exit;
    }

    // Update order status
    if ($form_type === 'order_status' && db_available()) {
        $oid = (int)($_POST['id'] ?? 0);
        $new_status = $_POST['status'] ?? 'new';
        db_update('shop_orders', ['status'=>$new_status], ['id'=>$oid]);
        // If status changes to paid/shipped, decrement stock
        if (in_array($new_status, ['paid','shipped','done'])) {
            $order = db_find('shop_orders', $oid);
            if ($order && !empty($order['items'])) {
                $items = json_decode($order['items'], true);
                if (is_array($items)) {
                    foreach ($items as $item) {
                        $pid = (int)($item['id'] ?? 0);
                        $qty = (int)($item['qty'] ?? 1);
                        if ($pid > 0) {
                            try {
                                $prod = db_find('shop_products', $pid);
                                if ($prod) {
                                    $new_stock = max(0, (int)$prod['stock'] - $qty);
                                    $update_data = ['stock' => $new_stock];
                                    // Only update sales_count if column exists
                                    if (isset($prod['sales_count'])) {
                                        $update_data['sales_count'] = (int)$prod['sales_count'] + $qty;
                                    }
                                    db_update('shop_products', $update_data, ['id' => $pid]);
                                }
                            } catch(Exception $e) {}
                        }
                    }
                }
            }
        }
        set_flash_message('Статус заказа обновлён. Склад скорректирован.','success');
        header('Location: shop.php?tab=orders'); exit;
    }

    // Delete order
    if ($form_type === 'order_delete' && db_available()) {
        db_delete('shop_orders', (int)($_POST['id'] ?? 0));
        set_flash_message('Заказ удалён.','success');
        header('Location: shop.php?tab=orders'); exit;
    }

    // Save category
    if ($form_type === 'category_save' && db_available()) {
        $cat_id = (int)($_POST['cat_id'] ?? 0);
        $cat_data = [
            'title' => trim($_POST['cat_title'] ?? ''),
            'slug' => trim($_POST['cat_slug'] ?? ''),
            'parent_id' => (int)($_POST['cat_parent_id'] ?? 0),
            'position' => (int)($_POST['cat_position'] ?? 0),
        ];
        if (empty($cat_data['slug'])) $cat_data['slug'] = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $cat_data['title']));
        if ($cat_id > 0) { db_update('shop_categories', $cat_data, ['id'=>$cat_id]); set_flash_message('Категория обновлена.','success'); }
        else { db_insert('shop_categories', $cat_data); set_flash_message('Категория создана.','success'); }
        header('Location: shop.php?tab=categories'); exit;
    }

    // Delete category
    if ($form_type === 'category_delete' && db_available()) {
        db_delete('shop_categories', (int)($_POST['id'] ?? 0));
        set_flash_message('Категория удалена.','success');
        header('Location: shop.php?tab=categories'); exit;
    }

    // Batch product actions
    if ($form_type === 'product_batch' && db_available()) {
        $ids = array_map('intval', $_POST['ids'] ?? []);
        $ids = array_filter($ids);
        $batch = $_POST['batch_action'] ?? '';
        if (!empty($ids) && in_array($batch, ['delete','activate','hide'])) {
            $idList = implode(',', $ids);
            if ($batch === 'delete') { db_query('DELETE FROM ' . db_table('shop_products') . ' WHERE id IN (' . $idList . ')'); set_flash_message('Удалено: ' . count($ids), 'success'); }
            elseif ($batch === 'activate') { db_query('UPDATE ' . db_table('shop_products') . ' SET status = ? WHERE id IN (' . $idList . ')', ['active']); set_flash_message('Активировано: ' . count($ids), 'success'); }
            elseif ($batch === 'hide') { db_query('UPDATE ' . db_table('shop_products') . ' SET status = ? WHERE id IN (' . $idList . ')', ['hidden']); set_flash_message('Скрыто: ' . count($ids), 'success'); }
        }
        header('Location: shop.php?tab=products'); exit;
    }

    // Coupon save
    if ($form_type === 'coupon_save' && db_available()) {
        $code = strtoupper(trim($_POST['coupon_code'] ?? ''));
        $type = $_POST['coupon_type'] ?? 'percent';
        $value = (float)str_replace(',', '.', $_POST['coupon_value'] ?? 0);
        $min_total = (float)str_replace(',', '.', $_POST['coupon_min'] ?? 0);
        $max_uses = (int)($_POST['coupon_max_uses'] ?? 0);
        $expires = trim($_POST['coupon_expires'] ?? '');
        if (empty($code)) { set_flash_message('Код купона не может быть пустым.', 'danger'); header('Location: shop.php?tab=coupons'); exit; }
        $exists = db_row('SELECT id FROM ' . db_table('shop_coupons') . ' WHERE code = ? LIMIT 1', [$code]);
        if ($exists) { set_flash_message('Купон с таким кодом уже существует.', 'danger'); header('Location: shop.php?tab=coupons'); exit; }
        db_insert('shop_coupons', ['code'=>$code, 'type'=>$type, 'value'=>$value, 'min_total'=>$min_total, 'max_uses'=>$max_uses, 'uses_count'=>0, 'expires_at'=>$expires ?: null, 'status'=>'active']);
        set_flash_message('Купон «' . $code . '» создан.', 'success'); header('Location: shop.php?tab=coupons'); exit;
    }
    // Coupon delete
    if ($form_type === 'coupon_delete' && db_available()) {
        db_delete('shop_coupons', (int)($_POST['id'] ?? 0));
        set_flash_message('Купон удалён.', 'success'); header('Location: shop.php?tab=coupons'); exit;
    }
    // CSV export
    if ($form_type === 'csv_export_products' && db_available()) {
        $rows = db_select('shop_products', '*', '', [], 'id ASC');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=products.csv');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // BOM for Excel
        fputcsv($out, ['id','title','slug','sku','price','old_price','cost_price','stock','category_id','status','brand','weight','short_desc','description']);
        foreach ($rows as $r) { fputcsv($out, [$r['id'],$r['title'],$r['slug'],$r['sku'],$r['price'],$r['old_price'],$r['cost_price'],$r['stock'],$r['category_id'],$r['status'],$r['brand']??'',$r['weight']??0,$r['short_desc'],$r['description']]); }
        fclose($out); exit;
    }
    if ($form_type === 'csv_export_orders' && db_available()) {
        $rows = db_select('shop_orders', '*', '', [], 'id ASC');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=orders.csv');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['id','order_number','customer_name','customer_email','customer_phone','total','status','created_at']);
        foreach ($rows as $r) { fputcsv($out, [$r['id'],$r['order_number'],$r['customer_name'],$r['customer_email'],$r['customer_phone'],$r['total'],$r['status'],$r['created_at']]); }
        fclose($out); exit;
    }
    // CSV import
    if ($form_type === 'csv_import_products' && db_available() && !empty($_FILES['csv_file']['tmp_name'])) {
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $headers = fgetcsv($handle); // skip header
        $imported = 0;
        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) < 5) continue;
            $p_data = [
                'title' => $data[0] ?? '', 'slug' => $data[1] ?? '', 'sku' => $data[3] ?? '',
                'price' => (float)($data[4] ?? 0), 'old_price' => (float)($data[5] ?? 0),
                'cost_price' => (float)($data[6] ?? 0), 'stock' => (int)($data[7] ?? 0),
                'category_id' => (int)($data[8] ?? 0), 'status' => $data[9] ?? 'active',
                'brand' => $data[10] ?? '', 'weight' => (float)($data[11] ?? 0),
                'short_desc' => $data[12] ?? '', 'description' => $data[13] ?? '',
                'image' => '', 'gallery' => '',
            ];
            if (empty($p_data['slug'])) $p_data['slug'] = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $p_data['title']));
            try { db_insert('shop_products', $p_data); $imported++; } catch (Exception $e) {}
        }
        fclose($handle);
        set_flash_message("Импортировано товаров: $imported", 'success'); header('Location: shop.php?tab=import'); exit;
    }
}

// ===== Load data =====
$editing_product = null;
if ($tab === 'products' && isset($_GET['edit']) && db_available()) {
    try { $editing_product = db_find('shop_products', (int)$_GET['edit']); } catch (Exception $e) {}
}
$products = [];
$orders = [];
$categories = [];
if (db_available()) {
    try { $products = db_select('shop_products', '*', '', [], 'created_at DESC'); } catch (Exception $e) {}
    try { $orders = db_select('shop_orders', '*', '', [], 'created_at DESC', 100); } catch (Exception $e) {}
    try { $categories = db_select('shop_categories', '*', '', [], 'position ASC, title ASC'); } catch (Exception $e) {}
}

// Statistics
$stats = ['total_products' => 0, 'active_products' => 0, 'total_stock' => 0, 'low_stock' => 0, 'out_of_stock' => 0,
          'total_orders' => 0, 'new_orders' => 0, 'total_revenue' => 0, 'pending_revenue' => 0, 'total_sales_count' => 0];
$top_products = [];
if (db_available()) {
    try {
        $stats['total_products'] = db_count('shop_products');
        $stats['active_products'] = db_count('shop_products', 'status = ?', ['active']);
        $stats['total_orders'] = db_count('shop_orders');
        $stats['new_orders'] = db_count('shop_orders', 'status = ?', ['new']);
    } catch (Exception $e) {}
    try {
        $stock_row = db_row('SELECT SUM(stock) as s, SUM(CASE WHEN stock > 0 AND stock <= 5 THEN 1 ELSE 0 END) as low_stock_cnt, SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END) as out_cnt FROM ' . db_table('shop_products'));
        if ($stock_row) { $stats['total_stock'] = (int)($stock_row['s'] ?? 0); $stats['low_stock'] = (int)($stock_row['low_stock_cnt'] ?? 0); $stats['out_of_stock'] = (int)($stock_row['out_cnt'] ?? 0); }
    } catch (Exception $e) {}
    try {
        $rev_row = db_row("SELECT SUM(total) as r, SUM(CASE WHEN status IN ('paid','shipped','done') THEN total ELSE 0 END) as pr FROM " . db_table('shop_orders'));
        if ($rev_row) { $stats['total_revenue'] = (float)($rev_row['r'] ?? 0); $stats['pending_revenue'] = (float)($rev_row['pr'] ?? 0); }
    } catch (Exception $e) {}
    try {
        $sales_row = db_row('SELECT SUM(sales_count) as sc FROM ' . db_table('shop_products'));
        if ($sales_row) $stats['total_sales_count'] = (int)($sales_row['sc'] ?? 0);
    } catch (Exception $e) {}
    try {
        $top_products = db_select('shop_products', 'id, title, price, sales_count, stock, image', 'sales_count > 0', [], 'sales_count DESC', 5);
    } catch (Exception $e) { $top_products = []; }
}

ob_start();
?>
<span class="text-sm text-slate-500 self-center">
    Товаров: <strong class="text-slate-700"><?= $stats['total_products'] ?></strong> · Заказов: <strong class="text-slate-700"><?= $stats['total_orders'] ?></strong> · Выручка: <strong class="text-green-600"><?= number_format($stats['pending_revenue'], 2) ?> ₽</strong>
</span>
<?php $action_panel = ob_get_clean();
$page_title = 'Магазин';
$breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>'Магазин']];
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8"><title>Магазин — AICMS</title>
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
        <div class="bg-amber-50 text-amber-700 rounded-lg p-4 flex items-center gap-2"><i class="fas fa-exclamation-triangle"></i>База данных не подключена. Настройте в Настройки → База данных.</div>
        <?php else: ?>

        <!-- Tabs -->
        <div class="flex gap-1 mb-4 border-b border-slate-200">
            <a href="?tab=dashboard" class="px-4 py-2 text-sm font-medium border-b-2 <?= $tab==='dashboard'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700' ?>"><i class="fas fa-chart-line mr-1"></i> Обзор</a>
            <a href="?tab=products" class="px-4 py-2 text-sm font-medium border-b-2 <?= $tab==='products'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700' ?>"><i class="fas fa-box mr-1"></i> Товары (<?= $stats['total_products'] ?>)</a>
            <a href="?tab=orders" class="px-4 py-2 text-sm font-medium border-b-2 <?= $tab==='orders'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700' ?>"><i class="fas fa-clipboard-list mr-1"></i> Заказы (<?= $stats['total_orders'] ?>)<?php if($stats['new_orders']>0): ?> <span class="ml-1 px-1.5 py-0.5 text-[10px] bg-red-500 text-white rounded-full"><?= $stats['new_orders'] ?></span><?php endif; ?></a>
            <a href="?tab=categories" class="px-4 py-2 text-sm font-medium border-b-2 <?= $tab==='categories'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700' ?>"><i class="fas fa-tags mr-1"></i> Категории (<?= count($categories) ?>)</a>
            <a href="?tab=coupons" class="px-4 py-2 text-sm font-medium border-b-2 <?= $tab==='coupons'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700' ?>"><i class="fas fa-ticket-alt mr-1"></i> Купоны</a>
            <a href="shop_stock.php" class="px-4 py-2 text-sm font-medium border-b-2 border-transparent text-slate-500 hover:text-slate-700"><i class="fas fa-boxes mr-1"></i> Склад</a>
            <a href="shop_reviews.php" class="px-4 py-2 text-sm font-medium border-b-2 border-transparent text-slate-500 hover:text-slate-700"><i class="fas fa-star mr-1"></i> Отзывы</a>
            <a href="?tab=import" class="px-4 py-2 text-sm font-medium border-b-2 <?= $tab==='import'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700' ?>"><i class="fas fa-file-import mr-1"></i> Импорт/Экспорт</a>
        </div>

        <?php if ($tab === 'dashboard'): ?>
        <!-- ===== DASHBOARD / STATISTICS ===== -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="aicms-stat-card flex items-center">
                <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center mr-4 text-xl"><i class="fas fa-box"></i></div>
                <div><h5 class="text-2xl font-bold text-slate-800 m-0"><?= $stats['total_products'] ?></h5><p class="text-slate-500 text-sm m-0">Всего товаров</p></div>
            </div>
            <div class="aicms-stat-card flex items-center">
                <div class="w-12 h-12 rounded-xl bg-green-50 text-green-600 flex items-center justify-center mr-4 text-xl"><i class="fas fa-money-bill-wave"></i></div>
                <div><h5 class="text-2xl font-bold text-slate-800 m-0"><?= number_format($stats['pending_revenue'], 0) ?> ₽</h5><p class="text-slate-500 text-sm m-0">Выручка (подтвержд.)</p></div>
            </div>
            <div class="aicms-stat-card flex items-center">
                <div class="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center mr-4 text-xl"><i class="fas fa-shopping-cart"></i></div>
                <div><h5 class="text-2xl font-bold text-slate-800 m-0"><?= $stats['total_orders'] ?></h5><p class="text-slate-500 text-sm m-0">Заказов всего<?php if($stats['new_orders']>0): ?> (<?= $stats['new_orders'] ?> нов.)<?php endif; ?></p></div>
            </div>
            <div class="aicms-stat-card flex items-center">
                <div class="w-12 h-12 rounded-xl <?= $stats['low_stock']>0?'bg-red-50 text-red-600':'bg-slate-100 text-slate-600' ?> flex items-center justify-center mr-4 text-xl"><i class="fas fa-warehouse"></i></div>
                <div><h5 class="text-2xl font-bold text-slate-800 m-0"><?= $stats['total_stock'] ?></h5><p class="text-slate-500 text-sm m-0">На складе<?php if($stats['low_stock']>0): ?> <span class="text-red-600">(<?= $stats['low_stock'] ?> мало)</span><?php endif; ?></p></div>
            </div>
        </div>

        <?php if ($stats['low_stock'] > 0 || $stats['out_of_stock'] > 0): ?>
        <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6">
            <h4 class="text-sm font-bold text-red-800 m-0 mb-2"><i class="fas fa-exclamation-triangle mr-1"></i> Товары с низким остатком:</h4>
            <div class="space-y-1">
                <?php $low_products = db_select('shop_products', 'id, title, stock, price', 'stock <= 5', [], 'stock ASC', 10); ?>
                <?php foreach ($low_products as $lp): ?>
                <div class="flex justify-between text-sm">
                    <span><a href="?tab=products&edit=<?= $lp['id'] ?>" class="text-slate-700 hover:text-blue-600"><?= h($lp['title']) ?></a></span>
                    <span class="<?= $lp['stock']==0?'text-red-600 font-bold':'text-amber-600' ?>"><?= $lp['stock']==0?'Нет в наличии':'Осталось: '.$lp['stock'].' шт.' ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <!-- Top products -->
            <div class="bg-white rounded-xl shadow-sm p-5">
                <h4 class="text-sm font-bold text-slate-700 m-0 mb-3"><i class="fas fa-trophy text-amber-500 mr-1"></i> Топ продаваемых товаров</h4>
                <?php if (empty($top_products)): ?>
                <p class="text-slate-400 text-sm m-0 text-center py-4">Ещё нет продаж.</p>
                <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($top_products as $i => $tp): ?>
                    <div class="flex items-center gap-3">
                        <span class="text-lg font-bold text-slate-400 w-6"><?= $i+1 ?></span>
                        <?php if(!empty($tp['image'])): ?><img src="<?= h($tp['image']) ?>" class="w-10 h-10 object-cover rounded"><?php else: ?><div class="w-10 h-10 bg-slate-100 rounded flex items-center justify-center text-slate-400"><i class="fas fa-image"></i></div><?php endif; ?>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-slate-800 truncate"><?= h($tp['title']) ?></div>
                            <div class="text-xs text-slate-400"><?= number_format($tp['price'], 2) ?> ₽ · остаток: <?= (int)$tp['stock'] ?></div>
                        </div>
                        <span class="px-2 py-0.5 bg-green-100 text-green-800 text-xs font-semibold rounded-full"><?= (int)$tp['sales_count'] ?> прод.</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <!-- Recent orders -->
            <div class="bg-white rounded-xl shadow-sm p-5">
                <h4 class="text-sm font-bold text-slate-700 m-0 mb-3"><i class="fas fa-clock text-blue-500 mr-1"></i> Последние заказы</h4>
                <?php if (empty($orders)): ?>
                <p class="text-slate-400 text-sm m-0 text-center py-4">Заказов пока нет.</p>
                <?php else: ?>
                <div class="space-y-2">
                    <?php foreach (array_slice($orders, 0, 5) as $o): ?>
                    <div class="flex justify-between items-center text-sm">
                        <div>
                            <strong class="text-slate-800"><?= h($o['order_number']) ?></strong>
                            <span class="text-slate-500 ml-2"><?= h($o['customer_name']) ?></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-slate-700"><?= number_format($o['total'], 0) ?> ₽</span>
                            <span class="px-1.5 py-0.5 text-[10px] font-semibold rounded-full <?= ['new'=>'bg-blue-100 text-blue-800','paid'=>'bg-green-100 text-green-800','shipped'=>'bg-cyan-100 text-cyan-800','done'=>'bg-slate-200 text-slate-600','cancelled'=>'bg-red-100 text-red-800'][$o['status']] ?? 'bg-slate-100' ?>"><?= $o['status'] ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($tab === 'products'): ?>
        <!-- ===== PRODUCTS ===== -->
        <!-- Product Form -->
        <div class="bg-white rounded-xl shadow-sm mb-4">
            <div class="px-5 py-3 border-b border-slate-200 font-semibold text-slate-700"><?= $editing_product ? 'Редактировать товар' : 'Новый товар' ?></div>
            <div class="p-5">
                <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="form_type" value="product_save"><input type="hidden" name="id" value="<?= $editing_product['id'] ?? 0 ?>">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="md:col-span-2">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div><label class="block text-sm font-medium text-slate-700 mb-1">Название *</label><input type="text" name="title" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none" value="<?= h($editing_product['title'] ?? '') ?>" required></div>
                                <div><label class="block text-sm font-medium text-slate-700 mb-1">Slug (URL)</label><input type="text" name="slug" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" value="<?= h($editing_product['slug'] ?? '') ?>"></div>
                                <div><label class="block text-sm font-medium text-slate-700 mb-1">SKU (артикул)</label><input type="text" name="sku" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" value="<?= h($editing_product['sku'] ?? '') ?>"></div>
                                <div><label class="block text-sm font-medium text-slate-700 mb-1">Бренд</label><input type="text" name="brand" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" value="<?= h($editing_product['brand'] ?? '') ?>"></div>
                                <div><label class="block text-sm font-medium text-slate-700 mb-1">Цена *</label><input type="text" name="price" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" value="<?= h($editing_product['price'] ?? '0') ?>" required></div>
                                <div><label class="block text-sm font-medium text-slate-700 mb-1">Старая цена (для скидки)</label><input type="text" name="old_price" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" value="<?= h($editing_product['old_price'] ?? '0') ?>"></div>
                                <div><label class="block text-sm font-medium text-slate-700 mb-1">Закупочная цена</label><input type="text" name="cost_price" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" value="<?= h($editing_product['cost_price'] ?? '0') ?>"></div>
                                <div><label class="block text-sm font-medium text-slate-700 mb-1">Вес (кг)</label><input type="text" name="weight" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" value="<?= h($editing_product['weight'] ?? '0') ?>"></div>
                                <div><label class="block text-sm font-medium text-slate-700 mb-1">Склад (шт) *</label><input type="number" name="stock" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" value="<?= h($editing_product['stock'] ?? 0) ?>" required></div>
                                <div><label class="block text-sm font-medium text-slate-700 mb-1">Категория</label>
                                    <select name="category_id" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none">
                                        <option value="0">— Без категории —</option>
                                        <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" <?= ($editing_product['category_id'] ?? 0)==$cat['id']?'selected':'' ?>><?= h($cat['title']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div><label class="block text-sm font-medium text-slate-700 mb-1">Статус</label><select name="status" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none"><option value="active" <?= ($editing_product['status'] ?? '')==='active'?'selected':'' ?>>Активен</option><option value="hidden" <?= ($editing_product['status'] ?? '')==='hidden'?'selected':'' ?>>Скрыт</option></select></div>
                            </div>
                            <div class="mt-3"><label class="block text-sm font-medium text-slate-700 mb-1">Короткое описание</label><input type="text" name="short_desc" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" value="<?= h($editing_product['short_desc'] ?? '') ?>"></div>
                            <div class="mt-3"><label class="block text-sm font-medium text-slate-700 mb-1">Полное описание</label><textarea name="description" rows="4" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none"><?= h($editing_product['description'] ?? '') ?></textarea></div>
                        </div>
                        <!-- Image column -->
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Главное изображение</label>
                            <div class="border-2 border-dashed border-slate-300 rounded-lg p-4 text-center mb-2">
                                <?php $main_img = $editing_product['image'] ?? ''; ?>
                                <?php if (!empty($main_img)): ?>
                                <img src="<?= h($main_img) ?>" class="w-full h-32 object-cover rounded mb-2" id="mainImagePreview">
                                <?php else: ?>
                                <div class="w-full h-32 bg-slate-50 rounded flex items-center justify-center text-slate-400 mb-2" id="mainImagePreview"><i class="fas fa-image text-3xl"></i></div>
                                <?php endif; ?>
                                <input type="file" name="image_file" accept="image/*" onchange="previewMain(this)" class="text-xs">
                            </div>
                            <input type="text" name="image" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none text-xs mb-3" value="<?= h($main_img) ?>" placeholder="или вставьте URL">

                            <label class="block text-sm font-medium text-slate-700 mb-1">Галерея (несколько изображений)</label>
                            <input type="file" name="gallery_files[]" accept="image/*" multiple class="text-xs mb-2">
                            <?php
                            $gallery_images = [];
                            if (!empty($editing_product['gallery'])) {
                                $gallery_images = json_decode($editing_product['gallery'], true) ?: [];
                            }
                            ?>
                            <?php if (!empty($gallery_images)): ?>
                            <div class="grid grid-cols-3 gap-1 mb-2">
                                <?php foreach ($gallery_images as $gi): ?>
                                <img src="<?= h($gi) ?>" class="w-full h-14 object-cover rounded">
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <textarea name="existing_gallery" rows="3" class="w-full px-2 py-1 border border-slate-300 rounded-lg outline-none text-xs font-mono" placeholder="Существующие URL галереи (по одному на строку)"><?= h(implode("\n", $gallery_images)) ?></textarea>
                        </div>
                    </div>
                    <div class="mt-4 text-right"><?php if ($editing_product): ?><a href="?tab=products" class="aicms-btn aicms-btn--secondary mr-2">Отмена</a><?php endif; ?><button type="submit" class="aicms-icon-btn aicms-icon-btn--success"><i class="fas fa-save"></i><span class="btn-label">Сохранить</span></button></div>
                </form>
            </div>
        </div>

        <!-- Products table with batch actions -->
        <form method="POST" id="productsForm"><?= csrf_field() ?><input type="hidden" name="form_type" value="product_batch">
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200 flex justify-between items-center flex-wrap gap-2">
                <span class="font-semibold text-slate-700 text-sm">Товары (<?= count($products) ?>)</span>
                <?php if (!empty($products)): ?>
                <div class="flex gap-2 flex-wrap">
                    <button type="button" onclick="document.querySelectorAll('#productsForm input[type=checkbox]').forEach(c=>c.checked=true)" class="text-xs px-2 py-1 bg-slate-100 rounded border hover:bg-slate-200">Выделить все</button>
                    <button type="submit" name="batch_action" value="activate" class="text-xs px-3 py-1 bg-green-600 text-white rounded hover:bg-green-700">Активировать</button>
                    <button type="submit" name="batch_action" value="hide" class="text-xs px-3 py-1 bg-amber-600 text-white rounded hover:bg-amber-700">Скрыть</button>
                    <button type="submit" name="batch_action" value="delete" class="text-xs px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700" onclick="return confirm('Удалить выбранные товары?')">Удалить</button>
                </div>
                <?php endif; ?>
            </div>
            <div class="overflow-x-auto"><table class="w-full">
                <thead><tr class="border-b border-slate-200 bg-slate-50">
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase w-8"></th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Товар</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Цена</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Склад</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Продано</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Статус</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase">Действия</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-200">
                <?php if (empty($products)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-slate-400">Товаров пока нет. Создайте первый выше.</td></tr>
                <?php else: foreach ($products as $p): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3"><input type="checkbox" name="ids[]" value="<?= $p['id'] ?>" class="w-4 h-4 rounded border-slate-300"></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <?php if(!empty($p['image'])): ?><img src="<?= h($p['image']) ?>" class="w-10 h-10 object-cover rounded"><?php else: ?><div class="w-10 h-10 bg-slate-100 rounded flex items-center justify-center text-slate-400"><i class="fas fa-image text-xs"></i></div><?php endif; ?>
                            <div><strong class="text-sm text-slate-800"><?= h($p['title']) ?></strong><?php if(!empty($p['sku'])): ?><br><small class="text-slate-400">SKU: <?= h($p['sku']) ?></small><?php endif; ?></div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-700"><?= number_format($p['price'], 2) ?> ₽<?php if($p['old_price']>0): ?><br><small class="text-slate-400 line-through"><?= number_format($p['old_price'], 2) ?> ₽</small><?php endif; ?></td>
                    <td class="px-4 py-3 text-sm <?= (int)$p['stock']==0?'text-red-600 font-bold':((int)$p['stock']<=5?'text-amber-600 font-bold':'text-slate-700') ?>"><?= (int)$p['stock'] ?> шт.</td>
                    <td class="px-4 py-3 text-sm text-slate-700"><?= (int)($p['sales_count'] ?? 0) ?></td>
                    <td class="px-4 py-3"><span class="px-2 py-0.5 text-xs font-semibold rounded-full <?= $p['status']==='active'?'bg-green-100 text-green-800':'bg-slate-200 text-slate-600' ?>"><?= $p['status']==='active'?'Активен':'Скрыт' ?></span></td>
                    <td class="px-4 py-3"><div class="flex gap-1 justify-end">
                        <a href="?tab=products&edit=<?= $p['id'] ?>" class="aicms-act-outline aicms-act-outline--primary" ><i class="fas fa-edit"></i><span class="btn-label">Редактировать</span></a>
                        <button type="submit" name="form_type" value="product_delete" formmethod="POST" class="aicms-act-outline aicms-act-outline--danger" onclick="return confirm('Удалить товар?')"><input type="hidden" name="id" value="<?= $p['id'] ?>"><i class="fas fa-trash"></i><span class="btn-label">Удалить</span></button>
                    </div></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table></div>
        </div>
        </form>

        <?php elseif ($tab === 'orders'): ?>
        <!-- ===== ORDERS ===== -->
        <?php if (empty($orders)): ?>
        <div class="bg-blue-50 text-blue-700 rounded-lg p-4 flex items-center gap-2"><i class="fas fa-info-circle"></i>Заказов пока нет.</div>
        <?php else: ?>
        <div class="bg-white rounded-xl shadow-sm overflow-hidden"><div class="overflow-x-auto"><table class="w-full">
            <thead><tr class="border-b border-slate-200 bg-slate-50"><th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">№</th><th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Клиент</th><th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Состав</th><th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Сумма</th><th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Дата</th><th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Статус</th><th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase">Действие</th></tr></thead>
            <tbody class="divide-y divide-slate-200">
            <?php foreach ($orders as $o): $st_colors = ['new'=>'bg-blue-100 text-blue-800','paid'=>'bg-green-100 text-green-800','shipped'=>'bg-cyan-100 text-cyan-800','done'=>'bg-slate-200 text-slate-600','cancelled'=>'bg-red-100 text-red-800']; ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 text-sm font-semibold text-slate-800"><?= h($o['order_number']) ?></td>
                <td class="px-4 py-3 text-sm"><?= h($o['customer_name']) ?><br><small class="text-slate-400"><?= h($o['customer_email']) ?><?php if(!empty($o['customer_phone'])): ?> · <?= h($o['customer_phone']) ?><?php endif; ?></small></td>
                <td class="px-4 py-3 text-xs text-slate-500"><?php $items = json_decode($o['items'], true); if(is_array($items)) { $parts = []; foreach($items as $it) { $parts[] = h($it['title']).' ×'.(int)$it['qty']; } echo implode('<br>', $parts); } ?></td>
                <td class="px-4 py-3 text-sm font-semibold text-slate-700"><?= number_format($o['total'], 2) ?> ₽</td>
                <td class="px-4 py-3 text-xs text-slate-400"><?= h($o['created_at']) ?></td>
                <td class="px-4 py-3"><span class="px-2 py-0.5 text-xs font-semibold rounded-full <?= $st_colors[$o['status']] ?? 'bg-slate-100' ?>"><?= $o['status'] ?></span></td>
                <td class="px-4 py-3">
                    <form method="POST" class="inline flex gap-1 items-center"><?= csrf_field() ?><input type="hidden" name="form_type" value="order_status"><input type="hidden" name="id" value="<?= $o['id'] ?>"><select name="status" class="text-xs border border-slate-300 rounded-lg px-2 py-1" onchange="this.form.submit()"><option value="new" <?= $o['status']==='new'?'selected':'' ?>>Новый</option><option value="paid" <?= $o['status']==='paid'?'selected':'' ?>>Оплачен</option><option value="shipped" <?= $o['status']==='shipped'?'selected':'' ?>>Отправлен</option><option value="done" <?= $o['status']==='done'?'selected':'' ?>>Завершён</option><option value="cancelled" <?= $o['status']==='cancelled'?'selected':'' ?>>Отменён</option></select></form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
        <?php endif; ?>

        <?php elseif ($tab === 'categories'): ?>
        <!-- ===== CATEGORIES ===== -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white rounded-xl shadow-sm p-5">
                <h4 class="font-semibold text-slate-700 m-0 mb-3"><?= isset($_GET['edit_cat']) ? 'Редактировать' : 'Новая категория' ?></h4>
                <?php $edit_cat = isset($_GET['edit_cat']) && db_available() ? db_find('shop_categories', (int)$_GET['edit_cat']) : null; ?>
                <form method="POST"><?= csrf_field() ?><input type="hidden" name="form_type" value="category_save"><input type="hidden" name="cat_id" value="<?= $edit_cat['id'] ?? 0 ?>">
                    <div class="mb-3"><label class="block text-sm font-medium text-slate-700 mb-1">Название *</label><input type="text" name="cat_title" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" value="<?= h($edit_cat['title'] ?? '') ?>" required></div>
                    <div class="mb-3"><label class="block text-sm font-medium text-slate-700 mb-1">Slug</label><input type="text" name="cat_slug" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" value="<?= h($edit_cat['slug'] ?? '') ?>"></div>
                    <div class="mb-3"><label class="block text-sm font-medium text-slate-700 mb-1">Родительская</label><select name="cat_parent_id" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none"><option value="0">— Нет —</option><?php foreach($categories as $c): if($edit_cat && $c['id']==$edit_cat['id']) continue; ?><option value="<?= $c['id'] ?>" <?= ($edit_cat['parent_id'] ?? 0)==$c['id']?'selected':'' ?>><?= h($c['title']) ?></option><?php endforeach; ?></select></div>
                    <div class="mb-3"><label class="block text-sm font-medium text-slate-700 mb-1">Позиция</label><input type="number" name="cat_position" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" value="<?= h($edit_cat['position'] ?? 0) ?>"></div>
                    <button type="submit" class="aicms-icon-btn aicms-icon-btn--success"><i class="fas fa-save"></i><span class="btn-label">Сохранить</span></button>
                </form>
            </div>
            <div class="bg-white rounded-xl shadow-sm">
                <div class="px-5 py-3 border-b border-slate-200 font-semibold text-slate-700 text-sm">Список категорий</div>
                <?php if (empty($categories)): ?><p class="px-5 py-4 text-slate-400 text-sm m-0">Категорий пока нет.</p>
                <?php else: ?>
                <div class="divide-y divide-slate-100">
                    <?php foreach ($categories as $c): $prod_count = 0; try { $prod_count = db_count('shop_products', 'category_id = ?', [$c['id']]); } catch (Exception $e) {} ?>
                    <div class="px-5 py-3 flex justify-between items-center">
                        <div><strong class="text-sm text-slate-800"><?= h($c['title']) ?></strong><br><small class="text-slate-400"><?= $prod_count ?> товаров</small></div>
                        <div class="flex gap-1">
                            <a href="?tab=categories&edit_cat=<?= $c['id'] ?>" class="aicms-act-outline aicms-act-outline--primary" ><i class="fas fa-edit"></i><span class="btn-label">Редактировать</span></a>
                            <form method="POST" class="inline" onsubmit="return confirm('Удалить категорию?')"><?= csrf_field() ?><input type="hidden" name="form_type" value="category_delete"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-trash"></i><span class="btn-label">Удалить</span></button></form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($tab === 'coupons'): ?>
        <!-- ===== COUPONS ===== -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
            <!-- New coupon form -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-200 flex items-center gap-2">
                    <i class="fas fa-plus-circle text-blue-600"></i>
                    <h4 class="font-semibold text-slate-700 m-0">Новый купон</h4>
                </div>
                <form method="POST" class="p-5"><?= csrf_field() ?><input type="hidden" name="form_type" value="coupon_save">
                    <!-- Row 1: Code + Type (2 cols) -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Код <span class="text-red-500">*</span></label>
                            <input type="text" name="coupon_code" required class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500 uppercase font-mono" placeholder="SUMMER2025" style="text-transform:uppercase">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Тип скидки</label>
                            <select name="coupon_type" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="percent">Процент (%)</option>
                                <option value="fixed">Фиксированная сумма (₽)</option>
                            </select>
                        </div>
                    </div>
                    <!-- Row 2: Value + Min + Max uses (3 cols) -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Значение <span class="text-red-500">*</span></label>
                            <input type="number" step="any" name="coupon_value" required class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500" value="10">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Мин. сумма (₽)</label>
                            <input type="number" step="any" name="coupon_min" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500" value="0">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Макс. использований</label>
                            <input type="number" name="coupon_max_uses" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500" value="0" placeholder="0 = без лимита">
                        </div>
                    </div>
                    <!-- Row 3: Expiration (full width) -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Срок действия</label>
                        <input type="datetime-local" name="coupon_expires" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-xs text-slate-400 mt-1 m-0">Оставьте пустым для купона без срока действия.</p>
                    </div>
                    <!-- Submit (right-aligned, full label button) -->
                    <div class="text-right pt-2 border-t border-slate-100">
                        <button type="submit" class="aicms-btn aicms-btn--success">
                            <i class="fas fa-plus"></i> Создать купон
                        </button>
                    </div>
                </form>
            </div>

            <!-- Active coupons list -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-200 flex items-center gap-2">
                    <i class="fas fa-ticket-alt text-amber-600"></i>
                    <h4 class="font-semibold text-slate-700 m-0 text-sm">Активные купоны</h4>
                    <?php $coupons = []; try { $coupons = db_select('shop_coupons', '*', '', [], 'created_at DESC'); } catch(Exception $e) {} ?>
                    <span class="ml-auto text-xs text-slate-400"><?= count($coupons) ?> шт.</span>
                </div>
                <?php if (empty($coupons)): ?>
                <div class="px-5 py-12 text-center">
                    <i class="fas fa-ticket-alt text-4xl text-slate-300 mb-3 block"></i>
                    <p class="text-slate-400 text-sm m-0">Купонов пока нет.</p>
                    <p class="text-slate-400 text-xs m-0 mt-1">Создайте первый купон в форме слева.</p>
                </div>
                <?php else: ?>
                <div class="divide-y divide-slate-100">
                    <?php foreach ($coupons as $cp): ?>
                    <div class="px-5 py-4 flex justify-between items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 mb-1 flex-wrap">
                                <span class="font-mono font-bold text-blue-600 text-sm bg-blue-50 px-2 py-0.5 rounded"><?= h($cp['code']) ?></span>
                                <span class="text-sm font-semibold text-slate-700"><?= $cp['type']==='percent' ? $cp['value'].'%' : $cp['value'].' ₽' ?></span>
                                <?php if (!empty($cp['expires_at']) && strtotime($cp['expires_at']) < time()): ?>
                                <span class="text-xs px-1.5 py-0.5 bg-red-100 text-red-700 rounded">Истёк</span>
                                <?php endif; ?>
                            </div>
                            <small class="text-slate-400 text-xs block">
                                Использован: <strong class="text-slate-600"><?= (int)$cp['uses_count'] ?></strong><?= $cp['max_uses']>0 ? ' / '.$cp['max_uses'] : ' / ∞' ?>
                                <?php if($cp['expires_at']): ?>
                                · до <?= h(date('d.m.Y H:i', strtotime($cp['expires_at']))) ?>
                                <?php endif; ?>
                                <?php if($cp['min_total']>0): ?>
                                · мин. <?= h($cp['min_total']) ?> ₽
                                <?php endif; ?>
                            </small>
                        </div>
                        <form method="POST" class="inline" onsubmit="return confirm('Удалить купон «<?= h($cp['code']) ?>»?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_type" value="coupon_delete">
                            <input type="hidden" name="id" value="<?= $cp['id'] ?>">
                            <button class="aicms-icon-btn aicms-icon-btn--danger" title="Удалить купон">
                                <i class="fas fa-trash"></i><span class="btn-label">Удалить</span>
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($tab === 'import'): ?>
        <!-- ===== IMPORT / EXPORT ===== -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white rounded-xl shadow-sm p-5">
                <h4 class="font-semibold text-slate-700 m-0 mb-3"><i class="fas fa-download text-green-600 mr-1"></i>Экспорт</h4>
                <p class="text-sm text-slate-500 mb-4">Выгрузите данные в CSV-файл для работы в Excel/Google Sheets.</p>
                <form method="POST" class="space-y-2">
                    <?= csrf_field() ?>
                    <button type="submit" name="form_type" value="csv_export_products" class="aicms-icon-btn aicms-icon-btn--success"><i class="fas fa-file-csv"></i><span class="btn-label">Экспорт товаров (CSV)</span></button>
                    <button type="submit" name="form_type" value="csv_export_orders" class="aicms-icon-btn aicms-icon-btn--info"><i class="fas fa-file-csv"></i><span class="btn-label">Экспорт заказов (CSV)</span></button>
                </form>
            </div>
            <div class="bg-white rounded-xl shadow-sm p-5">
                <h4 class="font-semibold text-slate-700 m-0 mb-3"><i class="fas fa-upload text-blue-600 mr-1"></i>Импорт товаров</h4>
                <p class="text-sm text-slate-500 mb-2">CSV-файл со столбцами: <code class="text-xs">title, slug, sku, price, old_price, cost_price, stock, category_id, status, brand, weight, short_desc, description</code></p>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_type" value="csv_import_products">
                    <input type="file" name="csv_file" accept=".csv" required class="w-full text-sm mb-3 border border-slate-300 rounded-lg p-2">
                    <button type="submit" class="aicms-icon-btn aicms-icon-btn--primary"><i class="fas fa-file-import"></i><span class="btn-label">Импортировать</span></button>
                </form>
            </div>
        </div>

        <?php endif; ?>
        <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
<script>
function previewMain(input) {
    var preview = document.getElementById('mainImagePreview');
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            if (preview.tagName === 'IMG') { preview.src = e.target.result; }
            else { preview.innerHTML = '<img src="' + e.target.result + '" class="w-full h-32 object-cover rounded">'; }
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
</body>
</html>
