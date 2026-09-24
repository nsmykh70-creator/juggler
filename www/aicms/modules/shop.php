<?php
/**
 * Module: Mini Shop
 * ==================
 * A simple product catalog with shopping cart and order checkout.
 * Requires MySQL or SQLite.
 *
 * Usage: {{module:shop}}
 *
 * Features:
 *   - Product grid with images, prices, descriptions
 *   - Category filtering
 *   - Session-based cart (no registration needed)
 *   - Checkout form (name, email, phone, address)
 *   - Order saved to shop_orders table
 *   - Simple stock management (decrement on order)
 *
 * URL parameters:
 *   ?shop=cart           — view cart
 *   ?shop=checkout       — checkout form
 *   ?shop=product&id=X   — single product view
 *   ?shop=cat&id=X       — filter by category
 *   ?shop=add (POST)     — add to cart (id + csrf_token)
 *   ?shop=remove (POST)  — remove from cart (id + csrf_token)
 *   ?shop=order          — place order (POST, csrf_token + honeypot + rate limit)
 */

require_once __DIR__ . '/../admin/core/db.php';
require_once __DIR__ . '/../admin/core/module_settings.php';
require_once __DIR__ . '/../admin/core/extensions.php';

// Check if extension is enabled
if (!extension_enabled('shop')) {
    echo '';
    return;
}

$s = module_get_settings('shop', [
    'title'         => 'Каталог товаров',
    'currency'      => '₽',
    'per_page'      => 12,
    'show_stock'    => true,
    'checkout_email'=> 'admin@example.com',
    'shipping_enabled' => false,
    'shipping_free_from' => 5000,
    'shipping_base_cost' => 300,
    'shipping_per_kg' => 50,
    'payment_method' => 'cod',
    'yookassa_shop_id' => '',
    'yookassa_secret' => '',
    'stripe_key' => '',
    'stripe_secret' => '',
]);

// Start session for cart if not already started
if (session_status() === PHP_SESSION_NONE) session_start();

// Initialize cart
if (!isset($_SESSION['shop_cart'])) $_SESSION['shop_cart'] = [];

// Public form protection: CSRF token + honeypot/time-trap/rate-limit helpers
require_once __DIR__ . '/../admin/core/form_protection.php';
if (empty($_SESSION['site_csrf'])) {
    $_SESSION['site_csrf'] = bin2hex(random_bytes(32));
}
$shop_csrf = $_SESSION['site_csrf'];

if (!function_exists('shop_csrf_ok')) {
    function shop_csrf_ok() {
        return isset($_POST['csrf_token']) && hash_equals($_SESSION['site_csrf'] ?? '', (string)$_POST['csrf_token']);
    }
}

$action = $_GET['shop'] ?? '';
$cur = htmlspecialchars($s['currency']);

// ===== Handle cart actions (POST + CSRF only — no more state changes via GET) =====
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST' && db_available()) {
    if (!shop_csrf_ok()) { header('Location: ?shop='); exit; }
    $pid = (int)($_POST['id'] ?? 0);
    $product = db_find('shop_products', $pid);
    if ($product && $product['status'] === 'active') {
        $current_qty = $_SESSION['shop_cart'][$pid]['qty'] ?? 0;
        // Check stock limit
        if ((int)$product['stock'] > 0 && $current_qty >= (int)$product['stock']) {
            // Cannot add more than in stock
            header('Location: ?shop=cart&stock_error=1');
            exit;
        }
        if (!isset($_SESSION['shop_cart'][$pid])) {
            $_SESSION['shop_cart'][$pid] = ['qty' => 1, 'title' => $product['title'], 'price' => $product['price']];
        } else {
            $_SESSION['shop_cart'][$pid]['qty']++;
        }
    }
    header('Location: ?shop=cart');
    exit;
}

if ($action === 'remove' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!shop_csrf_ok()) { header('Location: ?shop=cart'); exit; }
    $pid = (int)($_POST['id'] ?? 0);
    unset($_SESSION['shop_cart'][$pid]);
    header('Location: ?shop=cart');
    exit;
}

