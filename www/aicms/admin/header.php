<?php
/**
 * DEPRECATED — kept for backward compatibility.
 *
 * The modern admin UI uses /admin/core/topbar.php (sticky topbar with
 * breadcrumb, global search, theme toggle, action panel). This file is
 * a no-op stub so that any third-party code that still does
 * `include 'header.php';` will not break.
 *
 * If you need a header, include core/topbar.php instead:
 *   $page_title = 'My Page';
 *   $breadcrumb = [['label' => 'Dashboard', 'url' => 'index.php'], ['label' => 'My Page']];
 *   include __DIR__ . '/core/topbar.php';
 */
if (!defined('AICMS_SUPPRESS_DEPRECATED_HEADER_NOTICE')) {
    @trigger_error(
        'admin/header.php is deprecated — include admin/core/topbar.php instead.',
        E_USER_DEPRECATED
    );
}
// No output — topbar must be included explicitly by the page.
