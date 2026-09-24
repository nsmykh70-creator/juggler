<?php
/**
 * WebP Image Converter
 * Converts uploaded JPEG/PNG to WebP format for better performance.
 * Requires PHP-GD extension.
 * 
 * Usage:
 *   require_once 'core/webp.php';
 *   $webp_path = webp_convert('/path/to/image.jpg');
 */

function webp_convert($source_path, $quality = 80) {
    // Check if GD supports WebP
    if (!function_exists('imagewebp') || !function_exists('gd_info')) return null;
    $gd_info = gd_info();
    if (empty($gd_info['WebP Support'])) return null;

    if (!file_exists($source_path)) return null;
    $ext = strtolower(pathinfo($source_path, PATHINFO_EXTENSION));
    
    // Create image resource based on type
    $image = null;
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $image = @imagecreatefromjpeg($source_path);
            break;
        case 'png':
            $image = @imagecreatefrompng($source_path);
            if ($image) {
                imagepalettetotruecolor($image);
                imagealphablending($image, true);
                imagesavealpha($image, true);
            }
            break;
        case 'gif':
            $image = @imagecreatefromgif($source_path);
            break;
        default:
            return null; // Already WebP or unsupported
    }
    if (!$image) return null;
    
    // Generate WebP path
    $webp_path = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $source_path);
    
    // Convert and save
    $success = imagewebp($image, $webp_path, $quality);
    imagedestroy($image);
    
    if ($success && file_exists($webp_path)) {
        // Only return webp if it's smaller than original
        if (filesize($webp_path) < filesize($source_path)) {
            return $webp_path;
        }
        // Otherwise delete webp, keep original
        @unlink($webp_path);
        return null;
    }
    return null;
}

function webp_convert_uploaded($filepath) {
    $config_file = __DIR__ . '/../../data/config.json';
    if (!file_exists($config_file)) return $filepath;
    $config = json_decode(file_get_contents($config_file), true);
    if (empty($config['features']['webp_conversion'])) return $filepath;
    
    $webp = webp_convert($filepath);
    return $webp ?: $filepath;
}
