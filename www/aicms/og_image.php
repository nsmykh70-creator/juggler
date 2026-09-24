<?php
/**
 * OG Image Generator
 * Generates Open Graph images on the fly from page title.
 * URL: /og_image.php?title=...&subtitle=...
 * Uses GD to create a 1200x630 image with gradient background + text.
 */
require_once __DIR__ . '/admin/core/functions.php';

$config = read_json(__DIR__ . '/data/config.json', []);
$title = $_GET['title'] ?? ($config['site_title'] ?? 'AICMS');
$subtitle = $_GET['subtitle'] ?? ($config['site_description'] ?? '');

// Truncate title
if (mb_strlen($title) > 60) $title = mb_substr($title, 0, 57) . '...';
if (mb_strlen($subtitle) > 80) $subtitle = mb_substr($subtitle, 0, 77) . '...';

$width = 1200;
$height = 630;

// Check GD
if (!function_exists('imagecreate')) {
    // Fallback: redirect to a placeholder
    header('Location: https://via.placeholder.com/1200x630/1e293b/ffffff?text=' . urlencode($title));
    exit;
}

$image = imagecreatetruecolor($width, $height);

// Gradient background (dark blue to purple)
$color1 = imagecolorallocate($image, 30, 41, 59);    // #1e293b
$color2 = imagecolorallocate($image, 88, 28, 135);   // #581c87
for ($y = 0; $y < $height; $y++) {
    $r = (int)($color1 >> 16 & 0xFF) + ((($color2 >> 16 & 0xFF) - ($color1 >> 16 & 0xFF)) * $y / $height);
    $g = (int)($color1 >> 8 & 0xFF) + ((($color2 >> 8 & 0xFF) - ($color1 >> 8 & 0xFF)) * $y / $height);
    $b = (int)($color1 & 0xFF) + ((($color2 & 0xFF) - ($color1 & 0xFF)) * $y / $height);
    $line = imagecolorallocate($image, $r, $g, $b);
    imageline($image, 0, $y, $width, $y, $line);
}

// Accent bar at top
$accent = imagecolorallocate($image, 37, 99, 235);
imagefilledrectangle($image, 0, 0, $width, 8, $accent);

// White text
$white = imagecolorallocate($image, 255, 255, 255);
$gray = imagecolorallocate($image, 148, 163, 184);

// Try to use a TrueType font, fall back to built-in
$font_path = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
$font_regular = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

// Title text
$font_size_title = 42;
$font_size_sub = 24;
$y_start = 280;

if (file_exists($font_path) && function_exists('imagettftext')) {
    // Wrap title text
    $title_words = explode(' ', $title);
    $lines = [];
    $current = '';
    foreach ($title_words as $word) {
        $test = $current ? $current . ' ' . $word : $word;
        $box = imagettfbbox($font_size_title, 0, $font_path, $test);
        if ($box[2] > $width - 120 && $current) {
            $lines[] = $current;
            $current = $word;
        } else {
            $current = $test;
        }
    }
    if ($current) $lines[] = $current;
    $lines = array_slice($lines, 0, 3); // max 3 lines

    $y = $y_start;
    foreach ($lines as $line) {
        imagettftext($image, $font_size_title, 0, 60, $y, $white, $font_path, $line);
        $y += 55;
    }
    // Subtitle
    if ($subtitle) {
        imagettftext($image, $font_size_sub, 0, 60, $y + 20, $gray, $font_regular, $subtitle);
    }
} else {
    // Built-in font fallback
    imagestring($image, 5, 60, 270, $title, $white);
    if ($subtitle) imagestring($image, 3, 60, 320, $subtitle, $gray);
}

// Site name in corner
$site_name = $config['site_title'] ?? 'AICMS';
if (file_exists($font_regular) && function_exists('imagettftext')) {
    imagettftext($image, 18, 0, 60, 570, $gray, $font_regular, $site_name);
} else {
    imagestring($image, 3, 60, 570, $site_name, $gray);
}

// Output
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400'); // cache 24h
imagepng($image);
imagedestroy($image);
