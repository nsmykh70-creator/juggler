/*
 * Legacy Bootstrap Toggle — Vanilla JS compatibility shim
 * Provides toggle behavior for accordion / tabs / dropdowns in pre-existing
 * user content (pages.json) that still uses data-bs-toggle attributes.
 * Loaded by all themes alongside assets/css/bootstrap-compat.css.
 */
(function () {
    document.addEventListener('click', function (e) {
        // Accordion toggle
        var accBtn = e.target.closest('[data-bs-toggle="collapse"]');
        if (accBtn) {
            var targetSel = accBtn.getAttribute('data-bs-target');
            if (!targetSel) return;
            var target = document.querySelector(targetSel);
            if (!target) return;
            target.classList.toggle('show');
            accBtn.classList.toggle('collapsed');
            // Optional: data-bs-parent — close siblings
            var parentSel = target.getAttribute('data-bs-parent');
            if (parentSel) {
                var parent = document.querySelector(parentSel);
                if (parent) {
                    parent.querySelectorAll('.accordion-collapse.show').forEach(function (c) {
                        if (c !== target) {
                            c.classList.remove('show');
                            var btn = parent.querySelector('[data-bs-target="#' + c.id + '"]');
                            if (btn) btn.classList.add('collapsed');
                        }
                    });
                }
            }
            return;
        }
        // Tab toggle
        var tabBtn = e.target.closest('[data-bs-toggle="tab"]');
        if (tabBtn) {
            var tabTargetSel = tabBtn.getAttribute('data-bs-target');
            if (!tabTargetSel) return;
            // Deactivate siblings
            var tabList = tabBtn.closest('.nav-tabs') || tabBtn.closest('.nav');
            if (tabList) {
                tabList.querySelectorAll('.nav-link').forEach(function (l) { l.classList.remove('active'); });
            }
            tabBtn.classList.add('active');
            // Hide all tab-panes in same .tab-content
            var tabContent = tabBtn.closest('.tab-content') || (tabTargetSel ? document.querySelector(tabTargetSel).closest('.tab-content') : null);
            if (tabContent) {
                tabContent.querySelectorAll('.tab-pane').forEach(function (p) { p.classList.remove('show', 'active'); });
            }
            var pane = document.querySelector(tabTargetSel);
            if (pane) { pane.classList.add('show', 'active'); }
            return;
        }
        // Dropdown toggle
        var ddBtn = e.target.closest('[data-bs-toggle="dropdown"]');
        if (ddBtn) {
            var ddMenu = ddBtn.nextElementSibling;
            while (ddMenu && !ddMenu.classList.contains('dropdown-menu')) ddMenu = ddMenu.nextElementSibling;
            if (ddMenu) {
                ddMenu.classList.toggle('show');
                ddBtn.setAttribute('aria-expanded', ddMenu.classList.contains('show'));
            }
            return;
        }
        // Click inside a dropdown menu — keep open (Bootstrap behavior)
        if (e.target.closest('.dropdown-menu.show')) return;
        // Click outside any open dropdown — close all
        document.querySelectorAll('.dropdown-menu.show').forEach(function (m) {
            m.classList.remove('show');
            var trigger = m.previousElementSibling;
            while (trigger && !trigger.matches('[data-bs-toggle="dropdown"]')) trigger = trigger.previousElementSibling;
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
        });
    });
})();
