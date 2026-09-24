<?php
/**
 * Module: Progress / Skill Bars
 * ==============================
 * Horizontal progress bars with animated fill on scroll into view.
 * Settings are stored in data/module_settings/progress.json
 *
 * Settings:
 *   - title    : section heading
 *   - subtitle : optional subtitle
 *   - animate  : (bool) animate fill on scroll
 *   - bars     : array of {label, percentage, color}
 *
 * Usage: {{module:progress}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';

$defaults = [
    'title'    => 'Our Skills & Expertise',
    'subtitle' => 'Measured proficiency across key competencies',
    'animate'  => true,
    'bars'     => [
        ['label' => 'Web Development',  'percentage' => 95, 'color' => '#2563eb'],
        ['label' => 'UI / UX Design',   'percentage' => 88, 'color' => '#8b5cf6'],
        ['label' => 'Mobile Development','percentage' => 82, 'color' => '#10b981'],
        ['label' => 'Cloud & DevOps',   'percentage' => 78, 'color' => '#f59e0b'],
        ['label' => 'Data Analytics',   'percentage' => 70, 'color' => '#ef4444'],
    ],
];
$s = module_get_settings('progress', $defaults);
$bars    = is_array($s['bars']) ? $s['bars'] : [];
$animate = !empty($s['animate']);
$pid = 'pr_' . substr(md5(uniqid('', true)), 0, 8);
?>
<style>
.<?= $pid ?> .pb-wrap { max-width: 820px; margin: 0 auto; padding: 0 1rem; box-sizing: border-box; }
.<?= $pid ?> .pb-head { text-align: center; margin-bottom: 2.2rem; }
.<?= $pid ?> .pb-head h2 { font-size: 2rem; color: #1e293b; margin: 0 0 0.4rem; font-weight: 700; }
.<?= $pid ?> .pb-head p { color: #64748b; margin: 0; }
.<?= $pid ?> .pb-item { margin-bottom: 1.4rem; }
.<?= $pid ?> .pb-top {
    display: flex; justify-content: space-between; align-items: baseline;
    margin-bottom: 0.45rem;
}
.<?= $pid ?> .pb-label { font-weight: 600; color: #1e293b; font-size: 0.98rem; }
.<?= $pid ?> .pb-label i { color: #2563eb; margin-right: 0.45rem; }
.<?= $pid ?> .pb-pct { font-weight: 700; color: #475569; font-size: 0.95rem; }
.<?= $pid ?> .pb-track {
    background: #f1f5f9; border-radius: 999px; height: 12px; overflow: hidden;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
}
.<?= $pid ?> .pb-fill {
    height: 100%; width: 0; border-radius: 999px;
    background: var(--pb-color, #2563eb);
    background-image: linear-gradient(90deg, var(--pb-color, #2563eb), color-mix(in srgb, var(--pb-color, #2563eb) 70%, #fff));
    transition: width 1.6s cubic-bezier(0.22, 1, 0.36, 1);
    position: relative; overflow: hidden;
}
.<?= $pid ?> .pb-fill::after {
    content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
    animation: pb-shine 2s infinite;
}
@keyframes pb-shine { 0% { transform: translateX(-100%);} 100% { transform: translateX(100%);} }
@media (max-width: 600px) {
    .<?= $pid ?> .pb-head h2 { font-size: 1.5rem; }
    .<?= $pid ?> .pb-label { font-size: 0.9rem; }
}
</style>
<section class="<?= $pid ?> progress-module" style="padding: 3rem 0;">
    <div class="pb-wrap">
        <div class="pb-head">
            <h2><?= htmlspecialchars($s['title']) ?></h2>
            <?php if (!empty($s['subtitle'])): ?>
                <p><?= htmlspecialchars($s['subtitle']) ?></p>
            <?php endif; ?>
        </div>
        <?php foreach ($bars as $bar):
            $pct = max(0, min(100, (int)($bar['percentage'] ?? 0)));
            $color = !empty($bar['color']) ? $bar['color'] : '#2563eb'; ?>
            <div class="pb-item">
                <div class="pb-top">
                    <span class="pb-label"><i class="fas fa-circle-check"></i><?= htmlspecialchars($bar['label'] ?? '') ?></span>
                    <span class="pb-pct"><?= $pct ?>%</span>
                </div>
                <div class="pb-track">
                    <div class="pb-fill" style="--pb-color: <?= htmlspecialchars($color) ?>;" data-target="<?= $pct ?>"></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<script>
(function(){
    var root = document.querySelector('.<?= $pid ?>');
    if (!root) return;
    var fills = root.querySelectorAll('.pb-fill');
    var triggered = false;
    var animate = <?= $animate ? 'true' : 'false' ?>;
    function setFill(f, w){ f.style.width = w + '%'; }
    function run(){
        if (triggered) return;
        var rect = root.getBoundingClientRect();
        if (rect.top < window.innerHeight && rect.bottom > 0) {
            triggered = true;
            fills.forEach(function(f){
                var w = parseInt(f.getAttribute('data-target'), 10) || 0;
                if (animate) {
                    setTimeout(function(){ setFill(f, w); }, 80);
                } else {
                    setFill(f, w);
                }
            });
        }
    }
    // If not animating, fill immediately
    if (!animate) { fills.forEach(function(f){ setFill(f, parseInt(f.getAttribute('data-target'), 10) || 0); }); }
    run();
    window.addEventListener('scroll', run, { passive: true });
})();
</script>
