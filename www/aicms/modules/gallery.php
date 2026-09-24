<?php
/**
 * Module: Image Gallery
 * ======================
 * Responsive image gallery with a simple JavaScript lightbox.
 * Settings are stored in data/module_settings/gallery.json
 *
 * Settings:
 *   - title    : section heading
 *   - columns  : grid columns (2-4)
 *   - images   : array of {url, alt, caption}
 *
 * Usage: {{module:gallery}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';

$defaults = [
    'title'   => 'Gallery',
    'columns' => 3,
    'images'  => [
        ['url' => 'https://picsum.photos/seed/g1/600/400', 'alt' => 'Gallery image 1', 'caption' => 'Mountain view'],
        ['url' => 'https://picsum.photos/seed/g2/600/400', 'alt' => 'Gallery image 2', 'caption' => 'Ocean sunset'],
        ['url' => 'https://picsum.photos/seed/g3/600/400', 'alt' => 'Gallery image 3', 'caption' => 'City lights'],
        ['url' => 'https://picsum.photos/seed/g4/600/400', 'alt' => 'Gallery image 4', 'caption' => 'Forest path'],
        ['url' => 'https://picsum.photos/seed/g5/600/400', 'alt' => 'Gallery image 5', 'caption' => 'Desert dunes'],
        ['url' => 'https://picsum.photos/seed/g6/600/400', 'alt' => 'Gallery image 6', 'caption' => 'Northern lights'],
    ],
];
$s = module_get_settings('gallery', $defaults);
$cols   = max(2, min(4, (int)$s['columns']));
$images = is_array($s['images']) ? $s['images'] : [];
$gid    = 'gal_' . substr(md5(uniqid('', true)), 0, 8);
?>
<style>
.<?= $gid ?> .gal-wrap { max-width: 1140px; margin: 0 auto; padding: 0 1rem; box-sizing: border-box; }
.<?= $gid ?> .gal-head { text-align: center; margin-bottom: 2rem; }
.<?= $gid ?> .gal-head h2 { font-size: 2rem; color: #1e293b; margin: 0 0 0.4rem; font-weight: 700; }
.<?= $gid ?> .gal-head p { color: #64748b; margin: 0; }
.<?= $gid ?> .gal-grid {
    display: grid; grid-template-columns: repeat(<?= $cols ?>, 1fr); gap: 1rem;
}
.<?= $gid ?> .gal-item {
    position: relative; overflow: hidden; border-radius: 12px; cursor: pointer;
    aspect-ratio: 4 / 3; background: #f1f5f9;
    box-shadow: 0 2px 6px rgba(0,0,0,0.06); transition: transform 0.25s, box-shadow 0.25s;
}
.<?= $gid ?> .gal-item:hover { transform: translateY(-3px); box-shadow: 0 10px 24px rgba(37,99,235,0.18); }
.<?= $gid ?> .gal-item img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.4s; }
.<?= $gid ?> .gal-item:hover img { transform: scale(1.06); }
.<?= $gid ?> .gal-cap {
    position: absolute; left: 0; right: 0; bottom: 0; padding: 0.7rem 0.9rem;
    background: linear-gradient(transparent, rgba(0,0,0,0.7)); color: #fff;
    font-size: 0.9rem; opacity: 0; transition: opacity 0.25s;
}
.<?= $gid ?> .gal-item:hover .gal-cap { opacity: 1; }
.<?= $gid ?> .gal-zoom {
    position: absolute; top: 0.7rem; right: 0.7rem; width: 32px; height: 32px;
    background: rgba(37,99,235,0.9); color: #fff; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; font-size: 0.8rem;
    opacity: 0; transition: opacity 0.25s;
}
.<?= $gid ?> .gal-item:hover .gal-zoom { opacity: 1; }
.<?= $gid ?> .gal-lightbox {
    position: fixed; inset: 0; background: rgba(15,23,42,0.92); z-index: 9999;
    display: none; align-items: center; justify-content: center; padding: 2rem; box-sizing: border-box;
}
.<?= $gid ?> .gal-lightbox.active { display: flex; }
.<?= $gid ?> .gal-lb-inner { max-width: 90vw; max-height: 88vh; text-align: center; }
.<?= $gid ?> .gal-lb-inner img { max-width: 100%; max-height: 80vh; border-radius: 8px; box-shadow: 0 12px 40px rgba(0,0,0,0.5); }
.<?= $gid ?> .gal-lb-cap { color: #fff; margin-top: 1rem; font-size: 1rem; }
.<?= $gid ?> .gal-lb-close {
    position: absolute; top: 1.2rem; right: 1.5rem; background: none; border: none;
    color: #fff; font-size: 2rem; cursor: pointer; line-height: 1;
}
.<?= $gid ?> .gal-lb-nav {
    position: absolute; top: 50%; transform: translateY(-50%);
    background: rgba(255,255,255,0.12); border: none; color: #fff;
    width: 48px; height: 48px; border-radius: 50%; cursor: pointer; font-size: 1.2rem;
    display: flex; align-items: center; justify-content: center; transition: background 0.2s;
}
.<?= $gid ?> .gal-lb-nav:hover { background: rgba(255,255,255,0.25); }
.<?= $gid ?> .gal-lb-prev { left: 1.5rem; }
.<?= $gid ?> .gal-lb-next { right: 1.5rem; }
@media (max-width: 768px) {
    .<?= $gid ?> .gal-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 480px) {
    .<?= $gid ?> .gal-grid { grid-template-columns: 1fr; }
    .<?= $gid ?> .gal-lb-nav { width: 38px; height: 38px; }
}
</style>
<section class="<?= $gid ?> gallery-module" style="padding: 3rem 0;">
    <div class="gal-wrap">
        <div class="gal-head">
            <h2><?= htmlspecialchars($s['title']) ?></h2>
        </div>
        <div class="gal-grid">
            <?php foreach ($images as $i => $img):
                $url  = $img['url'] ?? '';
                $alt  = $img['alt'] ?? '';
                $cap  = $img['caption'] ?? ''; ?>
                <div class="gal-item" data-idx="<?= (int)$i ?>">
                    <img src="<?= htmlspecialchars($url) ?>" alt="<?= htmlspecialchars($alt) ?>" loading="lazy">
                    <span class="gal-zoom"><i class="fas fa-expand"></i></span>
                    <?php if (!empty($cap)): ?>
                        <span class="gal-cap"><?= htmlspecialchars($cap) ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="gal-lightbox" role="dialog" aria-modal="true">
        <button class="gal-lb-close" aria-label="Close">&times;</button>
        <button class="gal-lb-nav gal-lb-prev" aria-label="Previous"><i class="fas fa-chevron-left"></i></button>
        <button class="gal-lb-nav gal-lb-next" aria-label="Next"><i class="fas fa-chevron-right"></i></button>
        <div class="gal-lb-inner">
            <img src="" alt="">
            <div class="gal-lb-cap"></div>
        </div>
    </div>
</section>
<script>
(function(){
    var root = document.querySelector('.<?= $gid ?>');
    if (!root) return;
    var items = root.querySelectorAll('.gal-item');
    var lb = root.querySelector('.gal-lightbox');
    var lbImg = lb.querySelector('img');
    var lbCap = lb.querySelector('.gal-lb-cap');
    var current = 0;
    var data = [
        <?php foreach ($images as $img): ?>
        { url: <?= json_encode($img['url'] ?? '') ?>, alt: <?= json_encode($img['alt'] ?? '') ?>, cap: <?= json_encode($img['caption'] ?? '') ?> },
        <?php endforeach; ?>
    ];
    function show(i){
        if (!data.length) return;
        current = (i + data.length) % data.length;
        lbImg.src = data[current].url;
        lbImg.alt = data[current].alt;
        lbCap.textContent = data[current].cap;
    }
    items.forEach(function(el){
        el.addEventListener('click', function(){
            show(parseInt(el.getAttribute('data-idx'), 10) || 0);
            lb.classList.add('active');
            document.body.style.overflow = 'hidden';
        });
    });
    lb.querySelector('.gal-lb-close').addEventListener('click', function(){
        lb.classList.remove('active');
        document.body.style.overflow = '';
    });
    lb.querySelector('.gal-lb-prev').addEventListener('click', function(){ show(current - 1); });
    lb.querySelector('.gal-lb-next').addEventListener('click', function(){ show(current + 1); });
    lb.addEventListener('click', function(e){ if (e.target === lb){ lb.classList.remove('active'); document.body.style.overflow = ''; } });
    document.addEventListener('keydown', function(e){
        if (!lb.classList.contains('active')) return;
        if (e.key === 'Escape'){ lb.classList.remove('active'); document.body.style.overflow = ''; }
        if (e.key === 'ArrowLeft') show(current - 1);
        if (e.key === 'ArrowRight') show(current + 1);
    });
})();
</script>
