<?php
/**
 * AICMS Extensions System
 * ========================
 * Allows enabling/disabling optional features: shop, comments, forum, booking.
 * Config stored in data/config.json under "extensions" key.
 *
 * Usage:
 *   require_once 'core/extensions.php';
 *   if (extension_enabled('shop')) { ... }
 *   extension_enabled('forum');
 *   extension_get_config('shop');
 *   extension_set_enabled('shop', true);
 */

/**
 * Get the extensions config array.
 * Returns: ['shop' => ['enabled' => bool, 'title' => '...'], ...]
 */
function extensions_config() {
    static $cached = null;
    if ($cached !== null) return $cached;
    $config_file = __DIR__ . '/../../data/config.json';
    if (!file_exists($config_file)) { $cached = extensions_defaults(); return $cached; }
    $config = json_decode(file_get_contents($config_file), true);
    if (!is_array($config)) { $cached = extensions_defaults(); return $cached; }
    // If extensions key doesn't exist, use defaults IN MEMORY (don't write to file)
    if (!isset($config['extensions']) || !is_array($config['extensions'])) {
        $cached = extensions_defaults();
        return $cached;
    }
    // Merge with defaults to fill any missing fields
    $defaults = extensions_defaults();
    $result = [];
    foreach ($defaults as $name => $def) {
        $result[$name] = array_merge($def, $config['extensions'][$name] ?? []);
    }
    $cached = $result;
    return $cached;
}

/**
 * Default extensions metadata.
 */
function extensions_defaults() {
    return [
        'shop'     => ['enabled' => false, 'title' => 'Магазин',     'icon' => 'fa-shopping-bag',   'desc' => 'Каталог товаров, корзина, заказы. Требует БД.'],
        'comments' => ['enabled' => false, 'title' => 'Комментарии', 'icon' => 'fa-comments',       'desc' => 'Комментарии и оценки к новостям. Требует БД.'],
        'forum'    => ['enabled' => false, 'title' => 'Форум',       'icon' => 'fa-users',          'desc' => 'Минифорум с темами и ответами. Требует БД.'],
        'booking'  => ['enabled' => false, 'title' => 'Отель',          'icon' => 'fa-hotel',          'desc' => 'Бронирование номеров: категории, фото, цены, удобства. Заявки в админку.'],
        'cafe'      => ['enabled' => false, 'title' => 'Кафе',            'icon' => 'fa-utensils',       'desc' => 'Бронирование столиков: дата, время, гости. Заявки в админку.'],
    ];
}

/**
 * Check if an extension is enabled.
 */
function extension_enabled($name) {
    $ext = extensions_config();
    $defaults = extensions_defaults();
    if (!isset($defaults[$name])) return false; // unknown extension
    if (!isset($ext[$name])) return false;
    return !empty($ext[$name]['enabled']);
}

/**
 * Get extension config (title, icon, desc, enabled).
 */
function extension_get_config($name) {
    $ext = extensions_config();
    $defaults = extensions_defaults();
    if (!isset($defaults[$name])) return null;
    return array_merge($defaults[$name], $ext[$name] ?? []);
}

/**
 * Enable/disable an extension and save to config.
 */
function extension_set_enabled($name, $enabled) {
    $defaults = extensions_defaults();
    if (!isset($defaults[$name])) return false;
    $config_file = __DIR__ . '/../../data/config.json';
    $config = json_decode(file_get_contents($config_file), true);
    if (!is_array($config)) $config = [];
    if (!isset($config['extensions']) || !is_array($config['extensions'])) {
        $config['extensions'] = $defaults;
    }
    if (!isset($config['extensions'][$name])) {
        $config['extensions'][$name] = $defaults[$name];
    }
    $config['extensions'][$name]['enabled'] = (bool)$enabled;
    $ok = file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
    // Clear static cache
    $GLOBALS['_ext_cached'] = null;
    return $ok;
}

/**
 * Get all extensions with their config (merged with defaults).
 */
function extensions_all() {
    $ext = extensions_config();
    $defaults = extensions_defaults();
    $result = [];
    foreach ($defaults as $name => $def) {
        $result[$name] = array_merge($def, $ext[$name] ?? []);
    }
    return $result;
}
