<?php
/**
 * Module: Statistics Counters
 * ============================
 * Animated count-up statistics that trigger when scrolled into view.
 * Settings are stored in data/module_settings/stats.json
 *
 * Settings:
 *   - title : optional section heading
 *   - stats : array of {label, value, suffix, prefix, color}
 *
 * Usage: {{module:stats}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';

$defaults = [
    'title' => 'Our Impact in Numbers',
    'stats' => [
        ['label' => 'Happy Customers', 'value' => 12500, 'suffix' => '+', 'prefix' => '', 'color' => '#2563eb'],
        ['label' => 'Projects Completed','value' => 847,   'suffix' => '',  'prefix' => '', 'color' => '#10b981'],
        ['label' => 'Years of Experience','value' => 12,   'suffix' => '',  'prefix' => '', 'color' => '#f59e0b'],
        ['label' => 'Countries Served',  'value' => 36,    'suffix' => '',  'prefix' => '', 'color' => '#8b5cf6'],
    ],
];
$s = module_get_settings('stats', $defaults);
$stats = is_array($s['stats']) ? $s['stats'] : [];
$sid = 'st_' . substr(md5(uniqid('', true)), 0, 8);
?>
<style>
.<?= $sid ?> .st-wrap { max-width: 1140px; margin: 0 auto; padding: 0 1rem; box-sizing: border-box; }
.<?= $sid ?> .st-head { text-align: center; margin-bottom: 2.2rem; }
.<?= $sid ?> .st-head h2 { font-size: 2rem; color: #1e293b; margin: 0; font-weight: 700; }
.<?= $sid ?> .st-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; }
.<?= $sid ?> .st-card {
    background: #fff; border-radius: 16px; padding: 2rem 1.5rem; text-align: center;
    box-shadow: 0 4px 14px rgba(0,0,0,0.06); border: 1px solid #f1f5f9; box-sizing: border-box;
    transition: transform 0.25s, box-shadow 0.25s; position: relative; overflow: hidden;
}
.<?= $sid ?> .st-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
    background: var(--st-color, #2563eb);
}
.<?= $sid ?> .st-card:hover { transform: translateY(-4px); box-shadow: 0 12px 28px rgba(0,0,0,0.1); }
.<?= $sid ?> .st-icon {
    width: 54px; height: 54px; border-radius: 50%; margin: 0 auto 1rem;
    display: flex; align-items: center; justify-content: center; font-size: 1.4rem;
    background: color-mix(in srgb, var(--st-color, #2563eb) 12%, transparent);
    color: var(--st-color, #2563eb);
}
.<?= $sid ?> .st-number {
    font-size: 2.6rem; font-weight: 800; color: #1e293b; line-height: 1;
    margin-bottom: 0.5rem; display: flex; align-items: baseline; justify-content: center; gap: 0.05em;
}
.<?= $sid ?> .st-number .st-prefix { color: var(--st-color, #2563eb); }
.<?= $sid ?> .st-number .st-suffix { color: var(--st-color, #2563eb); font-size: 1.8rem; }
.<?= $sid ?> .st-label { color: #64748b; font-size: 1rem; font-weight: 500; margin: 0; }
@media (max-width: 600px) {
    .<?= $sid ?> .st-number { font-size: 2rem; }
    .<?= $sid ?> .st-head h2 { font-size: 1.5rem; }
    .<?= $sid ?> .st-card { padding: 1.6rem 1rem; }
}
</style>
<section class="<?= $sid ?> stats-module" style="padding: 3rem 0;">
    <div class="st-wrap">
        <?php if (!empty($s['title'])): ?>
            <div class="st-head"><h2><?= htmlspecialchars($s['title']) ?></h2></div>
        <?php endif; ?>
        <div class="st-grid">
            <?php
            // Icons mapped per-index for variety; admin may override via 'icon' field
            $defaultIcons = ['fas fa-users', 'fas fa-check-circle', 'fas fa-award', 'fas fa-globe'];
            foreach ($stats as $i => $st):
                $color = !empty($st['color']) ? $st['color'] : '#2563eb';
                $icon  = !empty($st['icon']) ? $st['icon'] : ($defaultIcons[$i % 4]);
                $value = (float)($st['value'] ?? 0);
                $isInt = floor($value) == $value; ?>
                <div class="st-card" style="--st-color: <?= htmlspecialchars($color) ?>;">
                    <div class="st-icon"><i class="<?= htmlspecialchars($icon) ?>"></i></div>
                    <div class="st-number">
                        <?php if (!empty($st['prefix'])): ?><span class="st-prefix"><?= htmlspecialchars($st['prefix']) ?></span><?php endif; ?>
                        <span class="st-val" data-target="<?= htmlspecialchars($value) ?>" data-int="<?= $isInt ? '1' : '0' ?>">0</span>
                        <?php if (!empty($st['suffix'])): ?><span class="st-suffix"><?= htmlspecialchars($st['suffix']) ?></span><?php endif; ?>
                    </div>
                    <p class="st-label"><?= htmlspecialchars($st['label'] ?? '') ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<script>
(function(){
    var root = document.querySelector('.<?= $sid ?>');
    if (!root) return;
    var els = root.querySelectorAll('.st-val');
    var done = false;
    function easeOut(t){ return 1 - Math.pow(1 - t, 3); }
    function animate(el){
        var target = parseFloat(el.getAttribute('data-target')) || 0;
        var isInt = el.getAttribute('data-int') === '1';
        var dur = 1800, start = null;
        function step(ts){
            if (!start) start = ts;
            var p = Math.min((ts - start) / dur, 1);
            var v = target * easeOut(p);
            el.textContent = isInt ? Math.round(v).toLocaleString() : v.toFixed(1);
            if (p < 1) requestAnimationFrame(step);
            else el.textContent = isInt ? Math.round(target).toLocaleString() : target.toFixed(1);
        }
        requestAnimationFrame(step);
    }
    function run(){
        if (done) return;
        var rect = root.getBoundingClientRect();
        if (rect.top < window.innerHeight && rect.bottom > 0) {
            done = true;
            els.forEach(animate);
        }
    }
    run();
    window.addEventListener('scroll', run, { passive: true });
    window.addEventListener('resize', run);
})();
</script>
