/**
 * Lumina Animation Observer
 * ==========================
 * Ensures animations only play when the element scrolls into the viewport,
 * not immediately on page load.
 *
 * HOW IT WORKS
 * ------------
 * 1. On DOMContentLoaded, find every element with a `.lumina-anim-*` class.
 * 2. Add the class `lumina-anim-paused` which sets `animation-play-state: paused`.
 *    This freezes the animation at its starting state.
 * 3. Use IntersectionObserver to watch each element.
 * 4. When an element enters the viewport (with a small rootMargin so it
 *    triggers slightly before the user actually sees it), remove the
 *    `lumina-anim-paused` class so the animation starts playing.
 * 5. For non-loop animations, unobserve after triggering (they play once).
 * 6. For loop animations (elements with `.lumina-anim-loop`), also unobserve
 *    after triggering — the animation will continue forever on its own.
 *
 * This file is loaded on the frontend (via themes) AND in the editor
 * (via editor.js import). On the editor side, the observer also watches
 * for dynamically-inserted elements via a MutationObserver.
 */
(function () {
    'use strict';

    // All animation classes that should be observed.
    var ANIM_CLASS_PREFIX = 'lumina-anim-';
    var PAUSED_CLASS = 'lumina-anim-paused';

    // Check if an element has any animation class.
    function hasAnimClass(el) {
        if (!el || !el.classList) return false;
        for (var i = 0; i < el.classList.length; i++) {
            if (el.classList[i].indexOf(ANIM_CLASS_PREFIX) === 0 && el.classList[i] !== PAUSED_CLASS) {
                // Skip the loop modifier — it's not an animation itself.
                if (el.classList[i] === 'lumina-anim-loop') continue;
                return true;
            }
        }
        return false;
    }

    // Collect all animation-class elements on the page.
    function collectAnimElements() {
        var all = document.querySelectorAll('[class*="lumina-anim-"]');
        var result = [];
        for (var i = 0; i < all.length; i++) {
            if (hasAnimClass(all[i])) result.push(all[i]);
        }
        return result;
    }

    // The IntersectionObserver. rootMargin: '50px' triggers the animation
    // slightly before the element is fully visible, so it feels responsive.
    var observer = null;

    function ensureObserver() {
        if (observer) return observer;
        if (!('IntersectionObserver' in window)) return null;

        observer = new IntersectionObserver(function (entries) {
            for (var i = 0; i < entries.length; i++) {
                var entry = entries[i];
                if (entry.isIntersecting) {
                    var el = entry.target;
                    // Remove the paused class to start the animation.
                    el.classList.remove(PAUSED_CLASS);
                    // Once triggered, stop observing this element.
                    // (Loop animations continue infinitely on their own;
                    //  non-loop animations play once and stop.)
                    observer.unobserve(el);
                }
            }
        }, {
            root: null,
            rootMargin: '50px',
            threshold: 0.1
        });

        return observer;
    }

    // Pause an element's animation and start observing it.
    function registerElement(el) {
        if (!hasAnimClass(el)) return;
        if (el.classList.contains(PAUSED_CLASS)) return; // already registered

        // Check if the element is ALREADY in the viewport on page load.
        // If so, don't pause it — just let it play immediately.
        var rect = el.getBoundingClientRect();
        var inViewport = (
            rect.top < (window.innerHeight || document.documentElement.clientHeight) &&
            rect.bottom > 0 &&
            rect.left < (window.innerWidth || document.documentElement.clientWidth) &&
            rect.right > 0
        );

        if (inViewport) {
            // Element is already visible — let it animate immediately.
            return;
        }

        // Element is below the fold — pause it until scrolled into view.
        el.classList.add(PAUSED_CLASS);

        var obs = ensureObserver();
        if (obs) {
            obs.observe(el);
        } else {
            // Fallback: if IntersectionObserver isn't supported, just play
            // the animation immediately (remove paused class).
            el.classList.remove(PAUSED_CLASS);
        }
    }

    // Initialize: find all existing animation elements and register them.
    function init() {
        var elements = collectAnimElements();
        for (var i = 0; i < elements.length; i++) {
            registerElement(elements[i]);
        }

        // Watch for dynamically-inserted elements (e.g. via AJAX content
        // loading, or editor insertions). When new elements with animation
        // classes are added, register them too.
        if ('MutationObserver' in window) {
            var bodyObserver = new MutationObserver(function (mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    var mutation = mutations[i];
                    if (mutation.type !== 'childList') continue;
                    for (var j = 0; j < mutation.addedNodes.length; j++) {
                        var node = mutation.addedNodes[j];
                        if (node.nodeType !== 1) continue;
                        // Check the node itself.
                        if (hasAnimClass(node)) registerElement(node);
                        // Check descendants.
                        var descendants = node.querySelectorAll ? node.querySelectorAll('[class*="lumina-anim-"]') : [];
                        for (var k = 0; k < descendants.length; k++) {
                            registerElement(descendants[k]);
                        }
                    }
                }
            });
            bodyObserver.observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
