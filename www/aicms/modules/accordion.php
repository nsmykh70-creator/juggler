<?php
/**
 * Module: Generic Accordion
 * ==========================
 * A flexible accordion allowing multiple panels open simultaneously.
 * Smooth JS-driven expand/collapse (height transition).
 * Settings are stored in data/module_settings/accordion.json
 *
 * Settings:
 *   - title       : section heading
 *   - subtitle    : optional subtitle
 *   - multiple    : (bool) allow multiple panels open at once
 *   - default_open: index of panel open by default (-1 = none)
 *   - icon_open   : Font Awesome icon class when closed
 *   - icon_close  : Font Awesome icon class when open
 *   - items        : array of {header, content}
 *
 * Usage: {{module:accordion}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';

$defaults = [
    'title'        => 'Information',
    'subtitle'     => 'Click a panel to expand its content',
    'multiple'     => true,
    'default_open' => 0,
    'icon_open'    => 'fas fa-chevron-down',
    'icon_close'   => 'fas fa-chevron-up',
    'items'        => [
        ['header' => 'Getting Started', 'content' => 'Welcome aboard! This panel contains everything you need to get started quickly. Follow the steps outlined here to set up your account and begin your journey with us.'],
        ['header' => 'Account & Billing', 'content' => 'Manage your subscription, update payment methods, view invoices, and download receipts. All billing-related actions can be found in this section.'],
        ['header' => 'Privacy & Security', 'content' => 'Your data is protected with bank-grade encryption. Review our privacy policy and configure two-factor authentication for additional security.'],
        ['header' => 'Frequently Asked', 'content' => 'Browse the most common questions from our community. If you cannot find an answer here, our support team is happy to help via live chat.'],
    ],
];
$s = module_get_settings('accordion', $defaults);
$items = is_array($s['items']) ? $s['items'] : [];
$multiple = !empty($s['multiple']);
$defaultOpen = (int)$s['default_open'];
$aid = 'ac_' . substr(md5(uniqid('', true)), 0, 8);
?>
<style>
.<?= $aid ?> .ac-wrap { max-width: 820px; margin: 0 auto; padding: 0 1rem; box-sizing: border-box; }
.<?= $aid ?> .ac-head { text-align: center; margin-bottom: 2rem; }
.<?= $aid ?> .ac-head h2 { font-size: 2rem; color: #1e293b; margin: 0 0 0.4rem; font-weight: 700; }
.<?= $aid ?> .ac-head p { color: #64748b; margin: 0; }
.<?= $aid ?> .ac-item {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
    margin-bottom: 0.8rem; overflow: hidden;
    transition: border-color 0.2s, box-shadow 0.2s; box-sizing: border-box;
}
.<?= $aid ?> .ac-item:hover { border-color: #cbd5e1; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
.<?= $aid ?> .ac-item.open { border-color: #2563eb; box-shadow: 0 4px 14px rgba(37,99,235,0.12); }
.<?= $aid ?> .ac-header {
    width: 100%; background: none; border: none; cursor: pointer;
    padding: 1.1rem 1.3rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    font-size: 1.05rem; font-weight: 600; color: #1e293b; text-align: left; box-sizing: border-box;
    transition: background 0.2s;
}
.<?= $aid ?> .ac-header:hover { background: #f8fafc; }
.<?= $aid ?> .ac-header .ac-h-left { display: flex; align-items: center; gap: 0.7rem; flex: 1; }
.<?= $aid ?> .ac-header .ac-num {
    width: 28px; height: 28px; border-radius: 8px; background: #eff6ff; color: #2563eb;
    display: flex; align-items: center; justify-content: center; font-size: 0.85rem; font-weight: 700; flex-shrink: 0;
    transition: background 0.2s, color 0.2s;
}
.<?= $aid ?> .ac-item.open .ac-num { background: #2563eb; color: #fff; }
.<?= $aid ?> .ac-icon { color: #2563eb; transition: transform 0.3s; flex-shrink: 0; }
.<?= $aid ?> .ac-item.open .ac-icon { transform: rotate(180deg); }
.<?= $aid ?> .ac-body {
    max-height: 0; overflow: hidden;
    transition: max-height 0.35s ease;
}
.<?= $aid ?> .ac-content { padding: 0 1.3rem 1.2rem; color: #475569; line-height: 1.65; margin: 0; }
@media (max-width: 600px) {
    .<?= $aid ?> .ac-head h2 { font-size: 1.5rem; }
    .<?= $aid ?> .ac-header { padding: 1rem; font-size: 0.98rem; }
}
</style>
<section class="<?= $aid ?> accordion-module" style="padding: 3rem 0;">
    <div class="ac-wrap">
        <div class="ac-head">
            <h2><?= htmlspecialchars($s['title']) ?></h2>
            <?php if (!empty($s['subtitle'])): ?>
                <p><?= htmlspecialchars($s['subtitle']) ?></p>
            <?php endif; ?>
        </div>
        <?php foreach ($items as $i => $it): ?>
            <div class="ac-item<?= $i === $defaultOpen ? ' open' : '' ?>">
                <button class="ac-header" aria-expanded="<?= $i === $defaultOpen ? 'true' : 'false' ?>">
                    <span class="ac-h-left">
                        <span class="ac-num"><?= $i + 1 ?></span>
                        <span><?= htmlspecialchars($it['header'] ?? '') ?></span>
                    </span>
                    <i class="ac-icon <?= htmlspecialchars($s['icon_open']) ?>"></i>
                </button>
                <div class="ac-body">
                    <p class="ac-content"><?= htmlspecialchars($it['content'] ?? '') ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<script>
(function(){
    var root = document.querySelector('.<?= $aid ?>');
    if (!root) return;
    var items = root.querySelectorAll('.ac-item');
    var multiple = <?= $multiple ? 'true' : 'false' ?>;
    function setBody(item){
        var body = item.querySelector('.ac-body');
        var header = item.querySelector('.ac-header');
        if (item.classList.contains('open')) {
            body.style.maxHeight = body.scrollHeight + 'px';
            header.setAttribute('aria-expanded', 'true');
        } else {
            body.style.maxHeight = '0';
            header.setAttribute('aria-expanded', 'false');
        }
    }
    items.forEach(function(item){
        var header = item.querySelector('.ac-header');
        header.addEventListener('click', function(){
            var isOpen = item.classList.contains('open');
            if (!multiple && !isOpen) {
                items.forEach(function(other){
                    if (other !== item && other.classList.contains('open')) {
                        other.classList.remove('open');
                        setBody(other);
                    }
                });
            }
            item.classList.toggle('open');
            setBody(item);
        });
    });
    // Initialize default open panel(s)
    items.forEach(function(item){
        if (item.classList.contains('open')) {
            // Defer to ensure scrollHeight is correct after layout
            requestAnimationFrame(function(){ setBody(item); });
        }
    });
    // Recalculate on resize
    window.addEventListener('resize', function(){
        items.forEach(function(item){ if (item.classList.contains('open')) setBody(item); });
    });
})();
</script>
