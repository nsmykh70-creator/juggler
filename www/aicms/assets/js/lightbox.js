/**
 * Universal Lightbox — works in all AICMS themes
 */
(function() {
    'use strict';
    if (document.querySelector('#aicms-lightbox')) return;
    var lb = document.createElement('div');
    lb.id = 'aicms-lightbox';
    lb.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.9);z-index:9999;display:flex;align-items:center;justify-content:center;opacity:0;visibility:hidden;transition:opacity 0.3s,visibility 0s 0.3s;cursor:pointer;padding:20px;box-sizing:border-box;';
    var img = document.createElement('img');
    img.style.cssText = 'max-width:90vw;max-height:90vh;width:auto;height:auto;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,0.6);transition:transform 0.2s;';
    lb.appendChild(img);
    var close = document.createElement('span');
    close.innerHTML = '&times;';
    close.style.cssText = 'position:absolute;top:20px;right:30px;color:#fff;font-size:40px;font-weight:300;cursor:pointer;line-height:1;user-select:none;z-index:10000;';
    lb.appendChild(close);
    document.body.appendChild(lb);
    function open(src) { img.src = src; lb.style.visibility = 'visible'; lb.style.transition = 'opacity 0.3s'; requestAnimationFrame(function() { lb.style.opacity = '1'; }); document.body.style.overflow = 'hidden'; }
    function closeLb() { lb.style.opacity = '0'; setTimeout(function() { lb.style.visibility = 'hidden'; }, 300); document.body.style.overflow = ''; }
    document.addEventListener('click', function(e) {
        var target = e.target;
        if (target.tagName !== 'IMG') return;
        if (target.closest('button') || target.closest('.lumina-toggle-block') || target.closest('#aicms-lightbox')) return;
        if (target.width < 50 && target.height < 50) return;
        if (!target.closest('.page-content, .news-content-full, .news-content-short, article, .default-article, .glass-panel, .forum-post-content, .lumina-editor')) return;
        e.preventDefault();
        open(target.src);
    });
    lb.addEventListener('click', function(e) { if (e.target === lb || e.target === close || e.target === img) closeLb(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && lb.style.visibility === 'visible') closeLb(); });
    img.addEventListener('click', function(e) { e.stopPropagation(); img.style.transform = img.style.transform === 'scale(1.5)' ? 'scale(1)' : 'scale(1.5)'; });
})();

/**
 * Block scroll animations — triggers CSS animations when blocks enter viewport
 * Blocks with data-anim attribute get animated when scrolled into view
 * Animations: fade, slide-left, slide-right, slide-up, slide-down, zoom-in, zoom-out, flip, bounce
 */
(function() {
    'use strict';
    
    var animStyles = document.createElement('style');
    animStyles.textContent = `
        [data-anim="fade"] { opacity:0; transform:translateY(0); transition:opacity 0.8s ease, transform 0.8s ease; }
        [data-anim="fade"].is-visible { opacity:1; transform:translateY(0); }
        [data-anim="slide-left"] { opacity:0; transform:translateX(-60px); transition:opacity 0.8s ease, transform 0.8s ease; }
        [data-anim="slide-left"].is-visible { opacity:1; transform:translateX(0); }
        [data-anim="slide-right"] { opacity:0; transform:translateX(60px); transition:opacity 0.8s ease, transform 0.8s ease; }
        [data-anim="slide-right"].is-visible { opacity:1; transform:translateX(0); }
        [data-anim="slide-up"] { opacity:0; transform:translateY(40px); transition:opacity 0.8s ease, transform 0.8s ease; }
        [data-anim="slide-up"].is-visible { opacity:1; transform:translateY(0); }
        [data-anim="slide-down"] { opacity:0; transform:translateY(-40px); transition:opacity 0.8s ease, transform 0.8s ease; }
        [data-anim="slide-down"].is-visible { opacity:1; transform:translateY(0); }
        [data-anim="zoom-in"] { opacity:0; transform:scale(0.7); transition:opacity 0.6s ease, transform 0.6s ease; }
        [data-anim="zoom-in"].is-visible { opacity:1; transform:scale(1); }
        [data-anim="zoom-out"] { opacity:0; transform:scale(1.3); transition:opacity 0.6s ease, transform 0.6s ease; }
        [data-anim="zoom-out"].is-visible { opacity:1; transform:scale(1); }
        [data-anim="flip"] { opacity:0; transform:perspective(600px) rotateY(90deg); transition:opacity 0.8s ease, transform 0.8s ease; }
        [data-anim="flip"].is-visible { opacity:1; transform:perspective(600px) rotateY(0deg); }
        [data-anim="bounce"] { opacity:0; transform:translateY(-100px); transition:opacity 0.6s ease, transform 0.6s cubic-bezier(0.34,1.56,0.64,1); }
        [data-anim="bounce"].is-visible { opacity:1; transform:translateY(0); }
    `;
    document.head.appendChild(animStyles);
    
    // IntersectionObserver to add .is-visible when block enters viewport
    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
        
        // Observe all elements with data-anim
        function observeAnimElements() {
            document.querySelectorAll('[data-anim]:not(.is-visible)').forEach(function(el) {
                observer.observe(el);
            });
        }
        
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', observeAnimElements);
        } else {
            observeAnimElements();
        }
    } else {
        // Fallback: show all immediately
        document.querySelectorAll('[data-anim]').forEach(function(el) {
            el.classList.add('is-visible');
        });
    }
})();
