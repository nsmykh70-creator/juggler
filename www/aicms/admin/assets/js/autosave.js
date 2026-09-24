/**
 * AICMS — Auto-save drafts to localStorage
 * =========================================
 * Periodically saves form field values to localStorage so that if the user
 * accidentally closes the tab or the browser crashes, the unsaved content
 * can be restored.
 *
 * Usage:
 *   1. Include this script on any admin page with an editor form.
 *   2. Call `initAutoSave({ formSelector: 'form', interval: 30, namespace: 'page_42' })`
 *      where:
 *        - formSelector: CSS selector for the <form> element to track
 *        - interval: save interval in seconds (default: 30)
 *        - namespace: unique string identifying this draft (e.g. 'page_42', 'news_5', 'new_page')
 *
 * On page load, if a draft exists and the form's current values differ from
 * what the server sent, a toast notification appears with "Restore" / "Discard"
 * buttons. If the user clicks "Restore", the draft values are filled in.
 *
 * On successful form submit, the draft is cleared.
 *
 * Note: The Lumina editor (used in edit_page.php / edit_news.php) needs special
 * handling because it stores its content in a hidden textarea. The `luminaField`
 * option lets you specify the selector for the Lumina-controlled textarea, and
 * autosave will read its `.value` (set by Lumina's onChange callback).
 */

