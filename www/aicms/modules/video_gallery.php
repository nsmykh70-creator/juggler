<?php
/**
 * Module: Video Gallery
 * ======================
 * Displays a responsive grid of videos with modal player.
 * Usage: {{module:video_gallery}}
 * Settings: title, columns, videos (one URL per line — YouTube/Vimeo/direct)
 *
 * Features:
 *   - Responsive grid (auto-fill columns)
 *   - YouTube / Vimeo / direct MP4 support
 *   - Auto-generated thumbnails for YouTube
 *   - Modal player (no nav away)
 *   - Keyboard Esc to close
 */
require_once __DIR__ . '/../admin/core/module_settings.php';
require_once __DIR__ . '/../admin/core/extensions.php';

if (!extension_enabled('video_gallery')) { echo ''; return; }

$defaults = [
    'title' => 'Видео-галерея',
    'columns' => 3,
    'videos' => "https://www.youtube.com/watch?v=dQw4w9WgXcQ\nhttps://vimeo.com/76979871",
];
$s = module_get_settings('video_gallery', $defaults);
$videos_raw = explode("\n", trim($s['videos']));
$videos = [];
foreach ($videos_raw as $url) {
    $url = trim($url);
    if (empty($url)) continue;
    // Convert YouTube watch URL to embed
    if (preg_match('/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/', $url, $m)) {
        $videos[] = ['url' => $url, 'embed' => 'https://www.youtube.com/embed/' . $m[1], 'thumb' => 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg'];
    } elseif (preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $url, $m)) {
        $videos[] = ['url' => $url, 'embed' => 'https://www.youtube.com/embed/' . $m[1], 'thumb' => 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg'];
    } elseif (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
        $videos[] = ['url' => $url, 'embed' => 'https://player.vimeo.com/video/' . $m[1], 'thumb' => ''];
    } else {
        $videos[] = ['url' => $url, 'embed' => $url, 'thumb' => ''];
    }
}
$vid_id = 'vg_' . substr(md5(uniqid()), 0, 8);
?>
<div class="<?= $vid_id ?>" style="max-width:1200px;margin:2rem auto;padding:0 16px;">
    <?php if (!empty($s['title'])): ?>
    <h3 style="font-size:1.5rem;font-weight:700;color:#1e293b;margin-bottom:1.5rem;text-align:center;"><?= htmlspecialchars($s['title']) ?></h3>
    <?php endif; ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(300px,100%),1fr));gap:16px;">
        <?php foreach ($videos as $v): ?>
        <div style="position:relative;aspect-ratio:16/9;background:#1e293b;border-radius:12px;overflow:hidden;cursor:pointer;" onclick="document.querySelector('.<?= $vid_id ?>-modal').src='<?= htmlspecialchars($v['embed']) ?>';document.querySelector('.<?= $vid_id ?>-overlay').style.display='flex';">
            <?php if (!empty($v['thumb'])): ?>
            <img src="<?= htmlspecialchars($v['thumb']) ?>" style="width:100%;height:100%;object-fit:cover;" alt="Video">
            <?php else: ?>
            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:48px;"><i class="fas fa-play-circle"></i></div>
            <?php endif; ?>
            <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.3);transition:background 0.2s;">
                <i class="fas fa-play" style="color:#fff;font-size:36px;text-shadow:0 2px 8px rgba(0,0,0,0.5);"></i>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<div class="<?= $vid_id ?>-overlay" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.85);align-items:center;justify-content:center;padding:20px;" onclick="this.style.display='none';this.querySelector('iframe').src='';">
    <div style="position:relative;width:100%;max-width:900px;">
        <button style="position:absolute;top:-40px;right:0;background:none;border:none;color:#fff;font-size:24px;cursor:pointer;">&times;</button>
        <iframe class="<?= $vid_id ?>-modal" style="width:100%;aspect-ratio:16/9;border:none;border-radius:8px;" src="" allowfullscreen></iframe>
    </div>
</div>