// Apply coupon
if ($action === 'apply_coupon' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!shop_csrf_ok()) { header('Location: ?shop=cart'); exit; }
    $_SESSION['shop_coupon'] = strtoupper(trim($_POST['coupon_code'] ?? ''));
    header('Location: ?shop=cart');
    exit;
}

// ===== Handle order submission =====
$order_success = false;
if ($action === 'order' && $_SERVER['REQUEST_METHOD'] === 'POST' && db_available()) {
    $name = trim($_POST['ord_name'] ?? '');
    $email = trim($_POST['ord_email'] ?? '');
    $phone = trim($_POST['ord_phone'] ?? '');
    $address = trim($_POST['ord_address'] ?? '');
    $notes = trim($_POST['ord_notes'] ?? '');

    // Validate email format (blocks CRLF/header injection too — FILTER_VALIDATE_EMAIL rejects newlines)
    $email_valid = filter_var($email, FILTER_VALIDATE_EMAIL);
    // Defense in depth: strip CR/LF/NUL from email before it ever reaches mail() headers
    $email_safe = str_replace(["\r", "\n", "\0"], '', $email);

    // Security gates: CSRF + honeypot/time-trap + rate limit
    $order_guard = form_guard_full_check('shop_order');
    $order_csrf_ok = shop_csrf_ok();

    if (!$order_csrf_ok) {
        $order_error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    } elseif ($order_guard['silent']) {
        // Bot caught by honeypot/time-trap — pretend success, don't process
        $_SESSION['shop_cart'] = [];
        $_SESSION['shop_coupon'] = '';
        $order_success = true;
    } elseif (!$order_guard['ok']) {
        $order_error = $order_guard['message'];
    } elseif (empty($name) || !$email_valid || empty($_SESSION['shop_cart'])) {
        $order_error = 'Заполните корректное имя, email и добавьте товары в корзину.';
    } else {
        $items = [];
        $total = 0;
        foreach ($_SESSION['shop_cart'] as $pid => $item) {
            $items[] = ['id' => $pid, 'title' => $item['title'], 'qty' => $item['qty'], 'price' => $item['price']];
            $total += $item['price'] * $item['qty'];
        }
        // Apply coupon discount
        $coupon_discount = 0;
        $coupon_code = $_SESSION['shop_coupon'] ?? '';
        if (!empty($coupon_code)) {
            try {
                $cp = db_row('SELECT * FROM ' . db_table('shop_coupons') . ' WHERE code = ? AND status = ? LIMIT 1', [strtoupper($coupon_code), 'active']);
                if ($cp && ($cp['max_uses'] == 0 || $cp['uses_count'] < $cp['max_uses'])) {
                    if ($cp['type'] === 'percent') { $coupon_discount = $total * ($cp['value'] / 100); }
                    else { $coupon_discount = min($cp['value'], $total); }
                    // Increment coupon usage
                    db_update('shop_coupons', ['uses_count' => (int)$cp['uses_count'] + 1], ['id' => $cp['id']]);
                }
            } catch (Exception $e) {}
        }
        $total = $total - $coupon_discount;

        // Calculate shipping
        $shipping_cost = 0;
        $payment_method = $_POST['payment_method'] ?? 'cod';
        if (!empty($s['shipping_enabled'])) {
            if ($total >= (float)$s['shipping_free_from']) {
                $shipping_cost = 0; // free shipping
            } else {
                $total_weight = 0;
                foreach ($_SESSION['shop_cart'] as $pid => $item) {
                    $prod = db_find('shop_products', $pid);
                    if ($prod) { $total_weight += (float)($prod['weight'] ?? 0) * $item['qty']; }
                }
                $shipping_cost = (float)$s['shipping_base_cost'] + ($total_weight * (float)$s['shipping_per_kg']);
            }
            $total += $shipping_cost;
        }

        $order_num = 'ORD-' . date('Ymd-His') . '-' . rand(100, 999);
        db_insert('shop_orders', [
            'order_number'     => $order_num,
            'customer_name'    => $name,
            'customer_email'   => $email,
            'customer_phone'   => $phone,
            'customer_address' => $address,
            'items'            => json_encode($items, JSON_UNESCAPED_UNICODE),
            'total'            => $total,
            'status'           => 'new',
            'payment_method'   => $payment_method,
            'notes'            => $notes . ($shipping_cost > 0 ? "\nДоставка: {$shipping_cost} {$cur}" : ($shipping_cost === 0 && !empty($s['shipping_enabled']) ? "\nДоставка: бесплатно" : '')),
        ]);

        // Decrement stock
        foreach ($_SESSION['shop_cart'] as $pid => $item) {
            $product = db_find('shop_products', $pid);
            if ($product) {
                $new_stock = max(0, (int)$product['stock'] - $item['qty']);
                db_update('shop_products', ['stock' => $new_stock], ['id' => $pid]);
            }
        }

        // Send email notification
        $body = "Новый заказ #{$order_num}\n\nКлиент: {$name}\nEmail: {$email}\nТелефон: {$phone}\nАдрес: {$address}\n\n";
        foreach ($items as $it) $body .= "{$it['title']} x{$it['qty']} = " . ($it['price'] * $it['qty']) . " {$cur}\n";
        $body .= "\nИтого: {$total} {$cur}\n";
        if ($notes) $body .= "\nКомментарий: {$notes}\n";
        @mail($s['checkout_email'], "Новый заказ #{$order_num}", $body, "From: {$email_safe}\r\nContent-Type: text/plain; charset=utf-8");

        $_SESSION['shop_cart'] = [];
        $_SESSION['shop_coupon'] = '';
        $order_success = true;

        // Send webhook + Telegram notification
        require_once __DIR__ . '/../admin/core/notifications.php';
        notify('new_order', ['order_number' => $order_num, 'total' => $total, 'customer_name' => $name, 'customer_email' => $email]);
    }
}

