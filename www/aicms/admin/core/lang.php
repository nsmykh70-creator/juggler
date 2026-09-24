<?php
/**
 * AICMS Admin Localization System
 * =================================
 *
 * Loads interface translations from per-language PHP files in admin/locales/.
 * Each file (e.g. ru.php, en.php) returns an associative array of strings.
 *
 * Features:
 *   - Automatic fallback to DEFAULT_LANG when a key is missing in the
 *     current language (so partial translations still work).
 *   - Falls back to the key itself (with underscores → spaces) if the key
 *     is not found in any language.
 *   - Caches loaded language files in a static variable to avoid re-parsing.
 *   - Auto-discovery of available locale files (any *.php in admin/locales/).
 *
 * To add a new language:
 *   1. Copy ru.php → <code>.php (e.g. fr.php) in admin/locales/.
 *   2. Translate the values.
 *   3. Register the language in data/config.json → "languages" array.
 *
 * @package AICMS\Locales
 */

/**
 * Load a language file from admin/locales/<code>.php.
 * Returns the translation array, or an empty array if the file is missing.
 */
function load_locale_file($lang_code) {
    static $cache = [];
    if (isset($cache[$lang_code])) {
        return $cache[$lang_code];
    }
    $path = __DIR__ . '/../locales/' . $lang_code . '.php';
    if (file_exists($path)) {
        $translations = include $path;
        if (!is_array($translations)) {
            $translations = [];
        }
    } else {
        $translations = [];
    }
    $cache[$lang_code] = $translations;
    return $translations;
}

/**
 * Build the final translation table for a given language.
 *
 * The result starts with the DEFAULT_LANG translations (so every key has a
 * value), then overlays the requested language's translations on top. This
 * means a partial translation file will fall back to the default language
 * for any missing keys.
 *
 * @param string $lang_code Target language code (e.g. 'en', 'de').
 * @return array Associative array of key => translated string.
 */
function get_translations($lang_code) {
    $default = load_locale_file(defined('DEFAULT_LANG') ? DEFAULT_LANG : 'ru');
    $target  = load_locale_file($lang_code);

    // Start from default, overlay target translations
    $merged = array_merge($default, $target);

    // Remove the __meta key from the default if the target provides its own
    // (array_merge already handles this correctly, but be explicit).
    if (isset($target['__meta'])) {
        $merged['__meta'] = $target['__meta'];
    }

    return $merged;
}

/**
 * Get the list of all available locale files on disk.
 * Returns an associative array: [code => meta_data] where meta_data is the
 * '__meta' entry from the locale file (code, name, flag).
 */
function get_available_locales() {
    $locales_dir = __DIR__ . '/../locales/';
    $result = [];
    if (!is_dir($locales_dir)) return $result;

    $files = glob($locales_dir . '*.php');
    foreach ($files as $file) {
        $code = basename($file, '.php');
        $translations = include $file;
        if (is_array($translations) && isset($translations['__meta'])) {
            $meta = $translations['__meta'];
            $result[$code] = [
                'code'       => $meta['code'] ?? $code,
                'name'       => $meta['name'] ?? ucfirst($code),
                'flag'       => $meta['flag'] ?? '',
                'key_count'  => count($translations) - 1, // subtract __meta
            ];
        } else {
            // Legacy / minimal file without __meta
            $result[$code] = [
                'code'       => $code,
                'name'       => ucfirst($code),
                'flag'       => '',
                'key_count'  => count($translations),
            ];
        }
    }
    // Sort alphabetically by code
    ksort($result);
    return $result;
}

/**
 * Calculate translation coverage for a language relative to the default.
 * Returns a percentage (0-100) of keys from the default language that are
 * present (and different from the default) in the target language.
 */
function get_translation_coverage($lang_code) {
    if (!defined('DEFAULT_LANG')) return 100;
    if ($lang_code === DEFAULT_LANG) return 100;

    $default_translations = load_locale_file(DEFAULT_LANG);
    $target_translations  = load_locale_file($lang_code);

    $default_keys = array_diff(array_keys($default_translations), ['__meta']);
    $total = count($default_keys);
    if ($total === 0) return 0;

    $translated = 0;
    foreach ($default_keys as $key) {
        if (isset($target_translations[$key]) && $target_translations[$key] !== $default_translations[$key]) {
            $translated++;
        }
    }
    return (int)round(($translated / $total) * 100);
}

/**
 * Global translation function.
 *
 * Looks up a key in the current admin language, falling back to the default
 * language, then to a humanised version of the key itself.
 *
 * @param string $key Translation key.
 * @return string Translated string.
 */
function t($key) {
    global $T;
    if (isset($T[$key])) {
        return $T[$key];
    }
    // Fallback: humanise the key (e.g. "manage_pages" → "Manage pages")
    return ucfirst(str_replace('_', ' ', $key));
}

/**
 * Build a JSON dictionary of strings for use in JavaScript.
 *
 * Returns a JSON string that should be echoed inside a <script> tag as
 * `window.I18N = <?= js_i18n() ?>;`. JavaScript code can then use
 * `I18N.key` instead of hardcoded strings.
 *
 * The keys returned are filtered to those actually used by JS files
 * (filemanager.js, media-library.js, aicms-ui.js, lumina editor).
 * This keeps the payload small (~1-2 KB).
 *
 * @return string JSON-encoded associative array of key => translated string.
 */
function js_i18n() {
    global $T;
    // Whitelist of keys used by JavaScript files
    $js_keys = [
        // Common UI
        'save', 'cancel', 'delete', 'edit', 'close', 'confirm_btn',
        'rename', 'copy', 'move', 'extract', 'upload', 'download',
        'new', 'folder', 'select_all', 'clear_selection', 'two_panels',
        'editor', 'search_placeholder', 'filemanager_root', 'logout_btn',
        'selected_count',
        // File manager
        'fm_select_item', 'fm_new_folder_ph', 'fm_new_file_ph',
        'fm_new_name', 'fm_delete_confirm', 'fm_archive_name',
        'fm_drop_files', 'fm_empty', 'fm_folder', 'fm_root',
        'fm_delete_bulk', 'fm_delete_bulk_confirm', 'fm_target_same',
        'fm_move_files', 'fm_copy_files', 'fm_modal_unavailable',
        'fm_error', 'fm_loading', 'fm_move', 'fm_copy',
        'fm_cannot_delete_critical', 'fm_no_files_selected',
        // Media library
        'ml_uploaded', 'ml_upload_error', 'ml_file_deleted', 'ml_url_copied',
        'ml_urls_copied', 'ml_deleted_count', 'ml_enter_url', 'ml_invalid_url',
        'ml_http_only', 'ml_download_failed', 'ml_too_large',
        'ml_loaded', 'ml_search_name',
        // Auto-save
        'autosave_saved', 'autosave_restored', 'autosave_discard',
        // Lumina editor
        'lumina_writing_news', 'lumina_writing',
    ];
    $dict = [];
    foreach ($js_keys as $k) {
        if (isset($T[$k])) $dict[$k] = $T[$k];
    }
    return json_encode($dict, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Load translations for the current admin language once.
// The merged table guarantees every key from the default language is present.
$T = get_translations(defined('ADMIN_LANG') ? ADMIN_LANG : 'ru');
