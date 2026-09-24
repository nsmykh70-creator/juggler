/**
 * AICMS — Modal accessibility helper
 * ===================================
 * Adds proper ARIA roles, focus trapping, and keyboard navigation
 * to existing modal dialogs.
 *
 * Usage:
 *   AicmsModal.setup('#previewModal', {
 *       onOpen: function() { ... },
 *       onClose: function() { ... },
 *       closeOnEsc: true,
 *       closeOnBackdrop: true
 *   });
 *
 *   // To open: AicmsModal.open('#previewModal');
 *   // To close: AicmsModal.close('#previewModal');
 *
 * Or call AicmsModal.setupAll() to auto-wire all elements with
 * class "aicms-modal" or "fm-modal" or "preview-modal".
 */
(function () {
    'use strict';

    var MODAL_SELECTORS = ['.aicms-modal', '.fm-modal', '.preview-modal', '[data-modal]'];
    var registeredModals = {}; // selector → { el, opts, lastFocused }
    var openModals = []; // stack of open modal selectors

    function isVisible(el) {
        return el && el.offsetParent !== null && getComputedStyle(el).display !== 'none';
    }

    function getFocusable(el) {
        if (!el) return [];
        var selector = 'a[href], area[href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), ' +
            'textarea:not([disabled]), button:not([disabled]), iframe, object, embed, [tabindex="0"], [contenteditable]';
        return Array.prototype.slice.call(el.querySelectorAll(selector)).filter(function (e) {
            return isVisible(e);
        });
    }

    function setup(selector, opts) {
        opts = opts || {};
        var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (!el) return;

        // Add ARIA attributes if missing
        if (!el.getAttribute('role')) el.setAttribute('role', 'dialog');
        if (!el.getAttribute('aria-modal')) el.setAttribute('aria-modal', 'true');
        if (!el.getAttribute('aria-labelledby')) {
            var titleEl = el.querySelector('h4, h5, h3, .modal-title, .fm-modal-title');
            if (titleEl && !titleEl.id) titleEl.id = 'modal-title-' + Math.random().toString(36).substr(2, 9);
            if (titleEl) el.setAttribute('aria-labelledby', titleEl.id);
        }

        // Find close button (× or .close)
        var closeBtn = el.querySelector('[onclick*="remove(\'show\')"], [onclick*="classList.remove"], .close, .fm-modal-close, .preview-modal-close, button[onclick*="close"]');
        if (closeBtn && !closeBtn.getAttribute('aria-label')) {
            closeBtn.setAttribute('aria-label', 'Close dialog');
        }

        // Backdrop click handler
        if (opts.closeOnBackdrop !== false) {
            el.addEventListener('click', function (e) {
                if (e.target === el) {
                    close(selector);
                }
            });
        }

        // ESC key handler (added when modal opens)
        registeredModals[selector] = { el: el, opts: opts };
    }

    function open(selector) {
        var reg = registeredModals[selector];
        if (!reg) {
            // Modal wasn't pre-registered — try to setup on the fly
            setup(selector, {});
            reg = registeredModals[selector];
            if (!reg) return;
        }
        var el = reg.el;
        reg.lastFocused = document.activeElement;

        // Show modal (multiple patterns supported)
        el.classList.add('show');
        el.classList.add('is-open');
        el.style.display = 'flex';

        // Trap focus
        var focusable = getFocusable(el);
        if (focusable.length) {
            setTimeout(function () { focusable[0].focus(); }, 50);
        }

        // Add ESC handler
        document.addEventListener('keydown', escHandler);

        // Track open stack
        if (openModals.indexOf(selector) === -1) openModals.push(selector);

        // Call onOpen callback
        if (reg.opts.onOpen) reg.opts.onOpen();
    }

    function close(selector) {
        var reg = registeredModals[selector];
        if (!reg) {
            // Try direct DOM manipulation for modals not registered
            var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
            if (el) {
                el.classList.remove('show');
                el.classList.remove('is-open');
                el.style.display = '';
            }
            return;
        }
        var el = reg.el;
        el.classList.remove('show');
        el.classList.remove('is-open');
        el.style.display = '';

        // Restore focus to element that had it before opening
        if (reg.lastFocused && reg.lastFocused.focus) {
            setTimeout(function () { reg.lastFocused.focus(); }, 50);
        }

        // Remove from open stack
        var idx = openModals.indexOf(selector);
        if (idx !== -1) openModals.splice(idx, 1);
        if (openModals.length === 0) {
            document.removeEventListener('keydown', escHandler);
        }

        // Call onClose callback
        if (reg.opts.onClose) reg.opts.onClose();
    }

    function escHandler(e) {
        if (e.key === 'Escape' && openModals.length > 0) {
            e.preventDefault();
            var topModal = openModals[openModals.length - 1];
            close(topModal);
        }
        // Tab trapping inside topmost modal
        if (e.key === 'Tab' && openModals.length > 0) {
            var topSelector = openModals[openModals.length - 1];
            var topReg = registeredModals[topSelector];
            if (!topReg) return;
            var focusable = getFocusable(topReg.el);
            if (focusable.length === 0) return;
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    function setupAll() {
        MODAL_SELECTORS.forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) {
                if (!el.id) el.id = 'modal-' + Math.random().toString(36).substr(2, 9);
                if (!registeredModals['#' + el.id]) {
                    setup('#' + el.id, {});
                }
            });
        });
    }

    // Auto-setup on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupAll);
    } else {
        setupAll();
    }

    window.AicmsModal = {
        setup: setup,
        open: open,
        close: close,
        setupAll: setupAll
    };
})();
