<?php
/**
 * Module: Hero Section
 * =====================
 * Full-width centered hero section with a solid or gradient background,
 * headline, subtitle, and a call-to-action button.
 * Settings are stored in data/module_settings/hero.json
 *
 * Settings:
 *   - title        : main headline
 *   - subtitle     : supporting text
 *   - button_text  : CTA button label
 *   - button_url   : CTA button link
 *   - button2_text : optional secondary button label
 *   - button2_url  : optional secondary button link
 *   - bg_color     : primary background color
 *   - bg_color2    : secondary color (for gradient)
 *   - text_color   : text color
 *   - style        : 'solid' | 'gradient'
 *   - bg_image     : optional background image URL
 *   - alignment    : 'center' | 'left'
 *   - min_height   : hero min height (e.g. "60vh")
 *
 * Usage: {{module:hero}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';

$defaults = [
    'title'        => 'Build Something Amazing Today',
    'subtitle'     => 'A powerful, flexible platform that helps teams ship faster. Start your free trial — no credit card required.',
    'button_text'  => 'Get Started Free',
    'button_url'   => '#',
    'button2_text' => 'Watch Demo',
    'button2_url'  => '#',
    'bg_color'     => '#2563eb',
    'bg_color2'    => '#1e40af',
    'text_color'   => '#ffffff',
    'style'        => 'gradient',
    'bg_image'     => '',
    'alignment'    => 'center',
    'min_height'   => '60vh',
];
$s = module_get_settings('hero', $defaults);

// Build the background
$bg = '';
if (!empty($s['bg_image'])) {
    $bg = 'linear-gradient(rgba(15,23,42,0.55), rgba(15,23,42,0.55)), url("' . htmlspecialchars($s['bg_image']) . '") center/cover no-repeat';
} elseif ($s['style'] === 'gradient') {
    $bg = 'linear-gradient(135deg, ' . htmlspecialchars($s['bg_color']) . ' 0%, ' . htmlspecialchars($s['bg_color2']) . ' 100%)';
} else {
    $bg = htmlspecialchars($s['bg_color']);
}
$align = ($s['alignment'] === 'left') ? 'flex-start' : 'center';
$textAlign = ($s['alignment'] === 'left') ? 'left' : 'center';
$hid = 'hr_' . substr(md5(uniqid('', true)), 0, 8);
?>
<style>
.<?= $hid ?>.hero-module {
    width: 100%; box-sizing: border-box; min-height: <?= htmlspecialchars($s['min_height']) ?>;
    display: flex; align-items: center; justify-content: <?= $align ?>;
    background: <?= $bg ?>;
    color: <?= htmlspecialchars($s['text_color']) ?>;
    padding: 4rem 1.5rem; position: relative; overflow: hidden;
}
.<?= $hid ?> .hero-inner { max-width: 820px; width: 100%; text-align: <?= $textAlign ?>; box-sizing: border-box; position: relative; z-index: 2; }
.<?= $hid ?> .hero-eyebrow {
    display: inline-block; padding: 0.35rem 0.9rem; border-radius: 999px;
    background: rgba(255,255,255,0.15); backdrop-filter: blur(6px);
    font-size: 0.8rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase;
    margin-bottom: 1.2rem; border: 1px solid rgba(255,255,255,0.25);
}
.<?= $hid ?> .hero-title {
    font-size: clamp(2rem, 5vw, 3.4rem); font-weight: 800; line-height: 1.1;
    margin: 0 0 1rem; letter-spacing: -0.02em;
}
.<?= $hid ?> .hero-sub {
    font-size: clamp(1rem, 2.2vw, 1.25rem); line-height: 1.6; opacity: 0.92;
    margin: 0 0 2rem; max-width: 640px;
    <?= $s['alignment'] === 'center' ? 'margin-left:auto; margin-right:auto;' : '' ?>
}
.<?= $hid ?> .hero-btns {
    display: flex; gap: 0.9rem; flex-wrap: wrap;
    justify-content: <?= $align ?>;
}
.<?= $hid ?> .hero-btn {
    display: inline-flex; align-items: center; gap: 0.5rem;
    padding: 0.95rem 1.8rem; border-radius: 999px; font-weight: 700; font-size: 1.02rem;
    text-decoration: none; transition: all 0.25s; box-sizing: border-box;
}
.<?= $hid ?> .hero-btn-primary { background: #fff; color: <?= htmlspecialchars($s['bg_color']) ?>; box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
.<?= $hid ?> .hero-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,0,0,0.3); }
.<?= $hid ?> .hero-btn-secondary { background: rgba(255,255,255,0.12); color: <?= htmlspecialchars($s['text_color']) ?>; border: 1px solid rgba(255,255,255,0.4); backdrop-filter: blur(6px); }
.<?= $hid ?> .hero-btn-secondary:hover { background: rgba(255,255,255,0.22); transform: translateY(-2px); }
.<?= $hid ?> .hero-deco {
    position: absolute; border-radius: 50%; background: rgba(255,255,255,0.06);
    pointer-events: none; z-index: 1;
}
.<?= $hid ?> .hero-deco-1 { width: 320px; height: 320px; top: -120px; right: -80px; }
.<?= $hid ?> .hero-deco-2 { width: 220px; height: 220px; bottom: -90px; left: -60px; }
@media (max-width: 600px) {
    .<?= $hid ?>.hero-module { padding: 3rem 1.2rem; min-height: auto; }
    .<?= $hid ?> .hero-btns { flex-direction: column; align-items: <?= $align ?>; }
    .<?= $hid ?> .hero-btn { width: 100%; max-width: 320px; justify-content: center; }
}
</style>
<section class="<?= $hid ?> hero-module">
    <div class="hero-deco hero-deco-1"></div>
    <div class="hero-deco hero-deco-2"></div>
    <div class="hero-inner">
        <?php if (!empty($s['eyebrow'])): ?>
            <span class="hero-eyebrow"><?= htmlspecialchars($s['eyebrow']) ?></span>
        <?php endif; ?>
        <h1 class="hero-title"><?= htmlspecialchars($s['title']) ?></h1>
        <?php if (!empty($s['subtitle'])): ?>
            <p class="hero-sub"><?= htmlspecialchars($s['subtitle']) ?></p>
        <?php endif; ?>
        <div class="hero-btns">
            <?php if (!empty($s['button_text'])): ?>
                <a href="<?= htmlspecialchars($s['button_url']) ?>" class="hero-btn hero-btn-primary">
                    <?= htmlspecialchars($s['button_text']) ?>
                    <i class="fas fa-arrow-right"></i>
                </a>
            <?php endif; ?>
            <?php if (!empty($s['button2_text'])): ?>
                <a href="<?= htmlspecialchars($s['button2_url']) ?>" class="hero-btn hero-btn-secondary">
                    <i class="fas fa-play"></i>
                    <?= htmlspecialchars($s['button2_text']) ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
</section>