// ===== Render =====
if (!db_available()) {
    echo '<div style="padding:16px; background:#fff3cd; color:#856404; border-radius:8px; text-align:center; margin:2rem 0;">⚠️ Магазин требует подключения базы данных (MySQL или SQLite).</div>';
    return;
}
?>
<div class="shop-module" style="max-width:1140px; margin:0 auto; padding:1rem;">

<?php if ($order_success): ?>
<div style="padding:20px; background:#d1e7dd; color:#0f5132; border-radius:12px; text-align:center; margin-bottom:2rem;">
    <h3>✓ Заказ оформлен!</h3>
    <p>Номер заказа: <strong><?= $order_num ?? '' ?></strong></p>
    <p>Мы свяжемся с вами для подтверждения.</p>
    <a href="?shop=" style="color:#2563eb;">← Вернуться в каталог</a>
</div>
<?php endif; ?>

<?php if ($action === 'cart'): ?>
<!-- ===== CART ===== -->
<h2>🛒 Корзина</h2>
<?php if (!empty($_GET['stock_error'])): ?>
<div style="padding:12px 16px; background:#fef3c7; color:#92400e; border:1px solid #fde68a; border-radius:8px; margin-bottom:1rem;">
    <i class="fas fa-exclamation-triangle"></i> Нельзя добавить больше товаров, чем есть на складе.
</div>
<?php endif; ?>
<?php if (empty($_SESSION['shop_cart'])): ?>
<p style="text-align:center; padding:2rem; color:#94a3b8;">Корзина пуста.</p>
<p style="text-align:center;"><a href="?shop=" style="color:#2563eb;">← В каталог</a></p>
<?php else: ?>
<div style="display:flex; flex-direction:column; gap:0.5rem;">
<?php $total = 0; foreach ($_SESSION['shop_cart'] as $pid => $item): $total += $item['price'] * $item['qty']; ?>
<div style="display:flex; justify-content:space-between; align-items:center; padding:0.8rem 1rem; background:#fff; border:1px solid #e2e8f0; border-radius:8px;">
    <div>
        <strong><?= h($item['title']) ?></strong><br>
        <small><?= $item['qty'] ?> × <?= number_format($item['price'], 2) ?> <?= $cur ?></small>
    </div>
    <div style="display:flex; align-items:center; gap:0.8rem;">
        <strong><?= number_format($item['price'] * $item['qty'], 2) ?> <?= $cur ?></strong>
        <form method="POST" action="?shop=remove" style="display:inline; margin:0;">
            <input type="hidden" name="csrf_token" value="<?= h($shop_csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$pid ?>">
            <button type="submit" style="background:none;border:none;cursor:pointer;color:#ef4444;font-size:1.2rem;" title="Удалить">×</button>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- Coupon input -->
