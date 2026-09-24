<?php
/**
 * Module: Contact Info Card
 * ==========================
 * Displays a styled card with contact information (address, phone, email, hours).
 * Settings are stored in data/module_settings/contact_info.json
 *
 * Settings:
 *   - title        : card title
 *   - address      : physical address
 *   - phone        : phone number
 *   - email        : email address
 *   - hours        : working hours
 *   - show_icons   : whether to show icons
 *
 * Usage: {{module:contact_info}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';

$defaults = [
    'title'      => 'Контактная информация',
    'address'    => 'г. Москва, ул. Примерная, д. 1',
    'phone'      => '+7 (495) 123-45-67',
    'email'      => 'info@example.com',
    'hours'      => 'Пн-Пт: 9:00-18:00',
    'show_icons' => true,
];
$s = module_get_settings('contact_info', $defaults);
?>
<div class="contact-info-module" style="max-width:500px; margin:2rem auto; padding:2rem; background:#fff; border-radius:12px; box-shadow:0 4px 12px rgba(0,0,0,0.08); border-left:4px solid #0d6efd;">
    <h3 style="margin-top:0; margin-bottom:1.2rem; color:#0d6efd; font-size:1.3rem;">
        <?= !empty($s['show_icons']) ? '📋 ' : '' ?><?= htmlspecialchars($s['title']) ?>
    </h3>
    <div style="display:flex; flex-direction:column; gap:0.8rem; line-height:1.6;">
        <?php if (!empty($s['address'])): ?>
        <div style="display:flex; gap:0.6rem; align-items:flex-start;">
            <span style="font-size:1.1rem;"><?= !empty($s['show_icons']) ? '📍' : '' ?></span>
            <div><strong>Адрес:</strong><br><?= htmlspecialchars($s['address']) ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($s['phone'])): ?>
        <div style="display:flex; gap:0.6rem; align-items:flex-start;">
            <span style="font-size:1.1rem;"><?= !empty($s['show_icons']) ? '📞' : '' ?></span>
            <div><strong>Телефон:</strong><br><a href="tel:<?= htmlspecialchars(preg_replace('/[^+\d]/', '', $s['phone'])) ?>" style="color:#0d6efd; text-decoration:none;"><?= htmlspecialchars($s['phone']) ?></a></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($s['email'])): ?>
        <div style="display:flex; gap:0.6rem; align-items:flex-start;">
            <span style="font-size:1.1rem;"><?= !empty($s['show_icons']) ? '✉️' : '' ?></span>
            <div><strong>Email:</strong><br><a href="mailto:<?= htmlspecialchars($s['email']) ?>" style="color:#0d6efd; text-decoration:none;"><?= htmlspecialchars($s['email']) ?></a></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($s['hours'])): ?>
        <div style="display:flex; gap:0.6rem; align-items:flex-start;">
            <span style="font-size:1.1rem;"><?= !empty($s['show_icons']) ? '🕐' : '' ?></span>
            <div><strong>Часы работы:</strong><br><?= htmlspecialchars($s['hours']) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>
