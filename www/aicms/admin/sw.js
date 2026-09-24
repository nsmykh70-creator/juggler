/**
 * AICMS Admin Service Worker
 * ==========================
 * Caches admin assets (JS, CSS, fonts) for offline use and faster loading.
 * Network-first strategy for PHP pages, cache-first for static assets.
 * Updates automatically when sw.js changes (bump SW_VERSION).
 */

const SW_VERSION = 'v1.0.0-20260808';
const STATIC_CACHE = `aicms-static-${SW_VERSION}`;
const RUNTIME_CACHE = `aicms-runtime-${SW_VERSION}`;

// Assets to cache immediately on install
const STATIC_ASSETS = [
    '/admin/assets/js/toast.js',
    '/admin/assets/js/modal-a11y.js',
    '/admin/assets/js/autosave.js',
    '/admin/assets/css/tailwind.css',
    'https://cdn.tailwindcss.com',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
    'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
];

// Install — cache static assets
self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(STATIC_CACHE).then(function(cache) {
            return cache.addAll(STATIC_ASSETS.map(url => new Request(url, { mode: 'no-cors' }))).catch(function() {
                // Ignore individual failures
            });
        }).then(function() {
            return self.skipWaiting();
        })
    );
});

// Activate — clean old caches
self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(names) {
            return Promise.all(
                names
                    .filter(name => name.startsWith('aicms-') && name !== STATIC_CACHE && name !== RUNTIME_CACHE)
                    .map(name => caches.delete(name))
            );
        }).then(function() {
            return self.clients.claim();
        })
    );
});

// Fetch strategy
self.addEventListener('fetch', function(event) {
    const request = event.request;
    
    // Only handle GET
    if (request.method !== 'GET') return;
    
    const url = new URL(request.url);
    
    // Skip non-http(s) requests
    if (!url.protocol.startsWith('http')) return;
    
    // Skip API calls and POST forms — always network
    if (url.pathname.includes('api_') || url.pathname.includes('save_') || url.pathname.includes('delete_') || url.pathname.includes('login.php') || url.pathname.includes('logout.php')) {
        return;
    }
    
    // Cache-first for static assets (CSS, JS, fonts, images from CDN)
    if (isStaticAsset(url)) {
        event.respondWith(
            caches.match(request).then(function(cached) {
                return cached || fetch(request).then(function(response) {
                    if (response && response.status === 200) {
                        const clone = response.clone();
                        caches.open(RUNTIME_CACHE).then(function(cache) {
                            cache.put(request, clone);
                        });
                    }
                    return response;
                }).catch(function() {
                    return cached;
                });
            })
        );
        return;
    }
    
    // Network-first for PHP pages (admin pages)
    event.respondWith(
        fetch(request).then(function(response) {
            // Only cache successful responses
            if (response && response.status === 200 && response.type === 'basic') {
                const clone = response.clone();
                caches.open(RUNTIME_CACHE).then(function(cache) {
                    cache.put(request, clone);
                });
            }
            return response;
        }).catch(function() {
            // Offline — try cache
            return caches.match(request).then(function(cached) {
                if (cached) return cached;
                // Return offline page for navigation requests
                if (request.mode === 'navigate') {
                    return new Response(
                        '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Офлайн</title><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f1f5f9;color:#1e293b;}div{text-align:center;}h1{font-size:24px;}p{color:#64748b;}</style></head><body><div><h1>📡 Нет соединения</h1><p>Подключение к серверу отсутствует. Проверьте интернет и попробуйте снова.</p></div></body></html>',
                        { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                    );
                }
            });
        })
    );
});

function isStaticAsset(url) {
    const ext = url.pathname.split('.').pop().toLowerCase();
    return ['css', 'js', 'woff', 'woff2', 'ttf', 'eot', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp'].includes(ext)
        || url.hostname !== location.hostname; // CDN assets
}