<?php
$coupon_discount = 0;
$coupon_code = $_SESSION['shop_coupon'] ?? '';
if (!empty($coupon_code) && db_available()) {
    try {
        $cp = db_row('SELECT * FROM ' . db_table('shop_coupons') . ' WHERE code = ? AND status = ? LIMIT 1', [strtoupper($coupon_code), 'active']);
        if ($cp && ($cp['max_uses'] == 0 || $cp['uses_count'] < $cp['max_uses'])) {
            if ($cp['type'] === 'percent') { $coupon_discount = $total * ($cp['value'] / 100); }
            else { $coupon_discount = min($cp['value'], $total); }
        }
    } catch (Exception $e) {}
}
$final_total = $total - $coupon_discount;
?>
<div style="padding:0.8rem 1rem; background:#f1f5f9; border-radius:8px; margin-top:0.5rem;">
    <form method="POST" action="?shop=apply_coupon" style="display:flex; gap:0.5rem;">
        <input type="hidden" name="csrf_token" value="<?= h($shop_csrf) ?>">
        <input type="text" name="coupon_code" placeholder="Промокод" value="<?= h($coupon_code) ?>" style="flex:1; padding:0.4rem 0.8rem; border:1px solid #cbd5e1; border-radius:6px; text-transform:uppercase;">
        <button type="submit" style="padding:0.4rem 1rem; background:#6366f1; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600;">Применить</button>
    </form>
</div>
<?php if ($coupon_discount > 0): ?>
<div style="display:flex; justify-content:space-between; padding:0.5rem 1rem; color:#16a34a; font-size:0.9rem;">
    <span>Скидка по купону:</span><span>−<?= number_format($coupon_discount, 2) ?> <?= $cur ?></span>
</div>
<?php endif; ?>

<div style="display:flex; justify-content:space-between; padding:1rem; font-size:1.2rem; font-weight:bold;">
    <span>Итого:<?= $coupon_discount>0 ? ' (со скидкой)' : '' ?></span><span><?= number_format($final_total, 2) ?> <?= $cur ?></span>
</div>
<?php
// Show shipping estimate in cart
if (!empty($s['shipping_enabled'])):
    $cart_weight = 0;
    foreach ($_SESSION['shop_cart'] as $pid => $item) {
        $prod = db_find('shop_products', $pid);
        if ($prod) { $cart_weight += (float)($prod['weight'] ?? 0) * $item['qty']; }
    }
    $est_shipping = ($final_total >= (float)$s['shipping_free_from']) ? 0 : ((float)$s['shipping_base_cost'] + ($cart_weight * (float)$s['shipping_per_kg']));
?>
<div style="padding:0.5rem 1rem; font-size:0.85rem; color:#64748b; border-top:1px solid #e2e8f0;">
    <?php if ($est_shipping == 0): ?>
    🚚 Доставка: <strong style="color:#16a34a;">бесплатно</strong> (заказ от <?= number_format($s['shipping_free_from'], 0) ?> <?= $cur ?>)
    <?php else: ?>
    🚚 Доставка: ~<?= number_format($est_shipping, 0) ?> <?= $cur ?> (вес: <?= number_format($cart_weight, 1) ?> кг) · бесплатно от <?= number_format($s['shipping_free_from'], 0) ?> <?= $cur ?>
    <?php endif; ?>
</div>
<?php endif; ?>
<div style="text-align:center; margin-top:1rem;">
    <a href="?shop=checkout" style="display:inline-block; padding:0.8rem 2rem; background:#16a34a; color:#fff; text-decoration:none; border-radius:8px; font-weight:600;">Оформить заказ →</a>
    <a href="?shop=" style="margin-left:1rem; color:#64748b;">← Продолжить покупки</a>
</div>
</div>
<?php endif; ?>

