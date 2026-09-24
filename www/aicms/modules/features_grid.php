<?php
/**
 * Module: Features Grid
 * ======================
 * Displays a grid of feature cards with icons, titles, descriptions.
 * Settings are stored in data/module_settings/features_grid.json
 *
 * Settings:
 *   - title    : section heading
 *   - subtitle : section subtitle
 *   - columns  : number of columns (1-4)
 *   - cards    : JSON array of {icon, title, text}
 *
 * Usage: {{module:features_grid}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';

$defaults = [
    'title'    => 'Наши преимущества',
    'subtitle' => 'Почему выбирают нас',
    'columns'  => 3,
    'cards'    => [
        ['icon' => '🚀', 'title' => 'Скорость', 'text' => 'Быстрая загрузка и отзывчивость.'],
        ['icon' => '🛡️', 'title' => 'Надёжность', 'text' => 'Защита данных и стабильная работа.'],
        ['icon' => '💬', 'title' => 'Поддержка', 'text' => 'Круглосуточная помощь 24/7.'],
    ],
];
$s = module_get_settings('features_grid', $defaults);
$cols = max(1, min(4, (int)$s['columns']));
$cards = is_array($s['cards']) ? $s['cards'] : [];
?>
<section class="features-grid-module" style="padding:3rem 0;">
    <div style="max-width:1140px; margin:0 auto; padding:0 1rem;">
        <div style="text-align:center; margin-bottom:2.5rem;">
            <h2 style="font-size:2rem; margin-bottom:0.5rem; color:#1e293b;"><?= htmlspecialchars($s['title']) ?></h2>
            <p style="color:#64748b; font-size:1.1rem;"><?= htmlspecialchars($s['subtitle']) ?></p>
        </div>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:1.5rem;">
            <?php foreach ($cards as $card): ?>
            <div style="padding:2rem; background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.06); text-align:center; transition:transform 0.2s; border:1px solid #f1f5f9;">
                <div style="font-size:3rem; margin-bottom:1rem;"><?= htmlspecialchars($card['icon'] ?? '⭐') ?></div>
                <h3 style="font-size:1.25rem; margin-bottom:0.6rem; color:#0d6efd;"><?= htmlspecialchars($card['title'] ?? '') ?></h3>
                <p style="color:#64748b; line-height:1.6; margin:0;"><?= htmlspecialchars($card['text'] ?? '') ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
