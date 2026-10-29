/*
 * Aureus home-screen app service worker.
 *
 * Caches ONLY static, public assets (CSS, JS, fonts, images under the asset
 * folders) and the offline page. Page navigations always go to the network;
 * when the network is down the offline page is shown instead. Authenticated
 * HTML, Livewire / API calls, uploads and anything that is not a GET are
 * never cached or served from cache.
 */
const VERSION = 'aureus-pwa-v1';
const STATIC_CACHE = VERSION + '-static';
const BASE = new URL(self.registration.scope).pathname.replace(/\/$/, '');
const OFFLINE_URL = BASE + '/offline.html';
const PRECACHE = [
    OFFLINE_URL,
    BASE + '/images/pwa/icon-192.png',
    BASE + '/images/pwa/icon-512.png',
    BASE + '/images/pwa/icon.svg',
];
const STATIC_FOLDERS = ['/css/', '/js/', '/fonts/', '/images/', '/build/', '/svg/', '/flags/'].map((folder) => BASE + folder);
const STATIC_FILE = /\.(?:css|js|mjs|woff2?|ttf|otf|eot|png|jpe?g|gif|svg|webp|ico)$/i;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => ! key.startsWith(VERSION)).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

function isStaticAsset(url) {
    return url.origin === self.location.origin
        && STATIC_FOLDERS.some((folder) => url.pathname.startsWith(folder))
        && STATIC_FILE.test(url.pathname);
}

function isCacheable(response) {
    if (! response || ! response.ok || response.type !== 'basic') {
        return false;
    }

    const type = response.headers.get('Content-Type') || '';
    const control = response.headers.get('Cache-Control') || '';

    return ! type.includes('text/html') && ! control.includes('no-store') && ! control.includes('private');
}

async function staleWhileRevalidate(request) {
    const cache = await caches.open(STATIC_CACHE);
    const cached = await cache.match(request);
    const network = fetch(request)
        .then((response) => {
            if (isCacheable(response)) {
                cache.put(request, response.clone());
            }

            return response;
        })
        .catch(() => cached || Response.error());

    return cached || network;
}

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    // Pages: network only (never cached); offline page when there is no network.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));

        return;
    }

    if (isStaticAsset(url)) {
        event.respondWith(staleWhileRevalidate(request));
    }

    // Everything else (Livewire, API, JSON, downloads, storage files) goes straight to the network.
});
