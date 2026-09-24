<?php
/**
 * RSS Feed Generator
 * URL: /feed.xml (via .htaccess) or /feed.php
 */
require_once __DIR__ . '/admin/core/functions.php';
require_once __DIR__ . '/admin/core/extensions.php';

$config = read_json(__DIR__ . '/data/config.json', []);
$site_url = rtrim($config['site_url'] ?? ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), '/');
$lang = $_GET['lang'] ?? ($config['default_lang'] ?? 'ru');
$news = read_content('news.json', [], $lang);
$news = array_values(array_filter($news, function($n) { return !isset($n['published']) || $n['published']; }));
usort($news, function($a, $b) { return strtotime($b['date']) - strtotime($a['date']); });
$news = array_slice($news, 0, 20);

header('Content-Type: application/rss+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
<title><?= htmlspecialchars($config['site_title'] ?? 'AICMS') ?></title>
<link><?= htmlspecialchars($site_url) ?></link>
<description><?= htmlspecialchars($config['site_description'] ?? '') ?></description>
<language><?= htmlspecialchars($lang) ?></language>
<lastBuildDate><?= date('r') ?></lastBuildDate>
<atom:link href="<?= htmlspecialchars($site_url) ?>/feed.xml" rel="self" type="application/rss+xml"/>
<?php foreach ($news as $n): ?>
<item>
<title><?= htmlspecialchars($n['title'] ?? '') ?></title>
<link><?= htmlspecialchars($site_url) ?>/?page=news#news-<?= (int)$n['id'] ?></link>
<description><![CDATA[<?= mb_substr(strip_tags($n['content'] ?? ''), 0, 300) ?>]]></description>
<pubDate><?= date('r', strtotime($n['date'] ?? 'now')) ?></pubDate>
<guid><?= htmlspecialchars($site_url) ?>/?page=news#news-<?= (int)$n['id'] ?></guid>
<?php if (!empty($n['category'])): ?><category><?= htmlspecialchars($n['category']) ?></category><?php endif; ?>
</item>
<?php endforeach; ?>
</channel>
</rss>
