<?php
/**
 * AICMS Plugin System (Hooks)
 * ===========================
 * Allows extending the CMS without modifying core files.
 * 
 * Available hooks:
 *   before_save_page    — called before a page is saved
 *   after_save_page     — called after a page is saved
 *   before_save_news    — called before news is saved
 *   after_save_news     — called after news is saved
 *   before_render       — called before page HTML is rendered
 *   after_render        — called after page HTML is rendered
 *   before_upload       — called before a file is uploaded
 *   after_upload        — called after a file is uploaded
 *   before_delete       — called before a page/news is deleted
 * 
 * Usage in a plugin file (e.g. data/plugins/my_plugin.php):
 *   <?php
 *   register_hook('after_save_page', function($page, $lang) {
 *       // Do something with the saved page
 *       error_log('Page saved: ' . $page['title']);
 *   });
 *   
 * To enable plugins, add to data/config.json:
 *   "plugins": ["my_plugin"]
 * 
 * Plugin files are loaded from data/plugins/<name>.php
 */

if (!function_exists('register_hook')) {
    $GLOBALS['_aicms_hooks'] = [];
    
    function register_hook($hook_name, $callback, $priority = 10) {
        if (!isset($GLOBALS['_aicms_hooks'][$hook_name])) {
            $GLOBALS['_aicms_hooks'][$hook_name] = [];
        }
        $GLOBALS['_aicms_hooks'][$hook_name][] = [
            'callback' => $callback,
            'priority' => $priority,
        ];
        // Sort by priority
        usort($GLOBALS['_aicms_hooks'][$hook_name], function($a, $b) {
            return $a['priority'] - $b['priority'];
        });
    }
}

if (!function_exists('do_hook')) {
    function do_hook($hook_name, &...$args) {
        if (!isset($GLOBALS['_aicms_hooks'][$hook_name])) {
            return;
        }
        foreach ($GLOBALS['_aicms_hooks'][$hook_name] as $hook) {
            try {
                call_user_func_array($hook['callback'], $args);
            } catch (Exception $e) {
                error_log("AICMS Hook error in {$hook_name}: " . $e->getMessage());
            }
        }
    }
}

if (!function_exists('load_plugins')) {
    function load_plugins() {
        $config_file = __DIR__ . '/../../data/config.json';
        if (!file_exists($config_file)) return;
        $config = json_decode(@file_get_contents($config_file), true) ?: [];
        $plugins = $config['plugins'] ?? [];
        if (empty($plugins)) return;
        
        $plugins_dir = __DIR__ . '/../../data/plugins/';
        if (!is_dir($plugins_dir)) {
            @mkdir($plugins_dir, 0755, true);
            return;
        }
        
        foreach ($plugins as $plugin_name) {
            // Validate plugin name (alphanumeric + underscore only)
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $plugin_name)) continue;
            
            $plugin_file = $plugins_dir . $plugin_name . '.php';
            if (file_exists($plugin_file)) {
                try {
                    include_once $plugin_file;
                } catch (Exception $e) {
                    error_log("AICMS Plugin error ({$plugin_name}): " . $e->getMessage());
                }
            }
        }
    }
}

// Load plugins on startup
load_plugins();
