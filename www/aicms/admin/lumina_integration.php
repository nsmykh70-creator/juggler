<?php
/**
 * Lumina Editor integration helper for AICMS
 * Include this file BEFORE the editor <script type="module"> block.
 * Sets up CSRF token and editor config via regular (synchronous) inline script.
 */
if (!isset($lumina_integration_loaded)) {
    $lumina_integration_loaded = true;

    $csrf_token = csrf_field_value();
    $upload_url = 'lumina_upload_image.php';
    $ai_url = 'api_generate_content.php';

    // Get current active theme from config.json
    $site_config = read_json(__DIR__ . '/../data/config.json', []);
    $current_theme = $site_config['theme'] ?? 'helios';

    // Load Lumina module settings from JSON config if available
    $lumina_config_file = __DIR__ . '/../data/lumina_settings.json';
    $lumina_config = [];
    if (file_exists($lumina_config_file)) {
        $lumina_config = json_decode(file_get_contents($lumina_config_file), true) ?: [];
    }
    $modules_json = !empty($lumina_config['modules'])
        ? json_encode($lumina_config['modules'])
        : '{}';
    $buttons_json = !empty($lumina_config['buttons'])
        ? json_encode($lumina_config['buttons'])
        : '{}';
    $bubble_menus_json = !empty($lumina_config['bubbleMenus'])
        ? json_encode($lumina_config['bubbleMenus'])
        : '{}';
    $sticky = !empty($lumina_config['stickyToolbar']) ? 'true' : 'false';

    // Cache-buster version string. Bump this when Lumina JS/CSS files change
    // so browsers reload the new versions instead of serving cached ones.
    $lumina_version = '20260814';
?>
<script>
// Lumina Editor — synchronous config (must run BEFORE module scripts)
window._luminaCsrfToken = <?= json_encode($csrf_token) ?>;
window._luminaEditorConfig = {
    csrfToken: <?= json_encode($csrf_token) ?>,
    uploadUrl: <?= json_encode($upload_url) ?>,
    cmsAiUrl: <?= json_encode($ai_url) ?>,
    theme: <?= json_encode($current_theme) ?>,
    modules: <?= $modules_json ?>,
    buttons: <?= $buttons_json ?>,
    bubbleMenus: <?= $bubble_menus_json ?>,
    stickyToolbar: <?= $sticky ?>,
    version: <?= json_encode($lumina_version) ?>
};
// Auto-clear any saved localStorage Lumina settings on first load after an
// update so new buttons (like the toggle button) get registered in the
// correct toolbar position instead of being appended at the end.
(function () {
    try {
        var savedVersion = localStorage.getItem('lumina_settings_version');
        if (savedVersion !== '<?= $lumina_version ?>') {
            localStorage.removeItem('lumina_settings');
            localStorage.setItem('lumina_settings_version', '<?= $lumina_version ?>');
        }
    } catch (e) { /* localStorage may be disabled */ }
})();
</script>
<?php } ?>