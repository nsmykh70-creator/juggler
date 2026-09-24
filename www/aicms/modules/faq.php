<?php
/**
 * Module: FAQ Accordion
 * =====================
 * Displays FAQ items as a native accordion using <details>/<summary>.
 * No JavaScript required — uses built-in browser behavior.
 * Settings are stored in data/module_settings/faq.json
 *
 * Settings:
 *   - title    : section heading
 *   - subtitle : optional subtitle
 *   - items    : array of {question, answer}
 *
 * Usage: {{module:faq}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';

$defaults = [
    'title'    => 'Frequently Asked Questions',
    'subtitle' => 'Answers to common questions about our service',
    'items'    => [
        ['question' => 'How does the service work?',   'answer' => 'Simply sign up, choose a plan, and start using all features immediately. No setup required.'],
        ['question' => 'Can I cancel anytime?',         'answer' => 'Yes, you can cancel your subscription at any time with no penalties or hidden fees.'],
        ['question' => 'Do you offer support?',         'answer' => 'We provide 24/7 customer support via email, chat, and phone for all paid plans.'],
        ['question' => 'Is my data secure?',            'answer' => 'Absolutely. We use industry-standard encryption and never share your data with third parties.'],
    ],
];
$s = module_get_settings('faq', $defaults);
$items = is_array($s['items']) ? $s['items'] : [];
?>
<style>
.faq-module { --faq-accent: #2563eb; }
.faq-module .faq-wrap { max-width: 820px; margin: 0 auto; padding: 0 1rem; }
.faq-module .faq-head { text-align: center; margin-bottom: 2rem; }
.faq-module .faq-head h2 { font-size: 2rem; color: #1e293b; margin: 0 0 0.5rem; font-weight: 700; }
.faq-module .faq-head p { color: #64748b; font-size: 1.05rem; margin: 0; }
.faq-module details {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
    margin-bottom: 0.9rem; padding: 0; box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    overflow: hidden; transition: box-shadow 0.2s, transform 0.2s;
    box-sizing: border-box;
}
.faq-module details:hover { box-shadow: 0 4px 14px rgba(37,99,235,0.12); transform: translateY(-1px); }
.faq-module details[open] { border-color: var(--faq-accent); box-shadow: 0 4px 14px rgba(37,99,235,0.15); }
.faq-module summary {
    list-style: none; cursor: pointer; padding: 1.1rem 1.3rem;
    font-weight: 600; font-size: 1.05rem; color: #1e293b;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
}
.faq-module summary::-webkit-details-marker { display: none; }
.faq-module summary .faq-q-icon {
    flex-shrink: 0; width: 28px; height: 28px; border-radius: 50%;
    background: #eff6ff; color: var(--faq-accent);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 0.85rem; transition: transform 0.25s, background 0.2s;
}
.faq-module details[open] summary .faq-q-icon { transform: rotate(135deg); background: var(--faq-accent); color: #fff; }
.faq-module .faq-a {
    padding: 0 1.3rem 1.2rem; color: #475569; line-height: 1.65; margin: 0;
}
@media (max-width: 600px) {
    .faq-module .faq-head h2 { font-size: 1.5rem; }
    .faq-module summary { padding: 1rem; font-size: 0.98rem; }
}
</style>
<section class="faq-module" style="padding: 3rem 0;">
    <div class="faq-wrap">
        <div class="faq-head">
            <h2><?= htmlspecialchars($s['title']) ?></h2>
            <?php if (!empty($s['subtitle'])): ?>
                <p><?= htmlspecialchars($s['subtitle']) ?></p>
            <?php endif; ?>
        </div>
        <?php foreach ($items as $item):
            $q = $item['question'] ?? '';
            $a = $item['answer']   ?? ''; ?>
            <details>
                <summary>
                    <span><?= htmlspecialchars($q) ?></span>
                    <span class="faq-q-icon"><i class="fas fa-plus"></i></span>
                </summary>
                <p class="faq-a"><?= htmlspecialchars($a) ?></p>
            </details>
        <?php endforeach; ?>
        <?php if (empty($items)): ?>
            <p style="text-align:center; color:#94a3b8;">No FAQ items configured.</p>
        <?php endif; ?>
    </div>
</section>