<?php elseif ($action === 'checkout' || ($action === 'order' && !empty($order_error))): ?>
<!-- ===== CHECKOUT ===== -->
<h2>📝 Оформление заказа</h2>
<?php if (isset($order_error)): ?>
<div style="padding:12px; background:#f8d7da; color:#842029; border-radius:8px; margin-bottom:1rem;"><?= h($order_error) ?></div>
<?php endif; ?>
<?php if (empty($_SESSION['shop_cart'])): ?>
<p style="text-align:center; color:#94a3b8;">Корзина пуста.</p>
<?php else: ?>
<form method="POST" action="?shop=order" style="max-width:500px; margin:0 auto; display:flex; flex-direction:column; gap:1rem;">
    <input type="hidden" name="csrf_token" value="<?= h($shop_csrf) ?>">
    <?= form_honeypot_field() ?>
    <?= form_timetrap_field() ?>
    <div><label style="font-weight:600;">Имя *</label><input type="text" name="ord_name" required style="width:100%; padding:0.6rem; border:1px solid #cbd5e1; border-radius:8px;"></div>
    <div><label style="font-weight:600;">Email *</label><input type="email" name="ord_email" required style="width:100%; padding:0.6rem; border:1px solid #cbd5e1; border-radius:8px;"></div>
    <div><label style="font-weight:600;">Телефон</label><input type="tel" name="ord_phone" style="width:100%; padding:0.6rem; border:1px solid #cbd5e1; border-radius:8px;"></div>
    <div><label style="font-weight:600;">Адрес доставки</label><textarea name="ord_address" rows="3" style="width:100%; padding:0.6rem; border:1px solid #cbd5e1; border-radius:8px;"></textarea></div>
    <div><label style="font-weight:600;">Комментарий</label><textarea name="ord_notes" rows="2" style="width:100%; padding:0.6rem; border:1px solid #cbd5e1; border-radius:8px;"></textarea></div>

    <!-- Payment method selection -->
    <div>
        <label style="font-weight:600; display:block; margin-bottom:0.5rem;">Способ оплаты</label>
        <select name="payment_method" style="width:100%; padding:0.6rem; border:1px solid #cbd5e1; border-radius:8px;">
            <option value="cod">Наличными при получении</option>
            <?php if (!empty($s['yookassa_shop_id'])): ?><option value="yookassa">ЮKassa (банковская карта)</option><?php endif; ?>
            <?php if (!empty($s['stripe_secret'])): ?><option value="stripe">Stripe (банковская карта)</option><?php endif; ?>
            <option value="bank_transfer">Банковский перевод</option>
        </select>
    </div>

    <button type="submit" style="padding:0.8rem 2rem; background:#16a34a; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">Подтвердить заказ</button>
</form>
<?php endif; ?>

<?php elseif ($action === 'product'): ?>
<!-- ===== SINGLE PRODUCT ===== -->
<?php $p = db_find('shop_products', (int)($_GET['id'] ?? 0)); if ($p):
    $gallery_images = [];
    if (!empty($p['gallery'])) {
        $decoded = json_decode($p['gallery'], true);
        if (is_array($decoded)) $gallery_images = $decoded;
    }
