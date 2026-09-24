<?php
/**
 * AICMS Admin UI Enhancements — PHP helper.
 *
 * Call `aicms_ui_footer_scripts()` right before `</body>` on any admin page
 * to enable:
 *   - Auto-dismiss flash messages (3 seconds)
 *   - Floating Action Bar (Save / Back) with language-neutral icons
 *
 * The helper also injects a small inline script that auto-loads the JS file
 * if it hasn't been loaded yet, so it works even on pages that don't include
 * a footer template.
 */
if (!function_exists('aicms_ui_footer_scripts')) {
    function aicms_ui_footer_scripts() {
        // Cache-busting version — bump when aicms-ui.js changes
        $v = '20260812';
        echo '<script src="assets/js/aicms-ui.js?v=' . $v . '"></script>' . "\n";
    }
}
