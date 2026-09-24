<!-- AICMS Tailwind+Alpine head block — include in <head> of all admin pages -->
<!-- Tailwind CSS — LOCAL ONLY (no Play CDN).
     The admin UI uses Tailwind utility classes that depend on Tailwind's
     preflight (CSS reset). We load the FULL precompiled Tailwind v2.2.19
     (tailwind.local.css) which includes the preflight, PLUS tailwind-shim.css
     for v3 color classes and overflow-clip fix. -->
<link rel="stylesheet" href="../assets/css/tailwind.local.css?v=20260723">
<link rel="stylesheet" href="../assets/css/tailwind-shim.css?v=20260810">
<!-- Alpine.js for interactive components (dropdowns, modals, tabs) -->
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<!-- AICMS custom component layer (Tailwind @apply classes) -->
<link rel="stylesheet" href="assets/css/tailwind.css?v=20261005">
<!-- Font Awesome for icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<!-- AICMS toast notification system (smart stacking, grouping) -->
<script src="assets/js/toast.js?v=20260801"></script>
<!-- AICMS modal accessibility helper (ARIA roles, focus trap, ESC) -->
<script src="assets/js/modal-a11y.js?v=20260801"></script>
<!-- AICMS JS localization dictionary — exposes window.I18N for use in .js files -->
<script>
window.I18N = <?= js_i18n() ?>;
window.I18N.t = function(key, fallback) {
    return (this && this[key]) ? this[key] : (fallback || key);
};
window.I18N.fmt = function(key) {
    // sprintf-like: window.I18N.fmt('fm_delete_confirm', 'file.txt') => "Delete «file.txt»?"
    var s = this[key] || key;
    if (arguments.length < 2) return s;
    var args = Array.prototype.slice.call(arguments, 1);
    var i = 0;
    return s.replace(/%s/g, function() { return args[i++] !== undefined ? args[i-1] : '%s'; });
};
// Legacy toast() function for backward compatibility with filemanager.js / media-library.js
window.toast = function(message, type) {
    if (window.AicmsToast) window.AicmsToast.show(message, type);
};
</script>
<!-- Admin stretch-button animation speed (ms) — data/config.json → ui.stretch_speed -->
<script>
window.AICMS_STRETCH_MS = <?= (int)(isset($config['ui']['stretch_speed']) ? $config['ui']['stretch_speed'] : 700) ?>;
</script>
<!-- Service Worker for offline cache (admin only) -->
<script>
if ('serviceWorker' in navigator && location.pathname.indexOf('/admin/') !== -1) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('sw.js').catch(function(e) {
            console.warn('SW registration failed:', e);
        });
    });
}
</script>