?>
<div style="display:grid; grid-template-columns:1fr 1fr; gap:2rem; max-width:900px; margin:0 auto;">
    <div>
        <?php $main_img = !empty($p['image']) ? $p['image'] : (!empty($gallery_images) ? $gallery_images[0] : ''); ?>
        <?php if (!empty($main_img)): ?>
        <img src="<?= h($main_img) ?>" alt="<?= h($p['title']) ?>" id="mainProductImage" style="width:100%; border-radius:12px; margin-bottom:0.5rem;">
        <?php else: ?>
        <div style="width:100%; aspect-ratio:1; background:#f1f5f9; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:3rem; color:#cbd5e1;">📦</div>
        <?php endif; ?>
        <?php if (!empty($gallery_images)): ?>
        <div style="display:flex; gap:0.4rem; flex-wrap:wrap;">
            <?php if (!empty($p['image'])): ?>
            <img src="<?= h($p['image']) ?>" onclick="document.getElementById('mainProductImage').src='<?= h($p['image']) ?>'" style="width:60px; height:60px; object-fit:cover; border-radius:6px; cursor:pointer; border:2px solid #e2e8f0;">
            <?php endif; ?>
            <?php foreach ($gallery_images as $gi): ?>
            <img src="<?= h($gi) ?>" onclick="document.getElementById('mainProductImage').src='<?= h($gi) ?>'" style="width:60px; height:60px; object-fit:cover; border-radius:6px; cursor:pointer; border:2px solid #e2e8f0;">
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div>
        <?php if (!empty($p['brand'])): ?><div style="color:#64748b; font-size:13px; margin-bottom:4px;"><?= h($p['brand']) ?></div><?php endif; ?>
        <h2 style="margin:0 0 0.5rem;"><?= h($p['title']) ?></h2>
        <div style="font-size:1.8rem; font-weight:700; color:#16a34a; margin:0.5rem 0;">
            <?= number_format($p['price'], 2) ?> <?= $cur ?>
            <?php if ($p['old_price'] > 0 && $p['old_price'] > $p['price']): ?>
            <span style="font-size:1rem; color:#94a3b8; text-decoration:line-through; margin-left:8px;"><?= number_format($p['old_price'], 2) ?> <?= $cur ?></span>
            <span style="background:#dc2626; color:#fff; padding:2px 8px; border-radius:4px; font-size:12px; font-weight:700; margin-left:6px;">-<?= round(100 - ($p['price'] / $p['old_price'] * 100)) ?>%</span>
            <?php endif; ?>
        </div>
        <?php if (!empty($p['sku'])): ?><p style="color:#64748b; font-size:13px;">Артикул: <?= h($p['sku']) ?></p><?php endif; ?>
        <?php if (!empty($s['show_stock'])): ?>
        <p>На складе:
            <?php if ((int)$p['stock'] > 5): ?><strong style="color:#16a34a;"><?= (int)$p['stock'] ?> шт.</strong>
            <?php elseif ((int)$p['stock'] > 0): ?><strong style="color:#f59e0b;">Осталось <?= (int)$p['stock'] ?> шт.</strong>
            <?php else: ?><strong style="color:#dc2626;">Нет в наличии</strong><?php endif; ?>
        </p>
        <?php endif; ?>
        <?php if (!empty($p['short_desc'])): ?><div style="font-style:italic; color:#475569; margin:0.5rem 0;"><?= h($p['short_desc']) ?></div><?php endif; ?>
        <div style="margin:1rem 0; line-height:1.7; color:#334155;"><?= $p['description'] ?></div>
        <?php if ((int)$p['stock'] > 0): ?>
        <form method="POST" action="?shop=add" style="display:inline-block; margin:0;">
            <input type="hidden" name="csrf_token" value="<?= h($shop_csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button type="submit" style="display:inline-block; padding:0.7rem 1.8rem; background:#2563eb; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">🛒 В корзину</button>
        </form>
        <?php else: ?>
        <span style="display:inline-block; padding:0.7rem 1.8rem; background:#e2e8f0; color:#94a3b8; border-radius:8px;">Нет в наличии</span>
        <?php endif; ?>
        <a href="?shop=" style="margin-left:1rem; color:#64748b;">← Назад в каталог</a>
    </div>
</div>

<!-- Product reviews -->
<?php if (db_available()): 
    // Handle review submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'review_submit') {
        $rev_csrf_ok = shop_csrf_ok();
        $rev_name = trim($_POST['rev_name'] ?? '');
        $rev_email = trim($_POST['rev_email'] ?? '');
        $rev_rating = max(1, min(5, (int)($_POST['rev_rating'] ?? 5)));
        $rev_text = trim($_POST['rev_text'] ?? '');
        $rev_guard = form_guard_full_check('shop_review');
        if ($rev_guard['silent']) {
            // Bot caught by honeypot/time-trap — pretend success
            echo '<div style="padding:12px; background:#d1e7dd; color:#0f5132; border-radius:8px; margin:1rem 0;">✓ Отзыв отправлен! Он появится после проверки модератором.</div>';
        } elseif (!$rev_guard['ok']) {
            echo '<div style="padding:12px; background:#f8d7da; color:#842029; border-radius:8px; margin:1rem 0;">' . h($rev_guard['message']) . '</div>';
        } elseif ($rev_csrf_ok && !empty($rev_name) && !empty($rev_text)) {
            try {
                db_insert('shop_reviews', ['product_id'=>$p['id'], 'author_name'=>$rev_name, 'author_email'=>$rev_email, 'rating'=>$rev_rating, 'content'=>$rev_text, 'status'=>'pending']);
                echo '<div style="padding:12px; background:#d1e7dd; color:#0f5132; border-radius:8px; margin:1rem 0;">✓ Отзыв отправлен! Он появится после проверки модератором.</div>';
            } catch (Exception $e) {}
        }
    }
    $reviews = [];
    try { $reviews = db_select('shop_reviews', '*', 'product_id = ? AND status = ?', [$p['id'], 'approved'], 'created_at DESC'); } catch (Exception $e) {}
    $avg_rating = 0;
    if (!empty($reviews)) { $avg_rating = round(array_sum(array_column($reviews, 'rating')) / count($reviews), 1); }
