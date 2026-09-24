<?php
/**
 * Multi-site Manager
 * ==================
 * Allows one AICMS installation to manage multiple sites (different domains,
 * different content, different themes, but shared admin + users + modules).
 *
 * Storage:
 *   data/sites.json              → list of configured sites
 *   data/sites/<site_id>/        → per-site content (pages.json, news.json, etc.)
 *   data/sites/<site_id>/config.json → per-site config overrides
 *
 * Site detection:
 *   1. ?site=<site_id> query param (admin override)
 *   2. Match $_SERVER['HTTP_HOST'] against site.host
 *   3. Fall back to 'default' site
 *
 * Usage:
 *   $site = current_site();         // returns ['id' => 'default', 'host' => '...', ...]
 *   $site_id = $site['id'];
 *   $config = load_site_config();   // merges global + site-specific config
 */

if (!defined('SITES_FILE')) define('SITES_FILE', __DIR__ . '/../../data/sites.json');

/**
 * Get the list of all configured sites.
 * Each site: { id, name, host, theme, default_lang, enabled, created }
 */
function get_sites() {
    if (!file_exists(SITES_FILE)) {
        // Auto-create default site on first run
        // Use theme from config.json if available
        $_cfg = json_decode(@file_get_contents(__DIR__ . '/../../data/config.json'), true);
        $_default_theme = is_array($_cfg) ? ($_cfg['theme'] ?? 'helios') : 'helios';
        $default = [
            [
                'id' => 'default',
                'name' => 'Default site',
                'host' => $_SERVER['HTTP_HOST'] ?? 'localhost',
                'theme' => $_default_theme,
                'default_lang' => 'ru',
                'enabled' => true,
                'created' => date('Y-m-d H:i:s'),
            ]
        ];
        file_put_contents(SITES_FILE, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $default;
    }
    $data = json_decode(@file_get_contents(SITES_FILE), true);
    return is_array($data) ? $data : [];
}

/**
 * Save the sites list.
 */
function save_sites($sites) {
    file_put_contents(SITES_FILE, json_encode($sites, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Get a single site by ID.
 */
function get_site($site_id) {
    foreach (get_sites() as $s) {
        if ($s['id'] === $site_id) return $s;
    }
    return null;
}

/**
 * Detect the current site based on hostname or ?site= param.
 * Returns the site array, or null if no sites configured.
 */
function current_site() {
    static $cached = null;
    if ($cached !== null) return $cached;

    $sites = get_sites();
    if (empty($sites)) return null;

    // 1. ?site= override (admin)
    if (!empty($_GET['site'])) {
        $sid = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['site']);
        foreach ($sites as $s) {
            if ($s['id'] === $sid && !empty($s['enabled'])) {
                $cached = $s;
                return $s;
            }
        }
    }
    // 2. Session-stored selection (admin switching sites)
    if (!empty($_SESSION['aicms_current_site'])) {
        foreach ($sites as $s) {
            if ($s['id'] === $_SESSION['aicms_current_site'] && !empty($s['enabled'])) {
                $cached = $s;
                return $s;
            }
        }
    }
    // 3. Match by HTTP_HOST
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Strip port
    $host = preg_replace('/:\d+$/', '', $host);
    foreach ($sites as $s) {
        if (!empty($s['host']) && $s['host'] === $host && !empty($s['enabled'])) {
            $cached = $s;
            return $s;
        }
    }
    // 4. Wildcard / substring match (e.g. site1.example.com matches *.example.com)
    foreach ($sites as $s) {
        if (!empty($s['host']) && strpos($s['host'], '*') !== false) {
            $pattern = str_replace('\*', '.*', preg_quote($s['host'], '/'));
            if (preg_match('/^' . $pattern . '$/i', $host) && !empty($s['enabled'])) {
                $cached = $s;
                return $s;
            }
        }
    }
    // 5. Fall back to default site (first enabled)
    foreach ($sites as $s) {
        if (!empty($s['enabled']) && $s['id'] === 'default') {
            $cached = $s;
            return $s;
        }
    }
    foreach ($sites as $s) {
        if (!empty($s['enabled'])) {
            $cached = $s;
            return $s;
        }
    }
    return null;
}

/**
 * Get the current site ID (or 'default' if no sites configured).
 */
function current_site_id() {
    $s = current_site();
    return $s ? $s['id'] : 'default';
}

/**
 * Set the current site (for admin switching). Stored in session.
 */
function set_current_site($site_id) {
    foreach (get_sites() as $s) {
        if ($s['id'] === $site_id) {
            $_SESSION['aicms_current_site'] = $site_id;
            return true;
        }
    }
    return false;
}

/**
 * Get the path to per-site content.
 * Returns absolute path like: /var/www/aicms/data/sites/default/ru/pages.json
 */
function site_content_path($filename, $lang) {
    $site_id = current_site_id();
    $base = __DIR__ . '/../../data/sites/' . $site_id . '/content/' . $lang . '/' . $filename;
    return $base;
}

/**
 * Load the merged config (global config.json + per-site config.json overrides).
 */
function load_site_config() {
    static $cached = null;
    if ($cached !== null) return $cached;

    $global = read_json(__DIR__ . '/../../data/config.json', []);
    $site_id = current_site_id();
    $site_config_path = __DIR__ . '/../../data/sites/' . $site_id . '/config.json';
    if (file_exists($site_config_path)) {
        $site_cfg = json_decode(@file_get_contents($site_config_path), true);
        if (is_array($site_cfg)) {
            $global = array_merge($global, $site_cfg);
        }
    }
    // Inject site-specific values from sites.json
    $site = get_site($site_id);
    if ($site) {
        $global['site_id'] = $site['id'];
        $global['site_name'] = $site['name'] ?? $global['site_title'] ?? 'AICMS';
        if (!empty($site['theme'])) $global['theme'] = $site['theme'];
        if (!empty($site['default_lang'])) $global['default_lang'] = $site['default_lang'];
    }
    $cached = $global;
    return $global;
}

/**
 * Create a new site.
 * @param array $data  Site config: id, name, host, theme, default_lang
 * @return bool|string  true on success, error message on failure
 */
function create_site($data) {
    $sites = get_sites();
    // Validate ID
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $data['id'] ?? '');
    if (empty($id) || strlen($id) < 2) return 'Site ID must be 2+ chars (a-z, 0-9, -, _)';
    if (strlen($id) > 32) return 'Site ID too long (max 32 chars)';
    foreach ($sites as $s) {
        if ($s['id'] === $id) return 'Site ID already exists';
    }
    // Validate host uniqueness (if any)
    $host = trim($data['host'] ?? '');
    if (!empty($host)) {
        foreach ($sites as $s) {
            if (!empty($s['host']) && $s['host'] === $host) return 'Host already used by site "' . $s['id'] . '"';
        }
    }
    $new_site = [
        'id' => $id,
        'name' => $data['name'] ?? $id,
        'host' => $host,
        'theme' => $data['theme'] ?? 'aurora',
        'default_lang' => $data['default_lang'] ?? 'ru',
        'enabled' => true,
        'created' => date('Y-m-d H:i:s'),
    ];
    $sites[] = $new_site;
    save_sites($sites);
    // Create per-site content directory
    $dir = __DIR__ . '/../../data/sites/' . $id . '/content';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    // Create empty content files for each supported language
    $languages = $data['languages'] ?? [['code' => 'ru']];
    foreach ($languages as $li) {
        $lang_code = $li['code'] ?? 'ru';
        $lang_dir = $dir . '/' . $lang_code;
        if (!is_dir($lang_dir)) mkdir($lang_dir, 0755, true);
        foreach (['pages.json', 'news.json'] as $f) {
            $path = $lang_dir . '/' . $f;
            if (!file_exists($path)) file_put_contents($path, '[]');
        }
    }
    // Create per-site config.json (override)
    $site_config = [
        'site_title' => $new_site['name'],
        'site_description' => '',
        'theme' => $new_site['theme'],
        'default_lang' => $new_site['default_lang'],
    ];
    file_put_contents(__DIR__ . '/../../data/sites/' . $id . '/config.json',
        json_encode($site_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return true;
}

/**
 * Update an existing site.
 */
function update_site($site_id, $data) {
    $sites = get_sites();
    foreach ($sites as &$s) {
        if ($s['id'] === $site_id) {
            if (isset($data['name'])) $s['name'] = $data['name'];
            if (isset($data['host'])) $s['host'] = trim($data['host']);
            if (isset($data['theme'])) $s['theme'] = $data['theme'];
            if (isset($data['default_lang'])) $s['default_lang'] = $data['default_lang'];
            if (isset($data['enabled'])) $s['enabled'] = (bool)$data['enabled'];
            save_sites($sites);
            return true;
        }
    }
    return false;
}

/**
 * Delete a site (and its content directory).
 * Cannot delete the 'default' site.
 */
function delete_site($site_id) {
    if ($site_id === 'default') return false;
    $sites = get_sites();
    $new = [];
    $found = false;
    foreach ($sites as $s) {
        if ($s['id'] === $site_id) {
            $found = true;
            continue;
        }
        $new[] = $s;
    }
    if (!$found) return false;
    save_sites($new);
    // Remove the content directory (move to .trash to be safe)
    $dir = __DIR__ . '/../../data/sites/' . $site_id;
    if (is_dir($dir)) {
        $trash = __DIR__ . '/../../data/sites/.trash';
        if (!is_dir($trash)) mkdir($trash, 0755, true);
        @rename($dir, $trash . '/' . $site_id . '_' . date('Ymd_His'));
    }
    // Clear session if it was pointing to the deleted site
    if (($_SESSION['aicms_current_site'] ?? '') === $site_id) {
        unset($_SESSION['aicms_current_site']);
    }
    return true;
}

/**
 * Check if multi-site feature is enabled (more than 1 site configured).
 */
function is_multisite() {
    return count(get_sites()) > 1;
}
