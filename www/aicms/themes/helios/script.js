document.addEventListener('DOMContentLoaded', function() {
    // Fade-in animation on scroll
    const sectionsToFade = document.querySelectorAll(
        '.page-content article > *, .news-widget, .page-content img, .page-content h1, .page-content h2'
    );
    if ("IntersectionObserver" in window) {
        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach((entry, i) => {
                if (entry.isIntersecting) {
                    entry.target.style.transitionDelay = (i * 40) + 'ms';
                    entry.target.classList.add('is-visible');
                    obs.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1, rootMargin: "0px 0px -50px 0px" });

        sectionsToFade.forEach(s => { s.classList.add('fade-in-section'); observer.observe(s); });
    } else {
        sectionsToFade.forEach(s => s.classList.add('is-visible'));
    }
});
