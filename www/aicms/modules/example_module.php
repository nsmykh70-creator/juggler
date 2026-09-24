<?php
/**
 * Module: Example Module (with settings)
 * ======================================
 * A simple demonstration module showing how to use settings.
 * Settings: title, message, border_color, bg_color, show_time
 *
 * Usage: {{module:example_module}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';

$defaults = [
    'title'       => 'Пример модуля!',
    'message'     => 'Это содержимое сгенерировано файлом example_module.php.',
    'border_color'=> '#0d6efd',
    'bg_color'    => '#eef5ff',
    'show_time'   => true,
];
$s = module_get_settings('example_module', $defaults);
?>
<div style="border: 2px solid <?= htmlspecialchars($s['border_color']) ?>; padding: 15px; border-radius: 8px; background: <?= htmlspecialchars($s['bg_color']) ?>;">
    <h4><?= htmlspecialchars($s['title']) ?></h4>
    <p><?= htmlspecialchars($s['message']) ?></p>
    <?php if (!empty($s['show_time'])): ?>
    <p>Текущее время на сервере: <strong><?= date('H:i:s') ?></strong></p>
    <?php endif; ?>
</div>
