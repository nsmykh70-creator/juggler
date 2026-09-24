<?php
/**
 * Module: Team Members
 * =====================
 * Grid of team member cards with photo, name, role, bio reveal on hover,
 * and social icon links.
 * Settings are stored in data/module_settings/team.json
 *
 * Settings:
 *   - title    : section heading
 *   - subtitle : optional subtitle
 *   - columns  : grid columns (2-4)
 *   - members  : array of {name, role, bio, photo_url, socials: {twitter, linkedin, github, email}}
 *
 * Usage: {{module:team}}
 */
require_once __DIR__ . '/../admin/core/module_settings.php';

$defaults = [
    'title'    => 'Meet Our Team',
    'subtitle' => 'The talented people behind our success',
    'columns'  => 4,
    'members'  => [
        [
            'name'     => 'Alex Morgan',
            'role'     => 'Founder & CEO',
            'bio'      => 'Visionary leader with 15+ years building products used by millions. Passionate about democratizing technology.',
            'photo_url'=> 'https://i.pravatar.cc/300?img=11',
            'socials'  => ['twitter' => '#', 'linkedin' => '#', 'email' => 'alex@example.com'],
        ],
        [
            'name'     => 'Priya Sharma',
            'role'     => 'Chief Technology Officer',
            'bio'      => 'Leads engineering with deep expertise in distributed systems and a love for clean, scalable code.',
            'photo_url'=> 'https://i.pravatar.cc/300?img=45',
            'socials'  => ['twitter' => '#', 'github' => '#', 'linkedin' => '#'],
        ],
        [
            'name'     => 'James Wilson',
            'role'     => 'Head of Design',
            'bio'      => 'Designs beautiful, intuitive experiences. Believes great design is invisible — it just works.',
            'photo_url'=> 'https://i.pravatar.cc/300?img=33',
            'socials'  => ['twitter' => '#', 'dribbble' => '#', 'email' => 'james@example.com'],
        ],
        [
            'name'     => 'Sofia Garcia',
            'role'     => 'VP of Marketing',
            'bio'      => 'Storyteller and growth strategist. Has helped 3 startups scale from zero to IPO.',
            'photo_url'=> 'https://i.pravatar.cc/300?img=20',
            'socials'  => ['linkedin' => '#', 'twitter' => '#', 'instagram' => '#'],
        ],
    ],
];
$s = module_get_settings('team', $defaults);
$cols    = max(2, min(4, (int)$s['columns']));
$members = is_array($s['members']) ? $s['members'] : [];

