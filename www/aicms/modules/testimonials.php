<?php
/**
 * Module: Testimonials Carousel
 * ==============================
 * Auto-rotating customer testimonials with manual prev/next controls
 * and dot indicators. Includes quote styling and avatars.
 * Settings are stored in data/module_settings/testimonials.json
 *
 * Settings:
 *   - title         : section heading
 *   - subtitle      : optional subtitle
 *   - autoplay      : (bool) auto-rotate slides
 *   - interval      : autoplay delay in ms (default 5000)
 *   - testimonials  : array of {name, role, text, avatar, rating}
 *
 * Usage: {{module:testimonials}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';

$defaults = [
    'title'        => 'What Our Customers Say',
    'subtitle'     => 'Trusted by thousands of teams worldwide',
    'autoplay'     => true,
    'interval'     => 5000,
    'testimonials' => [
        [
            'name'   => 'Sarah Johnson',
            'role'   => 'CEO, TechStart Inc.',
            'text'   => 'This platform transformed how our team works. We saw a 40% increase in productivity within the first month. Highly recommended!',
            'avatar' => 'https://i.pravatar.cc/120?img=47',
            'rating' => 5,
        ],
        [
            'name'   => 'Michael Chen',
            'role'   => 'Product Manager, FlowState',
            'text'   => 'The intuitive interface and powerful features make this the best tool we have used. Support team is responsive and helpful.',
            'avatar' => 'https://i.pravatar.cc/120?img=12',
            'rating' => 5,
        ],
        [
            'name'   => 'Emily Rodriguez',
            'role'   => 'Marketing Director, BrightWave',
            'text'   => 'From onboarding to daily use, everything just works. Our marketing campaigns have never been more organized.',
            'avatar' => 'https://i.pravatar.cc/120?img=32',
            'rating' => 5,
        ],
        [
            'name'   => 'David Kim',
            'role'   => 'Founder, LaunchPad',
            'text'   => 'Worth every penny. The analytics alone have saved us countless hours and helped us make better decisions faster.',
            'avatar' => 'https://i.pravatar.cc/120?img=15',
            'rating' => 4,
        ],
    ],
];
$s = module_get_settings('testimonials', $defaults);
$items = is_array($s['testimonials']) ? $s['testimonials'] : [];
$autoplay = !empty($s['autoplay']);
$interval = max(2000, (int)$s['interval']);
$cid = 'tm_' . substr(md5(uniqid('', true)), 0, 8);
?>
<style>
.<?= $cid ?> .tm-wrap { max-width: 880px; margin: 0 auto; padding: 0 1rem; box-sizing: border-box; }
.<?= $cid ?> .tm-head { text-align: center; margin-bottom: 2.2rem; }
.<?= $cid ?> .tm-head h2 { font-size: 2rem; color: #1e293b; margin: 0 0 0.4rem; font-weight: 700; }
.<?= $cid ?> .tm-head p { color: #64748b; margin: 0; }
.<?= $cid ?> .tm-stage { position: relative; min-height: 280px; overflow: hidden; }
.<?= $cid ?> .tm-track { position: relative; width: 100%; }
.<?= $cid ?> .tm-slide {
    background: #fff; border-radius: 16px; padding: 2.5rem 2rem; box-sizing: border-box;
    box-shadow: 0 6px 20px rgba(0,0,0,0.08); border: 1px solid #f1f5f9; text-align: center;
    position: absolute; top: 0; left: 0; width: 100%; opacity: 0;
    transform: translateX(30px); transition: opacity 0.5s, transform 0.5s;
    pointer-events: none;
}
.<?= $cid ?> .tm-slide.active { opacity: 1; transform: translateX(0); position: relative; pointer-events: auto; }
.<?= $cid ?> .tm-quote {
    font-size: 3rem; color: #2563eb; opacity: 0.25; line-height: 1; margin-bottom: 0.5rem;
    font-family: Georgia, serif;
}
.<?= $cid ?> .tm-text { font-size: 1.1rem; line-height: 1.7; color: #334155; margin: 0 0 1.5rem; font-style: italic; }
.<?= $cid ?> .tm-stars { color: #fbbf24; margin-bottom: 1.2rem; font-size: 1rem; letter-spacing: 0.1em; }
.<?= $cid ?> .tm-stars i { margin: 0 1px; }
.<?= $cid ?> .tm-author { display: flex; align-items: center; justify-content: center; gap: 0.8rem; }
.<?= $cid ?> .tm-avatar {
    width: 54px; height: 54px; border-radius: 50%; object-fit: cover;
    border: 3px solid #eff6ff;
}
.<?= $cid ?> .tm-avatar-fb {
    width: 54px; height: 54px; border-radius: 50%; background: #2563eb; color: #fff;
    display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.2rem;
    border: 3px solid #eff6ff;
}
.<?= $cid ?> .tm-meta { text-align: left; }
.<?= $cid ?> .tm-name { font-weight: 700; color: #1e293b; font-size: 1rem; }
.<?= $cid ?> .tm-role { color: #64748b; font-size: 0.85rem; }
.<?= $cid ?> .tm-controls { display: flex; align-items: center; justify-content: center; gap: 1rem; margin-top: 1.6rem; }
.<?= $cid ?> .tm-arrow {
    width: 44px; height: 44px; border-radius: 50%; border: 1px solid #e2e8f0;
    background: #fff; color: #2563eb; cursor: pointer; font-size: 0.9rem;
    display: flex; align-items: center; justify-content: center; transition: all 0.2s;
}
.<?= $cid ?> .tm-arrow:hover { background: #2563eb; color: #fff; border-color: #2563eb; }
.<?= $cid ?> .tm-dots { display: flex; gap: 0.5rem; }
.<?= $cid ?> .tm-dot {
    width: 10px; height: 10px; border-radius: 50%; background: #cbd5e1;
    cursor: pointer; transition: all 0.2s; border: none; padding: 0;
}
.<?= $cid ?> .tm-dot.active { background: #2563eb; width: 28px; border-radius: 999px; }
@media (max-width: 600px) {
    .<?= $cid ?> .tm-slide { padding: 1.8rem 1.2rem; }
    .<?= $cid ?> .tm-text { font-size: 1rem; }
    .<?= $cid ?> .tm-head h2 { font-size: 1.5rem; }
}
</style>
<section class="<?= $cid ?> testimonials-module" style="padding: 3rem 0;">
    <div class="tm-wrap">
        <div class="tm-head">
            <h2><?= htmlspecialchars($s['title']) ?></h2>
            <?php if (!empty($s['subtitle'])): ?>
                <p><?= htmlspecialchars($s['subtitle']) ?></p>
            <?php endif; ?>
        </div>
        <div class="tm-stage">
            <div class="tm-track">
                <?php foreach ($items as $i => $t):
                    $name = $t['name'] ?? '';
                    $initials = implode('', array_map(function($w){ return strtoupper(substr($w,0,1)); }, array_slice(explode(' ', $name), 0, 2)));
                    $rating = (int)($t['rating'] ?? 5); ?>
                    <div class="tm-slide<?= $i === 0 ? ' active' : '' ?>">
                        <div class="tm-quote">&ldquo;</div>
                        <div class="tm-stars">
                            <?php for ($k = 1; $k <= 5; $k++): ?>
                                <i class="fas fa-star<?= $k > $rating ? '-half-alt' : '' ?>" style="<?= $k > $rating ? 'color:#e2e8f0;' : '' ?>"></i>
                            <?php endfor; ?>
                        </div>
                        <p class="tm-text"><?= htmlspecialchars($t['text'] ?? '') ?></p>
                        <div class="tm-author">
                            <?php if (!empty($t['avatar'])): ?>
                                <img class="tm-avatar" src="<?= htmlspecialchars($t['avatar']) ?>" alt="<?= htmlspecialchars($name) ?>">
                            <?php else: ?>
                                <div class="tm-avatar-fb"><?= htmlspecialchars($initials) ?></div>
                            <?php endif; ?>
                            <div class="tm-meta">
                                <div class="tm-name"><?= htmlspecialchars($name) ?></div>
                                <div class="tm-role"><?= htmlspecialchars($t['role'] ?? '') ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="tm-controls">
            <button class="tm-arrow tm-prev" aria-label="Previous"><i class="fas fa-chevron-left"></i></button>
            <div class="tm-dots">
                <?php foreach ($items as $i => $t): ?>
                    <button class="tm-dot<?= $i === 0 ? ' active' : '' ?>" data-idx="<?= $i ?>" aria-label="Slide <?= $i + 1 ?>"></button>
                <?php endforeach; ?>
            </div>
            <button class="tm-arrow tm-next" aria-label="Next"><i class="fas fa-chevron-right"></i></button>
        </div>
    </div>
</section>
<script>
(function(){
    var root = document.querySelector('.<?= $cid ?>');
    if (!root) return;
    var slides = root.querySelectorAll('.tm-slide');
    var dots = root.querySelectorAll('.tm-dot');
    var current = 0;
    var total = slides.length;
    var autoplay = <?= $autoplay ? 'true' : 'false' ?>;
    var interval = <?= $interval ?>;
    var timer = null;
    if (!total) return;
    function go(n){
        slides[current].classList.remove('active');
        dots[current] && dots[current].classList.remove('active');
        current = (n + total) % total;
        slides[current].classList.add('active');
        dots[current] && dots[current].classList.add('active');
    }
    function next(){ go(current + 1); }
    function prev(){ go(current - 1); }
    function start(){ if (autoplay && total > 1) timer = setInterval(next, interval); }
    function stop(){ if (timer) { clearInterval(timer); timer = null; } }
    root.querySelector('.tm-next').addEventListener('click', function(){ next(); stop(); start(); });
    root.querySelector('.tm-prev').addEventListener('click', function(){ prev(); stop(); start(); });
    dots.forEach(function(d){ d.addEventListener('click', function(){ go(parseInt(d.getAttribute('data-idx'), 10)); stop(); start(); }); });
    root.addEventListener('mouseenter', stop);
    root.addEventListener('mouseleave', start);
    start();
})();
</script>
