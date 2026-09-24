<?php
/**
 * Module: Tabs
 * =============
 * Tabbed content panel with click-to-switch tabs and an animated
 * active indicator. First tab is active by default.
 * Settings are stored in data/module_settings/tabs.json
 *
 * Settings:
 *   - title    : section heading
 *   - subtitle : optional subtitle
 *   - layout   : 'horizontal' | 'vertical'
 *   - tabs     : array of {label, icon, content}
 *
 * Usage: {{module:tabs}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';

$defaults = [
    'title'    => 'Features Overview',
    'subtitle' => 'Explore everything our platform has to offer',
    'layout'   => 'horizontal',
    'tabs'     => [
        ['label' => 'Overview',  'icon' => 'fas fa-home',       'content' => 'A complete platform that brings together everything you need. Built for teams of every size, with powerful features and an intuitive interface that gets out of your way.'],
        ['label' => 'Features',  'icon' => 'fas fa-cubes',      'content' => 'Powerful features include real-time collaboration, version history, custom workflows, integrations with your favorite tools, and advanced automation capabilities.'],
        ['label' => 'Performance','icon' => 'fas fa-bolt',      'content' => 'Built on a blazing-fast infrastructure, our platform handles thousands of requests per second with sub-100ms response times worldwide.'],
        ['label' => 'Security',  'icon' => 'fas fa-shield-halved','content' => 'Enterprise-grade security with SOC 2 compliance, end-to-end encryption, SSO support, granular permissions, and detailed audit logs for full visibility.'],
    ],
];
$s = module_get_settings('tabs', $defaults);
$tabs   = is_array($s['tabs']) ? $s['tabs'] : [];
$layout = ($s['layout'] === 'vertical') ? 'vertical' : 'horizontal';
$tid = 'tb_' . substr(md5(uniqid('', true)), 0, 8);
?>
<style>
.<?= $tid ?> .tb-wrap { max-width: 960px; margin: 0 auto; padding: 0 1rem; box-sizing: border-box; }
.<?= $tid ?> .tb-head { text-align: center; margin-bottom: 2rem; }
.<?= $tid ?> .tb-head h2 { font-size: 2rem; color: #1e293b; margin: 0 0 0.4rem; font-weight: 700; }
.<?= $tid ?> .tb-head p { color: #64748b; margin: 0; }
.<?= $tid ?> .tb-shell { display: flex; flex-direction: column; }
.<?= $tid ?> .tb-shell.vertical { flex-direction: row; gap: 1.5rem; }
.<?= $tid ?> .tb-bar {
    display: flex; gap: 0.3rem; background: #fff; padding: 0.4rem; border-radius: 12px;
    border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.04); overflow-x: auto; box-sizing: border-box;
}
.<?= $tid ?> .tb-shell.vertical .tb-bar {
    flex-direction: column; min-width: 220px; padding: 0.6rem; gap: 0.2rem;
}
.<?= $tid ?> .tb-tab {
    flex: 1; min-width: max-content; padding: 0.8rem 1.2rem; border: none; background: none;
    cursor: pointer; font-size: 0.98rem; font-weight: 600; color: #64748b;
    border-radius: 8px; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 0.5rem;
    white-space: nowrap; box-sizing: border-box;
}
.<?= $tid ?> .tb-tab i { font-size: 0.9rem; }
.<?= $tid ?> .tb-tab:hover { background: #f1f5f9; color: #1e293b; }
.<?= $tid ?> .tb-tab.active { background: #2563eb; color: #fff; box-shadow: 0 4px 10px rgba(37,99,235,0.3); }
.<?= $tid ?> .tb-shell.vertical .tb-tab { justify-content: flex-start; }
.<?= $tid ?> .tb-panel-wrap { flex: 1; position: relative; margin-top: 1.2rem; min-height: 180px; }
.<?= $tid ?> .tb-shell.vertical .tb-panel-wrap { margin-top: 0; }
.<?= $tid ?> .tb-panel {
    background: #fff; border-radius: 12px; padding: 1.8rem; box-sizing: border-box;
    border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    display: none; animation: tb-fade 0.4s ease;
}
.<?= $tid ?> .tb-panel.active { display: block; }
@keyframes tb-fade { from { opacity: 0; transform: translateY(8px);} to { opacity: 1; transform: translateY(0);} }
.<?= $tid ?> .tb-panel h3 { color: #2563eb; margin: 0 0 0.8rem; font-size: 1.3rem; display: flex; align-items: center; gap: 0.6rem; }
.<?= $tid ?> .tb-panel p { color: #475569; line-height: 1.7; margin: 0; font-size: 1rem; }
.<?= $tid ?> .tb-panel .tb-icon-big {
    display: inline-flex; width: 44px; height: 44px; border-radius: 10px;
    background: #eff6ff; color: #2563eb; align-items: center; justify-content: center; font-size: 1.1rem; margin-bottom: 1rem;
}
@media (max-width: 700px) {
    .<?= $tid ?> .tb-shell.vertical { flex-direction: column; }
    .<?= $tid ?> .tb-shell.vertical .tb-bar { min-width: 0; }
    .<?= $tid ?> .tb-tab { padding: 0.7rem 0.9rem; font-size: 0.9rem; }
    .<?= $tid ?> .tb-head h2 { font-size: 1.5rem; }
}
</style>
<section class="<?= $tid ?> tabs-module" style="padding: 3rem 0;">
    <div class="tb-wrap">
        <div class="tb-head">
            <h2><?= htmlspecialchars($s['title']) ?></h2>
            <?php if (!empty($s['subtitle'])): ?>
                <p><?= htmlspecialchars($s['subtitle']) ?></p>
            <?php endif; ?>
        </div>
        <div class="tb-shell <?= $layout ?>">
            <div class="tb-bar" role="tablist">
                <?php foreach ($tabs as $i => $t): ?>
                    <button class="tb-tab<?= $i === 0 ? ' active' : '' ?>" role="tab"
                            aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
                            data-idx="<?= $i ?>">
                        <?php if (!empty($t['icon'])): ?><i class="<?= htmlspecialchars($t['icon']) ?>"></i><?php endif; ?>
                        <span><?= htmlspecialchars($t['label'] ?? '') ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="tb-panel-wrap">
                <?php foreach ($tabs as $i => $t):
                    $icon = !empty($t['icon']) ? $t['icon'] : 'fas fa-circle-info'; ?>
                    <div class="tb-panel<?= $i === 0 ? ' active' : '' ?>" role="tabpanel" data-idx="<?= $i ?>">
                        <div class="tb-icon-big"><i class="<?= htmlspecialchars($icon) ?>"></i></div>
                        <h3><?= htmlspecialchars($t['label'] ?? '') ?></h3>
                        <p><?= htmlspecialchars($t['content'] ?? '') ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
<script>
(function(){
    var root = document.querySelector('.<?= $tid ?>');
    if (!root) return;
    var tabs = root.querySelectorAll('.tb-tab');
    var panels = root.querySelectorAll('.tb-panel');
    tabs.forEach(function(tab){
        tab.addEventListener('click', function(){
            var idx = tab.getAttribute('data-idx');
            tabs.forEach(function(t){ t.classList.remove('active'); t.setAttribute('aria-selected', 'false'); });
            panels.forEach(function(p){ p.classList.remove('active'); });
            tab.classList.add('active');
            tab.setAttribute('aria-selected', 'true');
            var panel = root.querySelector('.tb-panel[data-idx="' + idx + '"]');
            if (panel) panel.classList.add('active');
        });
    });
})();
</script>
