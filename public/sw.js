/*
 * SportsHub offline shell.
 *
 * Bump this version when the app-shell asset list or offline behavior changes.
 * User pages and API responses are never written to Cache Storage.
 */
const SW_VERSION = 'v5';
const CACHE_PREFIX = 'snnhs-sportshub';
const STATIC_CACHE = `${CACHE_PREFIX}-static-${SW_VERSION}`;
const APP_BASE = new URL('./', self.location);
const OFFLINE_URL = new URL('offline.html', APP_BASE).href;
const APP_SHELL = [
    'offline.html',
    'manifest.webmanifest',
    'css/theme.css',
    'css/portal.css',
    'css/landing.css',
    'css/pwa.css',
    'js/offline.js',
    'js/pwa-install.js',
    'favicon.ico',
    'images/icon-192.png',
    'images/icon-512.png',
    'images/icon-maskable-512.png',
].map((path) => new URL(path, APP_BASE).href);

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(STATIC_CACHE);
        await Promise.all(APP_SHELL.map(async (url) => {
            const response = await fetch(url, { cache: 'reload' });
            if (!response.ok || response.type !== 'basic') {
                throw new Error(`Unable to precache SportsHub asset: ${url}`);
            }
            await cache.put(url, response);
        }));
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys
            .filter((key) => key.startsWith(CACHE_PREFIX) && key !== STATIC_CACHE)
            .map((key) => caches.delete(key)));
        await self.clients.claim();
    })());
});

function isSameOrigin(url) {
    return url.origin === self.location.origin;
}

function isAppStaticAsset(url) {
    if (!url.pathname.startsWith(APP_BASE.pathname)) {
        return false;
    }

    const path = url.pathname.slice(APP_BASE.pathname.length);
    return /^(css|js|images|fonts)\/[^/]+(?:\/.*)?$/i.test(path)
        || /^(?:favicon\.ico|manifest\.webmanifest)$/i.test(path);
}

function mayCache(response) {
    const cacheControl = response.headers.get('Cache-Control') || '';

    return response.ok
        && response.type === 'basic'
        && !response.headers.has('Set-Cookie')
        && !/\b(?:private|no-store)\b/i.test(cacheControl);
}

async function refreshStaticAsset(request) {
    const response = await fetch(request, { cache: 'no-cache' });
    if (mayCache(response)) {
        try {
            const cache = await caches.open(STATIC_CACHE);
            await cache.put(request, response.clone());
        } catch (error) {
            console.error('SportsHub could not update a cached static asset:', error);
        }
    }
    return response;
}

async function cacheFirst(event, request) {
    const refresh = refreshStaticAsset(request);
    event.waitUntil(refresh.then(() => undefined, () => undefined));

    const cache = await caches.open(STATIC_CACHE);
    const cached = await cache.match(request);
    if (cached) {
        return cached;
    }

    try {
        return await refresh;
    } catch {
        return Response.error();
    }
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (!isSameOrigin(url)) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(async () => (
            await caches.match(OFFLINE_URL)
        ) || Response.error()));
        return;
    }

    if (isAppStaticAsset(url)) {
        event.respondWith(cacheFirst(event, request));
    }
});
