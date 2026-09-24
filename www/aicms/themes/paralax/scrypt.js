// Create AI particles background
function createParticles() {
    const container = document.getElementById('particles');
    const particleCount = 30;
    
    for (let i = 0; i < particleCount; i++) {
        const particle = document.createElement('div');
        particle.classList.add('particle');
        
        // Random size and position
        const size = Math.random() * 100 + 20;
        const posX = Math.random() * 100;
        const posY = Math.random() * 100;
        const animationDuration = Math.random() * 30 + 20;
        const delay = Math.random() * 5;
        
        particle.style.width = `${size}px`;
        particle.style.height = `${size}px`;
        particle.style.left = `${posX}%`;
        particle.style.top = `${posY}%`;
        particle.style.opacity = Math.random() * 0.3 + 0.1;
        particle.style.animationDuration = `${animationDuration}s`;
        particle.style.animationDelay = `${delay}s`;
        
        container.appendChild(particle);
    }
}

// Enhanced lightbox with gallery navigation
document.addEventListener('DOMContentLoaded', function() {
    createParticles();
    
    // Navbar scroll effect
    const navbar = document.querySelector('.navbar');
    if (navbar) {
        window.addEventListener('scroll', () => {
            navbar.classList.toggle('navbar-scrolled', window.scrollY > 50);
        }, { passive: true });
    }
    
    // Lightbox functionality
    const lightbox = document.getElementById('image-lightbox');
    const lightboxImage = lightbox.querySelector('img');
    const lightboxClose = document.getElementById('image-lightbox-close');
    const lightboxPrev = document.getElementById('lightbox-prev');
    const lightboxNext = document.getElementById('lightbox-next');
    
    // Collect all content images
    const images = Array.from(document.querySelectorAll('.page-content img'));
    let currentImageIndex = 0;
    
    const openLightbox = (index) => {
        currentImageIndex = index;
        lightboxImage.src = images[index].src;
        lightbox.classList.add('is-visible');
        document.body.classList.add('lightbox-open');
    };
    
    const closeLightbox = () => {
        lightbox.classList.remove('is-visible');
        document.body.classList.remove('lightbox-open');
    };
    
    const navigate = (direction) => {
        currentImageIndex = (currentImageIndex + direction + images.length) % images.length;
        lightboxImage.src = images[currentImageIndex].src;
    };
    
    // Set up image click events
    images.forEach((img, index) => {
        img.addEventListener('click', (e) => {
            e.preventDefault();
            openLightbox(index);
        });
    });
    
    // Lightbox controls
    lightboxClose.addEventListener('click', closeLightbox);
    lightbox.addEventListener('click', (e) => {
        if (e.target === lightbox) closeLightbox();
    });
    
    lightboxPrev.addEventListener('click', (e) => {
        e.stopPropagation();
        navigate(-1);
    });
    
    lightboxNext.addEventListener('click', (e) => {
        e.stopPropagation();
        navigate(1);
    });
    
    // Keyboard navigation
    document.addEventListener('keydown', (e) => {
        if (!lightbox.classList.contains('is-visible')) return;
        
        if (e.key === 'Escape') closeLightbox();
        if (e.key === 'ArrowLeft') navigate(-1);
        if (e.key === 'ArrowRight') navigate(1);
    });
    
    // Animation on scroll
    const fadeElements = document.querySelectorAll('.fade-in-section');
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
            }
        });
    }, {
        threshold: 0.1
    });
    
    fadeElements.forEach(el => observer.observe(el));
});