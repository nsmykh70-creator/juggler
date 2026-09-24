<?php
/**
 * Shop image upload helper
 * Handles file uploads to uploads/shop/ directory
 */

function shop_upload_image($file_input_name = 'image_file') {
    if (empty($_FILES[$file_input_name]) || $_FILES[$file_input_name]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $file = $_FILES[$file_input_name];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) {
        return null;
    }
    $upload_dir = __DIR__ . '/../../uploads/shop/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    $filename = 'product_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 6) . '.' . $ext;
    $destination = $upload_dir . $filename;
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        // Try WebP conversion
        require_once __DIR__ . '/webp.php';
        $webp_path = webp_convert($destination);
        if ($webp_path) {
            @unlink($destination); // remove original, keep webp
            return 'uploads/shop/' . basename($webp_path);
        }
        return 'uploads/shop/' . $filename;
    }
    return null;
}

function shop_upload_gallery($input_name = 'gallery_files') {
    $results = [];
    if (empty($_FILES[$input_name]) || empty($_FILES[$input_name]['name'][0])) return $results;
    $upload_dir = __DIR__ . '/../../uploads/shop/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    require_once __DIR__ . '/webp.php';
    $count = count($_FILES[$input_name]['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($_FILES[$input_name]['error'][$i] !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo($_FILES[$input_name]['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) continue;
        $filename = 'gallery_' . date('Ymd_His') . '_' . $i . '_' . substr(md5(uniqid()), 0, 6) . '.' . $ext;
        $dest = $upload_dir . $filename;
        if (move_uploaded_file($_FILES[$input_name]['tmp_name'][$i], $dest)) {
            $webp = webp_convert($dest);
            if ($webp) { @unlink($dest); $results[] = 'uploads/shop/' . basename($webp); }
            else { $results[] = 'uploads/shop/' . $filename; }
        }
    }
    return $results;
}
