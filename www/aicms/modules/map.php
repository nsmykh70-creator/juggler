<?php
/**
 * Module: Map
 * ===========
 * Displays an embedded map (Google Maps or Yandex Maps).
 * Usage: {{module:map}}
 * Settings: address, zoom, height, map_type (google/yandex), title
 *
 * Features:
 *   - Google Maps or Yandex Maps embed
 *   - Configurable zoom level & height
 *   - Address display below the map
 *   - Click-to-open in new tab (link to native maps app)
 */
require_once __DIR__ . '/../admin/core/module_settings.php';
require_once __DIR__ . '/../admin/core/extensions.php';

if (!extension_enabled('map')) { echo ''; return; }

$defaults = [
    'title' => 'Как нас найти',
    'address' => 'Москва, Красная площадь, 1',
    'zoom' => 15,
    'height' => 400,
    'map_type' => 'google',
    'show_link' => true,
];
$s = module_get_settings('map', $defaults);
$addr_encoded = urlencode($s['address']);
$native_link = $s['map_type'] === 'yandex'
    ? 'https://yandex.ru/maps/?text=' . $addr_encoded
    : 'https://www.google.com/maps/search/?api=1&query=' . $addr_encoded;
?>
<div class="map-module" style="max-width:100%;margin:2rem 0;">
    <?php if (!empty($s['title'])): ?>
    <h3 style="font-size:1.5rem;font-weight:700;color:#1e293b;margin-bottom:1rem;"><?= htmlspecialchars($s['title']) ?></h3>
    <?php endif; ?>
    <div style="width:100%;max-width:100%;overflow:hidden;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,0.1);">
        <?php if ($s['map_type'] === 'yandex'): ?>
        <iframe src="https://yandex.ru/map-widget/v1/?text=<?= $addr_encoded ?>&z=<?= (int)$s['zoom'] ?>"
            width="100%" height="<?= (int)$s['height'] ?>" frameborder="0" style="border:0;display:block;" loading="lazy" title="<?= htmlspecialchars($s['address']) ?>"></iframe>
        <?php else: ?>
        <iframe src="https://maps.google.com/maps?q=<?= $addr_encoded ?>&z=<?= (int)$s['zoom'] ?>&output=embed"
            width="100%" height="<?= (int)$s['height'] ?>" frameborder="0" style="border:0;display:block;" loading="lazy" title="<?= htmlspecialchars($s['address']) ?>"></iframe>
        <?php endif; ?>
    </div>
    <p style="text-align:center;margin-top:0.5rem;color:#64748b;font-size:14px;">
        <i class="fas fa-map-marker-alt" style="color:#dc2626;"></i>
        <?= htmlspecialchars($s['address']) ?>
        <?php if (!empty($s['show_link'])): ?>
        <a href="<?= htmlspecialchars($native_link) ?>" target="_blank" rel="noopener" style="color:#2563eb;text-decoration:none;margin-left:6px;">Открыть в приложении &nearr;</a>
        <?php endif; ?>
    </p>
</div>

