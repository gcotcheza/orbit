// Served verbatim by App\Http\Controllers\Pwa\ServiceWorkerController (not bundled by Vite). DO NOT cache anything
// with a fare in it (docs/BUSINESS-LOGIC.md §35).

/* global __SW_PRECACHE__ */
// ^ Substituted by ServiceWorkerController; a global to ESLint, a literal to the browser.

const VERSION = '__SW_VERSION__'
const PREFIX = 'orbit-'
const CACHE = PREFIX + VERSION

const OFFLINE_URL = '/offline'

/** The current build's shell — see App\Services\Pwa\BuildAssets. */
const PRECACHE = __SW_PRECACHE__

/**
 * Install: allSettled (not all) so one 404 in precache doesn't abort install; cache: 'reload' avoids installing an
 * asset the browser cached before this deploy (docs/BUSINESS-LOGIC.md §35).
 */
self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const cache = await caches.open(CACHE)

            await Promise.allSettled(
                PRECACHE.map((url) => cache.add(new Request(url, { cache: 'reload' })))
            )

            await self.skipWaiting()
        })()
    )
})

/**
 * Activate: skipWaiting + claim rather than waiting for tabs to close (a home-screen app's tab can stay open for
 * weeks); only prefix-matched caches are deleted, never a blanket wipe (docs/BUSINESS-LOGIC.md §35).
 */
self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const names = await caches.keys()

            await Promise.all(
                names
                    .filter((name) => name.startsWith(PREFIX) && name !== CACHE)
                    .map((name) => caches.delete(name))
            )

            await self.clients.claim()
        })()
    )
})

/**
 * SKIP_WAITING handler: unreachable today (install() already skips waiting) but required by resources/js/lib/pwa.js's
 * update handshake; narrow by design (one message type only) (docs/BUSINESS-LOGIC.md §35).
 */
self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        self.skipWaiting()
    }
})

self.addEventListener('fetch', (event) => {
    const request = event.request

    // Writes are never intercepted — there is no offline queue, and a replayed PATCH would be worse than a failed one
    // (docs/BUSINESS-LOGIC.md §35).
    if (request.method !== 'GET') return

    const url = new URL(request.url)

    if (url.origin !== self.location.origin) return

    // Fares: this worker cannot judge their shelf life, so /api/ is never cached.
    if (url.pathname.startsWith('/api/')) return

    if (isCacheable(url)) {
        event.respondWith(cacheFirst(request))

        return
    }

    if (request.mode === 'navigate') {
        event.respondWith(navigate(request))
    }
})

/**
 * Three cacheable families: /build/ is content-hashed (URL changes = new file, served immutable); /globe/ and /icons/
 * aren't hashed but rarely change and are served max-age=1wk (docs/BUSINESS-LOGIC.md §35).
 */
function isCacheable(url) {
    return url.pathname.startsWith('/build/') ||
        url.pathname.startsWith('/globe/') ||
        url.pathname.startsWith('/icons/') ||
        PRECACHE.includes(url.pathname)
}

async function cacheFirst(request) {
    const cache = await caches.open(CACHE)

    const hit = await cache.match(request)

    if (hit) return hit

    const response = await fetch(request)

    // 200 + same-origin only: a cached 206 (partial) fragment would decode to nothing.
    if (response.status === 200 && response.type === 'basic') {
        cache.put(request, response.clone())
    }

    return response
}

/**
 * Navigate: always network, offline page on failure — never a cached copy of a real page, since the fallback carries
 * no fares (docs/BUSINESS-LOGIC.md §35).
 */
async function navigate(request) {
    try {
        return await fetch(request)
    } catch {
        const cache = await caches.open(CACHE)

        const offline = await cache.match(OFFLINE_URL)

        return offline ?? Response.error()
    }
}
