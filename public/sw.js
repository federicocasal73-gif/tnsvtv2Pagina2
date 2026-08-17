/**
 * T.N.S.V.T Sanctum Service Worker
 *
 * Strategy:
 *   - Navigation (HTML pages): network-first, fallback to cache (SPA feel)
 *   - Static assets (CSS/JS/images): cache-first (instant loads)
 *   - API endpoints (/api/*): network-only (never cache user data)
 *   - Mercure SSE: pass-through (don't intercept)
 *
 * To enable: register this file from JS as `navigator.serviceWorker.register('/sw.js')`
 * The app shell (shell.html.twig) handles the registration.
 */

const CACHE_VERSION = 'tnsvt-v1';
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const PAGES_CACHE = `${CACHE_VERSION}-pages`;
const OFFLINE_URL = '/offline';

// Assets that should always be cached aggressively
const PRECACHE_URLS = [
    OFFLINE_URL,
    '/assets/styles/tokens.css',
    '/assets/styles/components.css',
    '/assets/styles/animations.css',
    '/assets/styles/accessibility.css',
    '/assets/styles/shell.css',
    '/assets/styles/glow.css',
    '/manifest.json',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => {
            return cache.addAll(PRECACHE_URLS).catch(() => {
                // If any asset fails, just continue — install completes anyway
            });
        }).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.filter((key) => !key.startsWith(CACHE_VERSION))
                    .map((key) => caches.delete(key))
            );
        }).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Skip non-GET, Mercure SSE, and API endpoints
    if (request.method !== 'GET') return;
    if (url.pathname.startsWith('/.well-known/mercure')) return;
    if (url.pathname.startsWith('/api/')) return; // API always live

    // Static assets — cache-first
    if (url.pathname.startsWith('/assets/')) {
        event.respondWith(cacheFirst(request));
        return;
    }

    // HTML navigation — network-first, cache fallback for offline
    if (request.headers.get('accept')?.includes('text/html')) {
        event.respondWith(networkFirstWithOffline(request));
        return;
    }

    // Default: try cache, then network
    event.respondWith(
        caches.match(request).then((cached) => cached || fetch(request))
    );
});

async function cacheFirst(request) {
    const cache = await caches.open(STATIC_CACHE);
    const cached = await cache.match(request);
    if (cached) {
        // Refresh in background
        fetch(request).then((response) => {
            if (response.ok) cache.put(request, response.clone());
        }).catch(() => {});
        return cached;
    }
    const response = await fetch(request);
    if (response.ok) cache.put(request, response.clone());
    return response;
}

async function networkFirstWithOffline(request) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(PAGES_CACHE);
            cache.put(request, response.clone());
        }
        return response;
    } catch (e) {
        const cached = await caches.match(request);
        if (cached) return cached;
        const offline = await caches.match(OFFLINE_URL);
        if (offline) return offline;
        // Last resort: 503 response
        return new Response('Offline', {
            status: 503,
            statusText: 'Service Unavailable',
            headers: { 'Content-Type': 'text/plain' },
        });
    }
}

// Allow clients to skip waiting
self.addEventListener('message', (event) => {
    if (event.data === 'SKIP_WAITING') self.skipWaiting();
});