?>
<div style="max-width:900px; margin:2rem auto;">
    <h3 style="margin-bottom:1rem;">Отзывы <?= !empty($reviews) ? '(' . count($reviews) . ')' : '' ?><?php if($avg_rating>0): ?> <span style="color:#f59e0b;">★ <?= $avg_rating ?></span><?php endif; ?></h3>
    
    <?php if (empty($reviews)): ?>
    <p style="color:#94a3b8; text-align:center;">Отзывов пока нет. Будьте первым!</p>
    <?php else: foreach ($reviews as $rev): ?>
    <div style="padding:1rem; background:#fff; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:0.8rem;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.4rem;">
            <strong><?= h($rev['author_name']) ?></strong>
            <span style="color:#f59e0b;"><?= str_repeat('★', $rev['rating']) . str_repeat('☆', 5 - $rev['rating']) ?></span>
        </div>
        <p style="margin:0; color:#475569; line-height:1.6;"><?= nl2br(h($rev['content'])) ?></p>
        <small style="color:#94a3b8;"><?= h($rev['created_at']) ?></small>
    </div>
    <?php endforeach; endif; ?>

    <!-- Review form -->
    <form method="POST" style="margin-top:1.5rem; padding:1.2rem; background:#f8fafc; border-radius:10px; position:relative;">
        <input type="hidden" name="form_type" value="review_submit">
        <input type="hidden" name="csrf_token" value="<?= h($shop_csrf) ?>">
        <?= form_honeypot_field() ?>
        <?= form_timetrap_field() ?>
        <h4 style="margin:0 0 1rem;">Оставить отзыв</h4>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.8rem; margin-bottom:0.8rem;">
            <input type="text" name="rev_name" placeholder="Ваше имя *" required style="padding:0.5rem 0.8rem; border:1px solid #cbd5e1; border-radius:6px;">
            <input type="email" name="rev_email" placeholder="Email" style="padding:0.5rem 0.8rem; border:1px solid #cbd5e1; border-radius:6px;">
        </div>
        <div style="margin-bottom:0.8rem;">
            <label style="font-size:13px; color:#64748b; margin-bottom:4px; display:block;">Оценка:</label>
            <select name="rev_rating" style="padding:0.4rem; border:1px solid #cbd5e1; border-radius:6px;">
                <option value="5">★★★★★ Отлично</option>
                <option value="4">★★★★☆ Хорошо</option>
                <option value="3">★★★☆☆ Нормально</option>
                <option value="2">★★☆☆☆ Плохо</option>
                <option value="1">★☆☆☆☆ Ужасно</option>
            </select>
        </div>
        <textarea name="rev_text" placeholder="Ваш отзыв..." required rows="3" style="width:100%; padding:0.5rem 0.8rem; border:1px solid #cbd5e1; border-radius:6px; resize:vertical;"></textarea>
        <button type="submit" style="margin-top:0.6rem; padding:0.5rem 1.5rem; background:#2563eb; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600;">Отправить отзыв</button>
    </form>
</div>
<?php endif; ?>

<?php else: ?>
<p>Товар не найден.</p>
<?php endif; ?>

