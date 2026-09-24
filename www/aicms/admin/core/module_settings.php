<?php
/**
 * Module Settings Helper
 * =======================
 * Provides a simple API for modules to read/write their own settings
 * stored in JSON files under data/module_settings/<module_name>.json.
 *
 * Usage in a module file:
 *   $settings = module_get_settings('contact_form');
 *   $email = $settings['email'] ?? 'default@example.com';
 *
 * To save settings (from an admin page or POST handler):
 *   module_save_settings('contact_form', ['email' => 'new@example.com']);
 */

if (!function_exists('module_get_settings')) {
    function module_get_settings($module_name, $defaults = []) {
        $path = __DIR__ . '/../data/module_settings/' . $module_name . '.json';
        $settings = $defaults;
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            if (is_array($data)) {
                $settings = array_merge($defaults, $data);
            }
        }
        // Apply inline parameters from shortcode (e.g. {{module:name|email=x@y.com}})
        // These override both defaults and saved settings.
        if (isset($GLOBALS['module_inline_params']) && is_array($GLOBALS['module_inline_params'])) {
            $settings = array_merge($settings, $GLOBALS['module_inline_params']);
        }
        return $settings;
    }
}

if (!function_exists('module_save_settings')) {
    function module_save_settings($module_name, $settings) {
        $dir = __DIR__ . '/../data/module_settings/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $path = $dir . $module_name . '.json';
        file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return true;
    }
}

/**
 * Localized module settings.
 * =================================
 * Modules can store per-language variants of their settings by calling
 * module_save_settings_localized('faq', 'ru', [...]) and reading them
 * via module_get_settings_localized('faq', 'ru', $defaults).
 *
 * Storage:
 *   data/module_settings/<module_name>.json  → default (lang-agnostic) settings
 *   data/module_settings/<module_name>.<lang>.json → language-specific overrides
 *
 * When reading, language-specific values take precedence over the default,
 * which falls back to $defaults. This way modules can ship a single set of
 * defaults and let admins override individual fields per language.
 */

if (!function_exists('module_get_settings_localized')) {
    function module_get_settings_localized($module_name, $lang = null, $defaults = []) {
        if ($lang === null) $lang = $GLOBALS['current_lang'] ?? 'ru';
        // 1) Start with defaults
        $settings = $defaults;
        // 2) Merge with lang-agnostic base settings
        $base_path = __DIR__ . '/../data/module_settings/' . $module_name . '.json';
        if (file_exists($base_path)) {
            $data = json_decode(@file_get_contents($base_path), true);
            if (is_array($data)) $settings = array_merge($settings, $data);
        }
        // 3) Merge with language-specific overrides (highest priority)
        $lang_path = __DIR__ . '/../data/module_settings/' . $module_name . '.' . $lang . '.json';
        if (file_exists($lang_path)) {
            $data = json_decode(@file_get_contents($lang_path), true);
            if (is_array($data)) $settings = array_merge($settings, $data);
        }
        // 4) Inline shortcode params override everything
        if (isset($GLOBALS['module_inline_params']) && is_array($GLOBALS['module_inline_params'])) {
            $settings = array_merge($settings, $GLOBALS['module_inline_params']);
        }
        return $settings;
    }
}

if (!function_exists('module_save_settings_localized')) {
    function module_save_settings_localized($module_name, $lang, $settings) {
        $dir = __DIR__ . '/../data/module_settings/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $path = $dir . $module_name . '.' . $lang . '.json';
        file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return true;
    }
}

/**
 * Get the list of languages that have overrides for a given module.
 * Returns array of lang codes (e.g. ['ru', 'en']).
 */
if (!function_exists('module_get_localized_langs')) {
    function module_get_localized_langs($module_name) {
        $dir = __DIR__ . '/../data/module_settings/';
        $langs = [];
        foreach (glob($dir . $module_name . '.*.json') as $file) {
            $basename = basename($file, '.json');
            $parts = explode('.', $basename);
            if (count($parts) >= 2) $langs[] = end($parts);
        }
        return $langs;
    }
}

/**
 * Convenience: get a localized string field with fallback.
 * Usage: $title = module_localized_field('faq', 'title', $lang, 'Default title');
 */
if (!function_exists('module_localized_field')) {
    function module_localized_field($module_name, $field_name, $lang = null, $default = '') {
        $settings = module_get_settings_localized($module_name, $lang);
        return $settings[$field_name] ?? $default;
    }
}

if (!function_exists('module_setting_field')) {
    /**
     * Render a single setting field HTML for admin forms.
     * Helper for modules that want to render their own settings UI.
     */
    function module_setting_field($name, $label, $value, $type = 'text', $options = []) {
        $html = '<div class="mb-3">';
        $html .= '<label class="form-label">' . htmlspecialchars($label) . '</label>';
        $val = htmlspecialchars($value);
        switch ($type) {
            case 'textarea':
                $html .= '<textarea name="' . htmlspecialchars($name) . '" class="form-control" rows="4">' . $val . '</textarea>';
                break;
            case 'select':
                $html .= '<select name="' . htmlspecialchars($name) . '" class="form-select">';
                foreach ($options as $opt_val => $opt_label) {
                    $sel = ($opt_val == $value) ? ' selected' : '';
                    $html .= '<option value="' . htmlspecialchars($opt_val) . '"' . $sel . '>' . htmlspecialchars($opt_label) . '</option>';
                }
                $html .= '</select>';
                break;
            case 'checkbox':
                $sel = ($value) ? ' checked' : '';
                $html = '<div class="form-check mb-3">';
                $html .= '<input type="checkbox" name="' . htmlspecialchars($name) . '" class="form-check-input" id="' . htmlspecialchars($name) . '"' . $sel . '>';
                $html .= '<label class="form-check-label" for="' . htmlspecialchars($name) . '">' . htmlspecialchars($label) . '</label>';
                break;
            default:
                $html .= '<input type="' . htmlspecialchars($type) . '" name="' . htmlspecialchars($name) . '" class="form-control" value="' . $val . '">';
        }
        $html .= '</div>';
        return $html;
    }
}
