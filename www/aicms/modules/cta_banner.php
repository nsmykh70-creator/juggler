<?php
/**
 * Module: Call to Action Banner
 * ==============================
 * A prominent banner with heading, text, and button.
 * Settings are stored in data/module_settings/cta_banner.json
 *
 * Settings:
 *   - heading     : main heading text
 *   - text        : descriptive text
 *   - button_text : button label
 *   - button_url  : button link URL
 *   - bg_color    : background color (hex)
 *   - text_color  : text color (hex)
 *   - style       : 'solid' | 'gradient' | 'outline'
 *
 * Usage: {{module:cta_banner}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';

$defaults = [
    'heading'     => 'Готовы начать?',
    'text'        => 'Свяжитесь с нами сегодня и получите бесплатную консультацию.',
    'button_text' => 'Связаться',
    'button_url'  => '#',
    'bg_color'    => '#0d6efd',
    'text_color'  => '#ffffff',
    'style'       => 'gradient',
];
$s = module_get_settings('cta_banner', $defaults);

// Build background based on style
if ($s['style'] === 'gradient') {
    $bg = 'linear-gradient(135deg, ' . htmlspecialchars($s['bg_color']) . ' 0%, ' . htmlspecialchars($s['bg_color']) . 'dd 100%)';
} elseif ($s['style'] === 'outline') {
    $bg = 'transparent';
} else {
    $bg = htmlspecialchars($s['bg_color']);
}

$border = ($s['style'] === 'outline') ? 'border:2px solid ' . htmlspecialchars($s['bg_color']) . ';' : '';
$textColor = ($s['style'] === 'outline') ? htmlspecialchars($s['bg_color']) : htmlspecialchars($s['text_color']);
$btnBg = ($s['style'] === 'outline') ? htmlspecialchars($s['bg_color']) : '#fff';
$btnColor = ($s['style'] === 'outline') ? '#fff' : htmlspecialchars($s['bg_color']);
?>
<div class="cta-banner-module" style="margin:2.5rem 0;">
    <div style="background:<?= $bg ?>; <?= $border ?> border-radius:16px; padding:3rem 2rem; text-align:center; color:<?= $textColor ?>; box-shadow:0 8px 24px rgba(0,0,0,0.12);">
        <h2 style="font-size:2rem; margin-bottom:0.8rem; font-weight:700;"><?= htmlspecialchars($s['heading']) ?></h2>
        <p style="font-size:1.15rem; margin-bottom:1.8rem; opacity:0.95; max-width:600px; margin-left:auto; margin-right:auto;"><?= htmlspecialchars($s['text']) ?></p>
        <a href="<?= htmlspecialchars($s['button_url']) ?>" style="display:inline-block; padding:0.9rem 2.2rem; background:<?= $btnBg ?>; color:<?= $btnColor ?>; text-decoration:none; border-radius:999px; font-weight:700; font-size:1.05rem; transition:transform 0.2s;">
            <?= htmlspecialchars($s['button_text']) ?>
        </a>
    </div>
</div>
