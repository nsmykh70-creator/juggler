/**
 * Lumina Toggle Button — frontend runtime
 * ----------------------------------------
 * Enables the "Читать далее / Свернуть" toggle buttons inserted via the
 * Lumina editor.
 *
 * HOW IT WORKS
 * The editor inserts only a button (wrapped in [data-lumina-toggle]).
 * The user then types content AFTER the button — that content becomes the
 * "hidden" content on the frontend.
 *
 * On DOMContentLoaded, this script:
 *   1. Finds every [data-lumina-toggle] wrapper.
 *   2. For each one, collects all following sibling elements (until the
 *      next [data-lumina-toggle] or end of parent).
 *   3. Wraps those siblings in a new <div class="lumina-toggle-content">
 *      with display:none.
 *
 * On click, the script toggles the visibility of that wrapper and swaps
 * the button label between data-text-collapsed and data-text-expanded.
 *
 * Backward compatibility: if the old structure is encountered (where
 * .lumina-toggle-content is already a CHILD of [data-lumina-toggle]),
 * the wrapping step is skipped and the existing content div is used.
 */
(function () {
    'use strict';

    function isHidden(el) {
        if (el.style.display === 'none') return true;
        var cs = window.getComputedStyle(el);
        return cs.display === 'none' || cs.visibility === 'hidden';
    }

    /**
     * Find the .lumina-toggle-content element associated with a toggle
     * wrapper. Checks: (1) child (old structure), (2) next sibling
     * (new structure, created by wrapFollowingContent).
     */
    function findContent(wrapper) {
        // 1. Old structure: .lumina-toggle-content is a child of [data-lumina-toggle]
        var childContent = wrapper.querySelector(':scope > .lumina-toggle-content');
        if (childContent) return childContent;

        // 2. New structure: .lumina-toggle-content is the next sibling
        var sibling = wrapper.nextElementSibling;
        if (sibling && sibling.classList && sibling.classList.contains('lumina-toggle-content')) {
            return sibling;
        }
        return null;
    }

    /**
     * For each [data-lumina-toggle] wrapper, collect all following sibling
     * elements (until the next [data-lumina-toggle] or end of parent) and
     * wrap them in a hidden <div class="lumina-toggle-content">.
     *
     * Skips wrappers that already have a .lumina-toggle-content child
     * (old structure) or whose following sibling is already a
     * .lumina-toggle-content (already processed).
     */
    function wrapFollowingContent() {
        var toggles = document.querySelectorAll('[data-lumina-toggle]');
        Array.prototype.forEach.call(toggles, function (toggle) {
            // Skip if already processed
            if (toggle.getAttribute('data-lumina-processed') === '1') return;

            // Skip if old structure (.lumina-toggle-content is already a child)
            var existingChild = toggle.querySelector(':scope > .lumina-toggle-content');
            if (existingChild) {
                toggle.setAttribute('data-lumina-processed', '1');
                return;
            }

            // Skip if next sibling is already a .lumina-toggle-content (processed)
            var nextSibling = toggle.nextElementSibling;
            if (nextSibling && nextSibling.classList && nextSibling.classList.contains('lumina-toggle-content')) {
                toggle.setAttribute('data-lumina-processed', '1');
                return;
            }

            // Collect following siblings until next [data-lumina-toggle] or end
            var siblings = [];
            var node = toggle.nextElementSibling;
            while (node) {
                // Stop at the next toggle wrapper
                if (node.nodeType === 1 && node.matches('[data-lumina-toggle]')) break;
                siblings.push(node);
                node = node.nextElementSibling;
            }

            // If nothing follows the button, nothing to hide
            if (siblings.length === 0) {
                toggle.setAttribute('data-lumina-processed', '1');
                return;
            }

            // Create the hidden content wrapper
            var content = document.createElement('div');
            content.className = 'lumina-toggle-content';
            content.style.display = 'none';

            // Insert the wrapper right after the toggle
            toggle.parentNode.insertBefore(content, toggle.nextSibling);

            // Move all collected siblings into the wrapper
            siblings.forEach(function (s) {
                content.appendChild(s);
            });

            toggle.setAttribute('data-lumina-processed', '1');
        });
    }

    function handleToggleClick(event) {
        var target = event.target;
        var btn = target.closest ? target.closest('.toggle-news-btn') : null;
        if (!btn) return;

        var wrapper = btn.closest('[data-lumina-toggle]');
        if (!wrapper) return; // not a Lumina-inserted toggle — let other handlers run

        // Find content: could be sibling OR the wrapper could be INSIDE the content now
        var content = findContent(wrapper);
        if (!content) {
            // Maybe wrapper was moved inside content — search up
            var parent = wrapper.parentElement;
            if (parent && parent.classList && parent.classList.contains('lumina-toggle-content')) {
                content = parent;
            }
        }
        if (!content) return;

        var willShow = isHidden(content);
        content.style.display = willShow ? 'block' : 'none';

        var expandedText  = btn.getAttribute('data-text-expanded')  || 'Свернуть';
        var collapsedText = btn.getAttribute('data-text-collapsed') || 'Читать далее';

        if (willShow) {
            // Expanding: move button to END of content, change text to "Свернуть"
            btn.textContent = expandedText;
            if (wrapper.parentElement !== content) {
                content.appendChild(wrapper);
            }
        } else {
            // Collapsing: move button back BEFORE content, change text to "Читать далее"
            btn.textContent = collapsedText;
            if (wrapper.parentElement === content) {
                content.parentNode.insertBefore(wrapper, content);
            }
        }

        btn.setAttribute('aria-expanded', willShow ? 'true' : 'false');

        event.preventDefault();
        event.stopPropagation();
    }

    function init() {
        // Wrap following content into hidden .lumina-toggle-content divs
        wrapFollowingContent();

        // Use capture phase so we run BEFORE any theme-level bubble-phase
        // listener (e.g. the news-item handler in themes/helios).
        document.addEventListener('click', handleToggleClick, true);

        // Code block copy button — use bubble phase (default) so it doesn't
        // interfere with toggle button's capture-phase handler.
        document.addEventListener('click', handleCodeCopyClick);

        // Initial ARIA setup
        var buttons = document.querySelectorAll('[data-lumina-toggle] .toggle-news-btn');
        Array.prototype.forEach.call(buttons, function (btn) {
            btn.setAttribute('aria-expanded', 'false');
        });

        // --- Code block cleanup for frontend ---
        // The Lumina editor inserts code blocks with: language <select>,
        // "Edit" button, "Copy" button. On the live site we want to:
        //   1. Hide the language dropdown (it's editor-only)
        //   2. Hide the "Edit" button (it opens an editor modal — useless on frontend)
        //   3. Keep the "Copy" button functional (copies code to clipboard)
        //   4. Style the Copy button to look like a proper blue button
        initCodeBlockStyles();
        cleanupCodeBlocks();
    }

    /**
     * Code block cleanup: hide editor-only UI, enhance copy button.
     * Called once on init(). Also called via event delegation for any
     * code blocks added dynamically (e.g. via AJAX-loaded content).
     */
    function cleanupCodeBlocks() {
        var wrappers = document.querySelectorAll('.lumina-code-wrapper');
        Array.prototype.forEach.call(wrappers, function (wrapper) {
            // Skip if already processed
            if (wrapper.getAttribute('data-lumina-code-processed') === '1') return;

            // Hide the language <select> dropdown (editor-only)
            var langSelect = wrapper.querySelector('.lumina-code-lang-select');
            if (langSelect) {
                langSelect.style.display = 'none';
            }

            // Hide the "Edit" button (editor-only — opens modal in admin)
            var editBtn = wrapper.querySelector('.lumina-code-edit');
            if (editBtn) {
                editBtn.style.display = 'none';
            }

            // Enhance the "Copy" button: add an SVG icon + make it look
            // like a proper button. The styling comes from the injected
            // CSS (see initCodeBlockStyles below).
            var copyBtn = wrapper.querySelector('.lumina-code-copy');
            if (copyBtn) {
                // Add a copy icon SVG if not already present
                if (!copyBtn.querySelector('svg')) {
                    var iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
                    copyBtn.innerHTML = iconSvg + '<span class="lumina-code-copy-label">' + (copyBtn.innerText || 'Copy') + '</span>';
                }
            }

            // Ensure the actions container is right-aligned
            var actions = wrapper.querySelector('.lumina-code-actions');
            if (actions) {
                actions.style.marginLeft = 'auto';
            }

            wrapper.setAttribute('data-lumina-code-processed', '1');
        });
    }

    /**
     * Inject CSS for code block copy button on the frontend.
     * This makes the button look like a proper blue button with icon,
     * positioned on the right side of the code block header.
     */
    function initCodeBlockStyles() {
        if (document.getElementById('lumina-code-frontend-styles')) return;
        var styleTag = document.createElement('style');
        styleTag.id = 'lumina-code-frontend-styles';
        styleTag.textContent =
            /* Code block wrapper */
            '.lumina-code-wrapper { margin: 1.5em 0; background-color: #282c34; border-radius: 8px; overflow: hidden; font-family: "Fira Code", "Consolas", monospace; position: relative; border: 1px solid #3e4451; }' +
            /* Header bar */
            '.lumina-code-header { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background-color: #21252b; border-bottom: 1px solid #3e4451; user-select: none; }' +
            /* Actions container — right-aligned */
            '.lumina-code-actions { display: flex; gap: 6px; margin-left: auto; }' +
            /* Copy button — enhanced, looks like a real button */
            '.lumina-code-copy { display: inline-flex !important; align-items: center; gap: 5px; background: #2563eb; border: 1px solid #2563eb; color: #ffffff; font-size: 12px; font-weight: 600; padding: 5px 14px; border-radius: 6px; cursor: pointer; transition: all 0.15s ease; font-family: -apple-system, system-ui, sans-serif; line-height: 1; box-shadow: 0 1px 3px rgba(37, 99, 235, 0.3); }' +
            '.lumina-code-copy:hover { background: #1d4ed8; border-color: #1d4ed8; color: #ffffff; box-shadow: 0 2px 8px rgba(37, 99, 235, 0.5); transform: translateY(-1px); }' +
            '.lumina-code-copy:active { transform: translateY(0); box-shadow: 0 1px 2px rgba(37, 99, 235, 0.2); }' +
            '.lumina-code-copy svg { width: 13px; height: 13px; flex-shrink: 0; }' +
            '.lumina-code-copy-label { font-size: 12px; }' +
            /* Code content */
            '.lumina-code-content { margin: 0; padding: 16px; color: #abb2bf; background-color: #282c34; white-space: pre-wrap; word-break: break-word; overflow-x: hidden; outline: none; font-size: 13px; line-height: 1.6; border: none; font-family: "Fira Code", "Consolas", monospace; cursor: default; }';
        document.head.appendChild(styleTag);
    }

    /**
     * Event delegation for copy button clicks. Works for all code blocks
     * on the page, including ones added dynamically after init().
     *
     * NOTE: Uses `closest('.lumina-code-copy')` so that clicks on the SVG
     * icon or the label span (children of the button) are also caught.
     * The previous check `target.classList.contains('lumina-code-copy')`
     * only matched clicks on the button element itself, which is why the
     * button only worked when clicking on its empty padding area.
     */
    function handleCodeCopyClick(event) {
        var target = event.target;
        var copyBtn = target.closest ? target.closest('.lumina-code-copy') : null;
        if (!copyBtn) return;

        var wrapper = copyBtn.closest('.lumina-code-wrapper');
        if (!wrapper) return;

        var pre = wrapper.querySelector('.lumina-code-content');
        if (!pre) return;

        var text = pre.innerText || pre.textContent;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                showCopyFeedback(copyBtn, true);
            }).catch(function () {
                // Fallback for older browsers
                fallbackCopy(text);
                showCopyFeedback(copyBtn, true);
            });
        } else {
            fallbackCopy(text);
            showCopyFeedback(copyBtn, true);
        }
        event.preventDefault();
        event.stopPropagation();
    }

    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
    }

    function showCopyFeedback(btn, success) {
        // Find the label span (created by cleanupCodeBlocks).
        // If it doesn't exist, fall back to the button's innerHTML.
        var label = btn.querySelector('.lumina-code-copy-label');
        var originalText = label ? label.innerText : btn.innerText;
        var originalHTML = label ? label.innerHTML : btn.innerHTML;

        if (label) {
            label.innerText = success ? '✓ Скопировано!' : '✗ Ошибка';
        } else {
            btn.innerText = success ? '✓ Скопировано!' : '✗ Ошибка';
        }
        btn.style.background = success ? '#16a34a' : '#dc2626';
        btn.style.borderColor = success ? '#16a34a' : '#dc2626';

        setTimeout(function () {
            if (label) {
                label.innerHTML = originalHTML;
            } else {
                btn.innerHTML = originalHTML;
            }
            btn.style.background = '';
            btn.style.borderColor = '';
        }, 2000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
