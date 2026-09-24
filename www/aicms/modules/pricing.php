<?php
/**
 * Module: Pricing Cards
 * ======================
 * Three-tier pricing table with a highlighted "popular" plan.
 * Settings are stored in data/module_settings/pricing.json
 *
 * Settings:
 *   - title    : section heading
 *   - subtitle : optional subtitle
 *   - currency : currency symbol ($, €, £, ₽…)
 *   - period   : billing period label (e.g. "/mo")
 *   - plans    : array of {name, price, features[], button_text, button_url, popular}
 *
 * Usage: {{module:pricing}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';

$defaults = [
    'title'    => 'Simple, Transparent Pricing',
    'subtitle' => 'Choose the plan that fits your needs. Cancel anytime.',
    'currency' => '$',
    'period'   => '/mo',
    'plans'    => [
        [
            'name'        => 'Starter',
            'price'       => '9',
            'features'    => ['1 project', '5 GB storage', 'Community support', 'Basic analytics'],
            'button_text' => 'Get Started',
            'button_url'  => '#',
            'popular'     => false,
        ],
        [
            'name'        => 'Pro',
            'price'       => '29',
            'features'    => ['10 projects', '50 GB storage', 'Priority support', 'Advanced analytics', 'Custom domains', 'Team collaboration'],
            'button_text' => 'Start Free Trial',
            'button_url'  => '#',
            'popular'     => true,
        ],
        [
            'name'        => 'Enterprise',
            'price'       => '99',
            'features'    => ['Unlimited projects', '500 GB storage', '24/7 dedicated support', 'Custom integrations', 'SLA guarantee'],
            'button_text' => 'Contact Sales',
            'button_url'  => '#',
            'popular'     => false,
        ],
    ],
];
$s = module_get_settings('pricing', $defaults);
$plans = is_array($s['plans']) ? $s['plans'] : [];
?>
<style>
.pricing-module .pr-wrap { max-width: 1140px; margin: 0 auto; padding: 0 1rem; box-sizing: border-box; }
.pricing-module .pr-head { text-align: center; margin-bottom: 2.5rem; }
.pricing-module .pr-head h2 { font-size: 2rem; color: #1e293b; margin: 0 0 0.5rem; font-weight: 700; }
.pricing-module .pr-head p { color: #64748b; font-size: 1.1rem; margin: 0; }
.pricing-module .pr-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; align-items: stretch; }
.pricing-module .pr-card {
    background: #fff; border-radius: 16px; padding: 2rem 1.8rem; box-sizing: border-box;
    border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    display: flex; flex-direction: column; transition: transform 0.25s, box-shadow 0.25s; position: relative;
}
.pricing-module .pr-card:hover { transform: translateY(-4px); box-shadow: 0 12px 28px rgba(0,0,0,0.1); }
.pricing-module .pr-card.popular {
    border: 2px solid #2563eb; box-shadow: 0 12px 28px rgba(37,99,235,0.2);
    transform: scale(1.03);
}
.pricing-module .pr-card.popular:hover { transform: scale(1.03) translateY(-4px); }
.pricing-module .pr-badge {
    position: absolute; top: -12px; left: 50%; transform: translateX(-50%);
    background: #2563eb; color: #fff; font-size: 0.75rem; font-weight: 700;
    padding: 0.3rem 0.9rem; border-radius: 999px; letter-spacing: 0.04em; text-transform: uppercase;
    white-space: nowrap;
}
.pricing-module .pr-name { font-size: 1.2rem; font-weight: 600; color: #1e293b; margin: 0 0 0.5rem; }
.pricing-module .pr-desc { color: #94a3b8; font-size: 0.9rem; margin: 0 0 1.2rem; }
.pricing-module .pr-price { display: flex; align-items: baseline; gap: 0.2rem; margin-bottom: 1.5rem; }
.pricing-module .pr-cur { font-size: 1.4rem; color: #64748b; font-weight: 600; }
.pricing-module .pr-amt { font-size: 3rem; color: #1e293b; font-weight: 800; line-height: 1; }
.pricing-module .pr-per { color: #94a3b8; font-size: 0.95rem; }
.pricing-module .pr-feats { list-style: none; padding: 0; margin: 0 0 1.8rem; flex: 1; }
.pricing-module .pr-feats li {
    display: flex; align-items: flex-start; gap: 0.6rem; padding: 0.5rem 0;
    color: #475569; font-size: 0.95rem; line-height: 1.5; border-bottom: 1px solid #f1f5f9;
}
.pricing-module .pr-feats li:last-child { border-bottom: none; }
.pricing-module .pr-feats li i { color: #10b981; margin-top: 0.2rem; flex-shrink: 0; }
.pricing-module .pr-btn {
    display: block; text-align: center; padding: 0.85rem 1rem; border-radius: 10px;
    text-decoration: none; font-weight: 600; font-size: 1rem; transition: all 0.2s; box-sizing: border-box;
}
.pricing-module .pr-btn-primary { background: #2563eb; color: #fff; }
.pricing-module .pr-btn-primary:hover { background: #1d4ed8; box-shadow: 0 6px 14px rgba(37,99,235,0.35); }
.pricing-module .pr-btn-ghost { background: #f1f5f9; color: #2563eb; }
.pricing-module .pr-btn-ghost:hover { background: #e2e8f0; }
@media (max-width: 768px) {
    .pricing-module .pr-card.popular { transform: none; }
    .pricing-module .pr-card.popular:hover { transform: translateY(-4px); }
    .pricing-module .pr-head h2 { font-size: 1.5rem; }
}
</style>
<section class="pricing-module" style="padding: 3rem 0;">
    <div class="pr-wrap">
        <div class="pr-head">
            <h2><?= htmlspecialchars($s['title']) ?></h2>
            <?php if (!empty($s['subtitle'])): ?>
                <p><?= htmlspecialchars($s['subtitle']) ?></p>
            <?php endif; ?>
        </div>
        <div class="pr-grid">
            <?php foreach ($plans as $plan):
                $popular  = !empty($plan['popular']);
                $features = is_array($plan['features'] ?? null) ? $plan['features'] : []; ?>
                <div class="pr-card<?= $popular ? ' popular' : '' ?>">
                    <?php if ($popular): ?>
                        <span class="pr-badge"><i class="fas fa-star"></i> Most Popular</span>
                    <?php endif; ?>
                    <h3 class="pr-name"><?= htmlspecialchars($plan['name'] ?? '') ?></h3>
                    <div class="pr-price">
                        <span class="pr-cur"><?= htmlspecialchars($s['currency']) ?></span>
                        <span class="pr-amt"><?= htmlspecialchars($plan['price'] ?? '') ?></span>
                        <span class="pr-per"><?= htmlspecialchars($s['period']) ?></span>
                    </div>
                    <ul class="pr-feats">
                        <?php foreach ($features as $feat): ?>
                            <li><i class="fas fa-check"></i><span><?= htmlspecialchars($feat) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="<?= htmlspecialchars($plan['button_url'] ?? '#') ?>"
                       class="pr-btn <?= $popular ? 'pr-btn-primary' : 'pr-btn-ghost' ?>">
                        <?= htmlspecialchars($plan['button_text'] ?? 'Choose Plan') ?>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
