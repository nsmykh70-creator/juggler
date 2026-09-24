<?php
/**
 * robots.txt Generator
 * URL: /robots.txt (via rewrite) or /robots.php
 */
require_once __DIR__ . '/admin/core/functions.php';

$config = read_json(__DIR__ . '/data/config.json', []);
$site_url = rtrim($config['site_url'] ?? ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), '/');

header('Content-Type: text/plain; charset=utf-8');

echo "User-agent: *\n";
echo "Allow: /\n";
echo "Disallow: /admin/\n";
echo "Disallow: /data/\n";
echo "Disallow: /backup/\n";
echo "Disallow: /logs/\n";
echo "Disallow: /modules/\n";
echo "Disallow: /widgets/\n";
echo "Disallow: /assets/js/\n";
echo "Disallow: /*.json$\n";
echo "Disallow: /*.php$\n";
echo "Allow: /sitemap.xml\n";
echo "Allow: /index.php\n";
echo "\n";
echo "Sitemap: " . $site_url . "/sitemap.xml\n";