// Map of social key => Font Awesome class
$socialIcons = [
    'twitter'   => 'fab fa-twitter',
    'linkedin'  => 'fab fa-linkedin-in',
    'github'    => 'fab fa-github',
    'email'     => 'fas fa-envelope',
    'dribbble'  => 'fab fa-dribbble',
    'instagram' => 'fab fa-instagram',
    'facebook'  => 'fab fa-facebook-f',
    'website'   => 'fas fa-globe',
];
$socialColors = [
    'twitter'   => '#1da1f2',
    'linkedin'  => '#0a66c2',
    'github'    => '#181717',
    'email'     => '#2563eb',
    'dribbble'  => '#ea4c89',
    'instagram' => '#e4405f',
    'facebook'  => '#1877f2',
    'website'   => '#64748b',
];
$tid = 'tm_' . substr(md5(uniqid('', true)), 0, 8);
?>
<style>
.<?= $tid ?> .tm-wrap { max-width: 1140px; margin: 0 auto; padding: 0 1rem; box-sizing: border-box; }
.<?= $tid ?> .tm-head { text-align: center; margin-bottom: 2.5rem; }
.<?= $tid ?> .tm-head h2 { font-size: 2rem; color: #1e293b; margin: 0 0 0.4rem; font-weight: 700; }
.<?= $tid ?> .tm-head p { color: #64748b; margin: 0; }
.<?= $tid ?> .tm-grid { display: grid; grid-template-columns: repeat(<?= $cols ?>, 1fr); gap: 1.5rem; }
.<?= $tid ?> .tm-card {
    background: #fff; border-radius: 16px; overflow: hidden; box-sizing: border-box;
    box-shadow: 0 4px 14px rgba(0,0,0,0.06); border: 1px solid #f1f5f9;
    transition: transform 0.3s, box-shadow 0.3s; position: relative;
}
.<?= $tid ?> .tm-card:hover { transform: translateY(-6px); box-shadow: 0 16px 32px rgba(37,99,235,0.15); }
.<?= $tid ?> .tm-photo {
    position: relative; width: 100%; aspect-ratio: 1 / 1; overflow: hidden; background: #e2e8f0;
}
.<?= $tid ?> .tm-photo img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.4s; }
.<?= $tid ?> .tm-card:hover .tm-photo img { transform: scale(1.06); }
.<?= $tid ?> .tm-overlay {
    position: absolute; inset: 0; background: linear-gradient(transparent 40%, rgba(15,23,42,0.85));
    display: flex; align-items: flex-end; justify-content: center; padding: 1rem;
    opacity: 0; transition: opacity 0.3s;
}
.<?= $tid ?> .tm-card:hover .tm-overlay { opacity: 1; }
.<?= $tid ?> .tm-bio {
    color: #fff; font-size: 0.88rem; line-height: 1.5; text-align: center; margin: 0;
}
.<?= $tid ?> .tm-body { padding: 1.3rem 1.2rem; text-align: center; }
.<?= $tid ?> .tm-name { font-size: 1.15rem; font-weight: 700; color: #1e293b; margin: 0 0 0.25rem; }
.<?= $tid ?> .tm-role { color: #2563eb; font-size: 0.88rem; font-weight: 600; margin: 0 0 0.9rem; text-transform: uppercase; letter-spacing: 0.04em; }
.<?= $tid ?> .tm-socials { display: flex; justify-content: center; gap: 0.5rem; }
.<?= $tid ?> .tm-soc {
    width: 34px; height: 34px; border-radius: 50%; background: #f1f5f9; color: #475569;
    display: flex; align-items: center; justify-content: center; font-size: 0.85rem;
    text-decoration: none; transition: all 0.2s;
}
.<?= $tid ?> .tm-soc:hover { background: var(--soc-c, #2563eb); color: #fff; transform: translateY(-2px); }
@media (max-width: 900px) { .<?= $tid ?> .tm-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 520px) { .<?= $tid ?> .tm-grid { grid-template-columns: 1fr; } .<?= $tid ?> .tm-head h2 { font-size: 1.5rem; } }
</style>
<section class="<?= $tid ?> team-module" style="padding: 3rem 0;">
    <div class="tm-wrap">
        <div class="tm-head">
            <h2><?= htmlspecialchars($s['title']) ?></h2>
            <?php if (!empty($s['subtitle'])): ?>
                <p><?= htmlspecialchars($s['subtitle']) ?></p>
            <?php endif; ?>
        </div>
        <div class="tm-grid">
            <?php foreach ($members as $m):
                $socials = is_array($m['socials'] ?? null) ? $m['socials'] : [];
                $initials = implode('', array_map(function($w){ return strtoupper(substr($w,0,1)); }, array_slice(explode(' ', $m['name'] ?? ''), 0, 2))); ?>
                <div class="tm-card">
                    <div class="tm-photo">
                        <?php if (!empty($m['photo_url'])): ?>
                            <img src="<?= htmlspecialchars($m['photo_url']) ?>" alt="<?= htmlspecialchars($m['name'] ?? '') ?>" loading="lazy">
                        <?php else: ?>
                            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:#2563eb;color:#fff;font-size:3rem;font-weight:700;"><?= htmlspecialchars($initials) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($m['bio'])): ?>
                            <div class="tm-overlay">
                                <p class="tm-bio"><?= htmlspecialchars($m['bio']) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="tm-body">
                        <h3 class="tm-name"><?= htmlspecialchars($m['name'] ?? '') ?></h3>
                        <p class="tm-role"><?= htmlspecialchars($m['role'] ?? '') ?></p>
                        <div class="tm-socials">
                            <?php foreach ($socials as $key => $url):
                                if (empty($url)) continue;
                                $iconClass = $socialIcons[$key] ?? 'fas fa-link';
                                $color = $socialColors[$key] ?? '#2563eb';
                                $href = ($key === 'email') ? 'mailto:' . htmlspecialchars($url) : htmlspecialchars($url); ?>
                                <a class="tm-soc" style="--soc-c: <?= $color ?>;" href="<?= $href ?>" target="_blank" rel="noopener" aria-label="<?= htmlspecialchars($key) ?>">
                                    <i class="<?= $iconClass ?>"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
