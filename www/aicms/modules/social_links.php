<?php
/**
 * Module: Social Links
 * =====================
 * Displays social media links as styled icons.
 * Settings are stored in data/module_settings/social_links.json
 *
 * Settings:
 *   - style     : 'circles' | 'squares' | 'text'
 *   - size      : pixel size of icons
 *   - align     : 'left' | 'center' | 'right'
 *   - platforms : JSON object { platform: url } — empty URL = hidden
 *
 * Usage: {{module:social_links}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';

$defaults = [
    'style'     => 'circles',
    'size'      => 40,
    'align'     => 'center',
    'platforms' => [
        'telegram' => '',
        'vk'       => '',
        'youtube'  => '',
        'instagram'=> '',
        'facebook' => '',
        'twitter'  => '',
        'github'   => '',
        'whatsapp' => '',
    ],
];
$s = module_get_settings('social_links', $defaults);
$size = max(24, min(64, (int)$s['size']));
$platforms = is_array($s['platforms']) ? $s['platforms'] : [];

// Platform icon definitions
$icons = [
    'telegram'  => ['icon' => '✈️', 'name' => 'Telegram',   'color' => '#0088cc'],
    'vk'        => ['icon' => '🅥',  'name' => 'VK',         'color' => '#4a76a8'],
    'youtube'   => ['icon' => '▶️', 'name' => 'YouTube',    'color' => '#ff0000'],
    'instagram' => ['icon' => '📷', 'name' => 'Instagram',  'color' => '#e4405f'],
    'facebook'  => ['icon' => 'f',  'name' => 'Facebook',   'color' => '#1877f2'],
    'twitter'   => ['icon' => '🐦', 'name' => 'Twitter/X',  'color' => '#1da1f2'],
    'github'    => ['icon' => '🐙', 'name' => 'GitHub',     'color' => '#181717'],
    'whatsapp'  => ['icon' => '💬', 'name' => 'WhatsApp',   'color' => '#25d366'],
];

$align_map = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'];
$align = $align_map[$s['align']] ?? 'center';

$radius = ($s['style'] === 'squares') ? '8px' : '50%';
?>
<div class="social-links-module" style="display:flex; justify-content:<?= htmlspecialchars($align) ?>; gap:0.8rem; padding:1.5rem 0; flex-wrap:wrap;">
    <?php foreach ($platforms as $platform => $url): ?>
        <?php if (!empty($url) && isset($icons[$platform])): ?>
            <?php $info = $icons[$platform]; ?>
            <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener" title="<?= htmlspecialchars($info['name']) ?>"
               style="display:inline-flex; align-items:center; justify-content:center; width:<?= $size ?>px; height:<?= $size ?>px; background:<?= htmlspecialchars($info['color']) ?>; color:#fff; border-radius:<?= $radius ?>; text-decoration:none; font-size:<?= round($size * 0.5) ?>px; font-weight:700; transition:transform 0.2s, opacity 0.2s;"
               onmouseover="this.style.transform='translateY(-3px)'; this.style.opacity='0.85';"
               onmouseout="this.style.transform=''; this.style.opacity='1';">
                <?= $info['icon'] ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