<?php else: ?>
<!-- ===== PRODUCT CATALOG ===== -->
<h2 style="margin-bottom:1rem;"><?= h($s['title']) ?></h2>
<?php
$cat_id = (int)($_GET['cat'] ?? 0);
$where = 'status = ?';
$params = ['active'];
if ($cat_id > 0) { $where .= ' AND category_id = ?'; $params[] = $cat_id; }
$products = db_select('shop_products', '*', $where, $params, 'position ASC, created_at DESC', (int)$s['per_page']);
$categories = db_select('shop_categories', '*', '', [], 'position ASC');
?>
<?php if (!empty($categories)): ?>
<div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1.5rem;">
    <a href="?shop=" style="padding:4px 12px; border-radius:20px; text-decoration:none; font-size:13px; <?= $cat_id === 0 ? 'background:#2563eb; color:#fff;' : 'background:#f1f5f9; color:#475569;' ?>">Все</a>
    <?php foreach ($categories as $cat): ?>
    <a href="?shop=&cat=<?= $cat['id'] ?>" style="padding:4px 12px; border-radius:20px; text-decoration:none; font-size:13px; <?= $cat_id === (int)$cat['id'] ? 'background:#2563eb; color:#fff;' : 'background:#f1f5f9; color:#475569;' ?>"><?= h($cat['title']) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($products)): ?>
<p style="text-align:center; padding:2rem; color:#94a3b8;">Товаров пока нет.</p>
<?php else: ?>
<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:1.2rem;">
    <?php foreach ($products as $p): ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; transition:box-shadow 0.2s; position:relative;" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.1)'" onmouseout="this.style.boxShadow=''">
        <?php if ($p['old_price'] > 0 && $p['old_price'] > $p['price']): ?>
        <span style="position:absolute; top:8px; left:8px; background:#dc2626; color:#fff; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:700; z-index:1;">-<?= round(100 - ($p['price'] / $p['old_price'] * 100)) ?>%</span>
        <?php endif; ?>
        <?php if ((int)$p['stock'] === 0): ?>
        <span style="position:absolute; top:8px; right:8px; background:#94a3b8; color:#fff; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600; z-index:1;">Нет в наличии</span>
        <?php elseif ((int)$p['stock'] <= 5): ?>
        <span style="position:absolute; top:8px; right:8px; background:#f59e0b; color:#fff; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600; z-index:1;">Осталось <?= (int)$p['stock'] ?></span>
        <?php endif; ?>
        <a href="?shop=product&id=<?= $p['id'] ?>" style="text-decoration:none; color:inherit; display:block;">
            <?php if (!empty($p['image'])): ?>
                <img src="<?= h($p['image']) ?>" alt="<?= h($p['title']) ?>" style="width:100%; height:180px; object-fit:cover;">
            <?php else: ?>
                <div style="width:100%; height:180px; background:#f1f5f9; display:flex; align-items:center; justify-content:center; font-size:3rem; color:#cbd5e1;">📦</div>
            <?php endif; ?>
        </a>
        <div style="padding:0.8rem;">
            <a href="?shop=product&id=<?= $p['id'] ?>" style="text-decoration:none; color:inherit;"><h4 style="font-size:14px; margin:0 0 0.4rem;"><?= h($p['title']) ?></h4></a>
            <?php if (!empty($p['short_desc'])): ?><p style="font-size:12px; color:#64748b; margin:0 0 0.5rem; line-height:1.4;"><?= h(mb_substr($p['short_desc'], 0, 80)) ?></p><?php endif; ?>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <strong style="color:#16a34a; font-size:1.1rem;"><?= number_format($p['price'], 2) ?> <?= $cur ?></strong>
                    <?php if ($p['old_price'] > 0): ?><br><small style="color:#94a3b8; text-decoration:line-through;"><?= number_format($p['old_price'], 2) ?> <?= $cur ?></small><?php endif; ?>
                </div>
                <?php if ((int)$p['stock'] > 0): ?>
                <form method="POST" action="?shop=add" style="display:inline; margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= h($shop_csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" style="padding:6px 12px; background:#2563eb; color:#fff; border:none; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer;">🛒 В корзину</button>
                </form>
                <?php else: ?>
                <span style="padding:6px 12px; background:#e2e8f0; color:#94a3b8; border-radius:6px; font-size:12px;">Нет</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

</div>
