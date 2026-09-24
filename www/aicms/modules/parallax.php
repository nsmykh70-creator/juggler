<?php
/**
 * Module: Parallax
 * =================
 * Full-width parallax background section with overlay text.
 * Usage: {{module:parallax}}
 * Settings: image_url, title, subtitle, height, overlay_opacity, text_color
 *
 * Features:
 *   - Fixed background attachment for parallax effect
 *   - Mobile-friendly fallback (scroll attachment on small screens)
 *   - Configurable overlay opacity for text legibility
 *   - Optional CTA button
 */
require_once __DIR__ . '/../admin/core/module_settings.php';
require_once __DIR__ . '/../admin/core/extensions.php';

if (!extension_enabled('parallax')) { echo ''; return; }

$defaults = [
    'title' => 'Ваш заголовок',
    'subtitle' => 'Описание поверх параллакс-фона',
    'image_url' => 'https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=1920',
    'height' => 500,
    'overlay_opacity' => 0.5,
    'text_color' => '#ffffff',
    'cta_text' => '',
    'cta_url' => '',
];
$s = module_get_settings('parallax', $defaults);
$pid = 'px_' . substr(md5(uniqid()), 0, 8);
?>
<style>
.<?= $pid ?>-section {
    position: relative;
    width: 100%;
    max-width: 100%;
    height: <?= (int)$s['height'] ?>px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    color: <?= htmlspecialchars($s['text_color']) ?>;
    background-attachment: fixed;
    background-position: center;
    background-repeat: no-repeat;
    background-size: cover;
    background-image: url('<?= htmlspecialchars($s['image_url']) ?>');
}
.<?= $pid ?>-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, <?= (float)$s['overlay_opacity'] ?>);
}
.<?= $pid ?>-content {
    position: relative;
    z-index: 2;
    max-width: 800px;
    padding: 0 20px;
}
@media (max-width: 768px) {
    .<?= $pid ?>-section { background-attachment: scroll; }
}
</style>
<div class="<?= $pid ?>-section">
    <div class="<?= $pid ?>-overlay"></div>
    <div class="<?= $pid ?>-content">
        <h2 style="font-size:2.5rem;font-weight:800;margin:0 0 1rem;text-shadow:0 2px 10px rgba(0,0,0,0.5);"><?= htmlspecialchars($s['title']) ?></h2>
        <p style="font-size:1.2rem;line-height:1.6;margin:0 0 1.5rem;text-shadow:0 2px 10px rgba(0,0,0,0.5);"><?= htmlspecialchars($s['subtitle']) ?></p>
        <?php if (!empty($s['cta_text']) && !empty($s['cta_url'])): ?>
        <a href="<?= htmlspecialchars($s['cta_url']) ?>" style="display:inline-block;padding:0.9rem 2rem;background:#fff;color:#1e293b;text-decoration:none;border-radius:8px;font-weight:600;font-size:1rem;transition:transform 0.15s;box-shadow:0 4px 12px rgba(0,0,0,0.15);" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'"><?= htmlspecialchars($s['cta_text']) ?></a>
        <?php endif; ?>
    </div>
</div>