(function () {
    'use strict';

    function tr(key, fallback) {
        if (window.I18N && window.I18N[key]) return window.I18N[key];
        return fallback || key;
    }

    function showToast(message, type, actions) {
        type = type || 'info';
        actions = actions || [];
        var t = document.createElement('div');
        t.style.cssText = 'position:fixed;bottom:20px;left:20px;z-index:100000;padding:12px 16px;border-radius:8px;' +
            'font-size:13px;font-family:inherit;color:#fff;background:' +
            (type === 'error' ? '#dc2626' : type === 'warning' ? '#f59e0b' : type === 'success' ? '#16a34a' : '#2563eb') +
            ';box-shadow:0 8px 24px rgba(0,0,0,.2);max-width:480px;display:flex;align-items:center;gap:10px;opacity:0;' +
            'transform:translateY(20px);transition:all .25s;';
        var text = document.createElement('span');
        text.style.flex = '1';
        text.textContent = message;
        t.appendChild(text);
        actions.forEach(function (action) {
            var btn = document.createElement('button');
            btn.textContent = action.label;
            btn.style.cssText = 'background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:5px;padding:6px 12px;cursor:pointer;font-size:12px;font-weight:600;';
            btn.addEventListener('click', function () {
                action.onClick && action.onClick();
                removeToast();
            });
            t.appendChild(btn);
        });
        // Auto-dismiss only if no actions
        document.body.appendChild(t);
        function removeToast() {
            t.style.opacity = '0';
            t.style.transform = 'translateY(20px)';
            setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 250);
        }
        requestAnimationFrame(function () {
            t.style.opacity = '1';
            t.style.transform = 'translateY(0)';
        });
        if (actions.length === 0) {
            setTimeout(removeToast, 4000);
        }
        return { remove: removeToast };
    }

    function initAutoSave(opts) {
        var form = document.querySelector(opts.formSelector);
        if (!form) return;
        var interval = (opts.interval || 30) * 1000;
        var namespace = opts.namespace || 'default';
        var luminaField = opts.luminaField || null; // selector for Lumina-controlled textarea
        var storageKey = 'aicms_autosave_' + namespace;
        var lastSavedHash = '';

        // Capture initial server-rendered state so we can detect "draft differs from server"
        function getFormSnapshot() {
            var data = {};
            var fields = form.querySelectorAll('input:not([type="file"]):not([type="hidden"]):not([type="submit"]):not([type="button"]), textarea, select');
            fields.forEach(function (field) {
                if (!field.name) return;
                data[field.name] = field.value;
            });
            // Capture Lumina content (it's set into the hidden textarea by Lumina's onChange)
            if (luminaField) {
                var luminaEl = document.querySelector(luminaField);
                if (luminaEl && luminaEl.name) {
                    data[luminaEl.name] = luminaEl.value;
                }
            }
            return data;
        }

        function restoreFromDraft(draft) {
            Object.keys(draft).forEach(function (name) {
                var field = form.querySelector('[name="' + CSS.escape(name) + '"]');
                if (!field) return;
                if (field.type === 'checkbox' || field.type === 'radio') {
                    field.checked = !!draft[name];
                } else {
                    field.value = draft[name];
                    // Trigger input event so Lumina / Alpine / other handlers can react
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            // For Lumina: explicitly set the editor content if the editor exposes a setContent method
            if (luminaField && window._luminaEditorInstance && window._luminaEditorInstance.setHTML) {
                var luminaEl = document.querySelector(luminaField);
                if (luminaEl && draft[luminaEl.name]) {
                    window._luminaEditorInstance.setHTML(draft[luminaEl.name]);
                }
            }
        }

        function hashObject(obj) {
            var s = JSON.stringify(obj);
            var h = 0, i, chr;
            for (i = 0; i < s.length; i++) {
                chr = s.charCodeAt(i);
                h = ((h << 5) - h) + chr;
                h |= 0;
            }
            return h.toString(36);
        }

        var initialSnapshot = getFormSnapshot();
        var initialHash = hashObject(initialSnapshot);

        // Save draft to localStorage
        function saveDraft() {
            var snapshot = getFormSnapshot();
            var hash = hashObject(snapshot);
            if (hash === lastSavedHash) return; // no changes since last save
            try {
                localStorage.setItem(storageKey, JSON.stringify({
                    data: snapshot,
                    savedAt: Date.now(),
                    hash: hash
                }));
                lastSavedHash = hash;
                // Subtle "saved" indicator (no toast — too noisy)
                var indicator = document.getElementById('aicms-autosave-indicator');
                if (!indicator) {
                    indicator = document.createElement('span');
                    indicator.id = 'aicms-autosave-indicator';
                    indicator.style.cssText = 'position:fixed;bottom:8px;right:8px;z-index:9999;padding:4px 10px;background:rgba(22,163,74,0.9);color:#fff;font-size:11px;border-radius:6px;pointer-events:none;opacity:0;transition:opacity .3s;';
                    document.body.appendChild(indicator);
                }
                indicator.textContent = '✓ ' + tr('autosave_saved', 'Draft saved');
                indicator.style.opacity = '1';
                clearTimeout(indicator._hideTimer);
                indicator._hideTimer = setTimeout(function () { indicator.style.opacity = '0'; }, 1500);
            } catch (e) {
                // localStorage might be full or disabled
            }
        }

        // Clear draft (called on successful form submit)
        function clearDraft() {
            try { localStorage.removeItem(storageKey); } catch (e) {}
        }

        // On successful form submit, clear the draft
        form.addEventListener('submit', function () {
            clearDraft();
        });

        // Check for existing draft on load — but only if it differs from server-rendered state
        try {
            var raw = localStorage.getItem(storageKey);
            if (raw) {
                var draft = JSON.parse(raw);
                if (draft && draft.data && draft.hash !== initialHash) {
                    // Draft exists and differs from server state — offer restore
                    var ageMin = Math.round((Date.now() - (draft.savedAt || 0)) / 60000);
                    showToast(tr('autosave_found', 'Unsaved draft found. Restore?') + ' (' + ageMin + ' min)', 'warning', [
                        { label: tr('autosave_restored', 'Restore'), onClick: function () {
                            restoreFromDraft(draft.data);
                            showToast(tr('autosave_restored', 'Draft restored'), 'success');
                        }},
                        { label: tr('autosave_discard', 'Discard'), onClick: function () {
                            clearDraft();
                        }}
                    ]);
                }
            }
        } catch (e) {}

        // Start periodic save
        setInterval(saveDraft, interval);
        // Also save on blur (when user leaves a field)
        form.addEventListener('blur', function (e) {
            if (e.target && e.target.tagName) saveDraft();
        }, true);
        // Save on visibility change (when user switches tab)
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') saveDraft();
        });

        return {
            save: saveDraft,
            clear: clearDraft,
            getSnapshot: getFormSnapshot
        };
    }

    // Expose globally
    window.initAutoSave = initAutoSave;
})();
