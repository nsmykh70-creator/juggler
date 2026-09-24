<?php
/**
 * Module: Countdown Timer
 * ========================
 * Displays a live countdown to a target date.
 * Settings are stored in data/module_settings/countdown_timer.json
 *
 * Settings:
 *   - target_date  : target datetime (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)
 *   - title        : heading above the timer
 *   - show_days    : show days unit
 *   - show_hours   : show hours unit
 *   - show_minutes : show minutes unit
 *   - show_seconds : show seconds unit
 *   - bg_color     : background color
 *   - expired_text : text shown after the countdown reaches zero
 *
 * Usage: {{module:countdown_timer}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';

$defaults = [
    'target_date'  => date('Y-m-d', strtotime('+30 days')),
    'title'        => 'До акции осталось:',
    'show_days'    => true,
    'show_hours'   => true,
    'show_minutes' => true,
    'show_seconds' => true,
    'bg_color'     => '#1e293b',
    'expired_text' => 'Время истекло!',
];
$s = module_get_settings('countdown_timer', $defaults);

// Generate unique ID for this instance
$uid = 'cd_' . substr(md5(uniqid('', true)), 0, 8);
$target = strtotime($s['target_date']);
$expired = $target <= time();
?>
<div class="countdown-module" style="margin:2rem 0;">
    <div id="<?= $uid ?>" style="background:<?= htmlspecialchars($s['bg_color']) ?>; border-radius:16px; padding:2.5rem 1.5rem; text-align:center; color:#fff;">
        <h3 style="margin-top:0; margin-bottom:1.5rem; font-size:1.3rem; opacity:0.95;"><?= htmlspecialchars($s['title']) ?></h3>
        <?php if ($expired): ?>
            <div style="font-size:1.5rem; font-weight:700; padding:1rem;"><?= htmlspecialchars($s['expired_text']) ?></div>
        <?php else: ?>
            <div style="display:flex; justify-content:center; gap:1rem; flex-wrap:wrap;">
                <?php if (!empty($s['show_days'])): ?>
                <div style="background:rgba(255,255,255,0.1); border-radius:12px; padding:1rem; min-width:80px;">
                    <div id="<?= $uid ?>_d" style="font-size:2.2rem; font-weight:800; line-height:1;">00</div>
                    <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.1em; opacity:0.8; margin-top:0.4rem;">дней</div>
                </div>
                <?php endif; ?>
                <?php if (!empty($s['show_hours'])): ?>
                <div style="background:rgba(255,255,255,0.1); border-radius:12px; padding:1rem; min-width:80px;">
                    <div id="<?= $uid ?>_h" style="font-size:2.2rem; font-weight:800; line-height:1;">00</div>
                    <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.1em; opacity:0.8; margin-top:0.4rem;">часов</div>
                </div>
                <?php endif; ?>
                <?php if (!empty($s['show_minutes'])): ?>
                <div style="background:rgba(255,255,255,0.1); border-radius:12px; padding:1rem; min-width:80px;">
                    <div id="<?= $uid ?>_m" style="font-size:2.2rem; font-weight:800; line-height:1;">00</div>
                    <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.1em; opacity:0.8; margin-top:0.4rem;">минут</div>
                </div>
                <?php endif; ?>
                <?php if (!empty($s['show_seconds'])): ?>
                <div style="background:rgba(255,255,255,0.1); border-radius:12px; padding:1rem; min-width:80px;">
                    <div id="<?= $uid ?>_s" style="font-size:2.2rem; font-weight:800; line-height:1;">00</div>
                    <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.1em; opacity:0.8; margin-top:0.4rem;">секунд</div>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php if (!$expired): ?>
<script>
(function() {
    var target = <?= $target ?> * 1000;
    var uid = '<?= $uid ?>';
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function tick() {
        var now = Date.now();
        var diff = target - now;
        if (diff <= 0) {
            location.reload();
            return;
        }
        var d = Math.floor(diff / 86400000);
        var h = Math.floor((diff % 86400000) / 3600000);
        var m = Math.floor((diff % 3600000) / 60000);
        var sec = Math.floor((diff % 60000) / 1000);
        var el;
        if (el = document.getElementById(uid + '_d')) el.textContent = pad(d);
        if (el = document.getElementById(uid + '_h')) el.textContent = pad(h);
        if (el = document.getElementById(uid + '_m')) el.textContent = pad(m);
        if (el = document.getElementById(uid + '_s')) el.textContent = pad(sec);
    }
    tick();
    setInterval(tick, 1000);
})();
</script>
<?php endif; ?>
