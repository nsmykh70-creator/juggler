/**
 * AICMS — Toast notification system with smart stacking
 * ======================================================
 * Replaces ad-hoc toast() implementations in filemanager.js and
 * media-library.js with a unified, smarter notification manager.
 *
 * Features:
 *   - Maximum 3 visible toasts at a time (older ones are removed)
 *   - Identical consecutive messages are grouped: "Удалено 3 файлов"
 *     instead of showing the same message 3 times
 *   - Auto-dismiss after 3s (info/success) or 5s (error/warning)
 *   - Pause auto-dismiss on hover
 *   - Manual close button
 *
 * Usage:
 *   AicmsToast.show('File deleted', 'success');
 *   AicmsToast.show('Upload failed', 'error');
 *   AicmsToast.show('Some info');
 *
 * To use the legacy toast() function name in existing code:
 *   var toast = AicmsToast.show;
 */
(function () {
    'use strict';

    var MAX_VISIBLE = 3;
    var AUTO_DISMISS_MS = {
        info:    3000,
        success: 3000,
        warning: 5000,
        error:   5000
    };

    var container = null;
    var activeToasts = []; // {el, msg, type, count, timer, paused}
    var lastMessage = null;
    var lastMessageType = null;
    var lastMessageTime = 0;
    var GROUP_WINDOW_MS = 1500; // group identical messages within this window

    function ensureContainer() {
        if (container && document.body.contains(container)) return container;
        container = document.createElement('div');
        container.id = 'aicms-toast-container';
        container.style.cssText = [
            'position:fixed', 'bottom:20px', 'right:20px', 'z-index:100000',
            'display:flex', 'flex-direction:column-reverse', 'gap:8px',
            'pointer-events:none', 'max-width:380px'
        ].join(';');
        document.body.appendChild(container);
        return container;
    }

    function removeToast(toastObj) {
        if (!toastObj || !toastObj.el) return;
        clearTimeout(toastObj.timer);
        toastObj.el.style.opacity = '0';
        toastObj.el.style.transform = 'translateX(20px)';
        setTimeout(function () {
            if (toastObj.el && toastObj.el.parentNode) {
                toastObj.el.parentNode.removeChild(toastObj.el);
            }
            var idx = activeToasts.indexOf(toastObj);
            if (idx !== -1) activeToasts.splice(idx, 1);
        }, 200);
    }

    function show(message, type) {
        type = type || 'info';
        var now = Date.now();

        // Group identical messages within GROUP_WINDOW_MS
        if (lastMessage === message && lastMessageType === type &&
            (now - lastMessageTime) < GROUP_WINDOW_MS && activeToasts.length > 0) {
            // Update the most recent toast of this type
            for (var i = activeToasts.length - 1; i >= 0; i--) {
                var t = activeToasts[i];
                if (t.msg === message && t.type === type) {
                    t.count++;
                    t.el.querySelector('.aicms-toast-text').textContent = message + ' (' + t.count + '×)';
                    // Reset auto-dismiss timer
                    clearTimeout(t.timer);
                    t.timer = setTimeout(function () { removeToast(t); }, AUTO_DISMISS_MS[type]);
                    lastMessageTime = now;
                    return;
                }
            }
        }

        ensureContainer();

        // Enforce MAX_VISIBLE: remove oldest
        while (activeToasts.length >= MAX_VISIBLE) {
            removeToast(activeToasts[0]);
        }

        // Create new toast
        var colors = {
            info:    '#2563eb',
            success: '#16a34a',
            warning: '#f59e0b',
            error:   '#dc2626'
        };
        var icons = {
            info:    'fa-info-circle',
            success: 'fa-check-circle',
            warning: 'fa-exclamation-triangle',
            error:   'fa-times-circle'
        };
        var el = document.createElement('div');
        el.className = 'aicms-toast aicms-toast-' + type;
        el.style.cssText = [
            'display:flex', 'align-items:center', 'gap:10px',
            'padding:12px 14px', 'border-radius:8px',
            'background:' + (colors[type] || colors.info),
            'color:#fff', 'font-size:13px', 'font-family:inherit', 'font-weight:500',
            'box-shadow:0 8px 24px rgba(0,0,0,.2)',
            'pointer-events:auto', 'opacity:0', 'transform:translateX(20px)',
            'transition:opacity .2s, transform .2s'
        ].join(';');
        var icon = document.createElement('i');
        icon.className = 'fas ' + (icons[type] || icons.info);
        icon.style.fontSize = '14px';
        icon.style.flexShrink = '0';
        var text = document.createElement('span');
        text.className = 'aicms-toast-text';
        text.style.flex = '1';
        text.textContent = message;
        var closeBtn = document.createElement('button');
        closeBtn.style.cssText = 'background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:4px;width:20px;height:20px;cursor:pointer;font-size:11px;padding:0;line-height:1;flex-shrink:0;';
        closeBtn.innerHTML = '&times;';
        closeBtn.title = 'Close';
        el.appendChild(icon);
        el.appendChild(text);
        el.appendChild(closeBtn);

        var toastObj = { el: el, msg: message, type: type, count: 1, timer: null, paused: false };

        closeBtn.addEventListener('click', function () { removeToast(toastObj); });
        // Pause on hover
        el.addEventListener('mouseenter', function () {
            toastObj.paused = true;
            clearTimeout(toastObj.timer);
        });
        el.addEventListener('mouseleave', function () {
            if (toastObj.paused) {
                toastObj.paused = false;
                toastObj.timer = setTimeout(function () { removeToast(toastObj); }, AUTO_DISMISS_MS[type]);
            }
        });

        container.appendChild(el);
        activeToasts.push(toastObj);

        // Animate in
        requestAnimationFrame(function () {
            el.style.opacity = '1';
            el.style.transform = 'translateX(0)';
        });

        // Auto-dismiss
        toastObj.timer = setTimeout(function () { removeToast(toastObj); }, AUTO_DISMISS_MS[type]);

        // Track for grouping
        lastMessage = message;
        lastMessageType = type;
        lastMessageTime = now;
    }

    function info(message) { show(message, 'info'); }
    function success(message) { show(message, 'success'); }
    function warning(message) { show(message, 'warning'); }
    function error(message) { show(message, 'error'); }

    // Expose globally
    window.AicmsToast = {
        show: show,
        info: info,
        success: success,
        warning: warning,
        error: error,
        // Clear all toasts
        clear: function () {
            activeToasts.slice().forEach(removeToast);
        }
    };
})();
