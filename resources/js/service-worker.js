// =============================================================================
// Orbit — service worker
// =============================================================================
// This file is NOT bundled by Vite. It is served verbatim by
// App\Http\Controllers\Pwa\ServiceWorkerController, which substitutes the two
// tokens below with the current build's version and precache list. Keeping it
// out of the bundle is deliberate: a worker whose filename carries a content
// hash can never be updated (the browser looks for the same URL it registered),
// and a worker that imported the app's module graph would be a second copy of
// the app running where there is no DOM.
//
// THE RULE THIS FILE EXISTS TO OBEY
//
// Nothing with a fare in it is ever cached. Not a route, not a calendar, not
// /api/watchlist. A cached price is a price that is wrong without looking
// wrong — the whole app is one number per row, and there is no way for the user
// to tell yesterday's €58 from today's. So the cache holds exactly two kinds of
// thing:
//
//   - assets whose URL IS their version (the content-hashed build output), and
//   - static files that contain no data: icons, textures, the offline page.
//
// | Request                             | Strategy                          |
// | ----------------------------------- | --------------------------------- |
// | /build/*  (hashed, immutable)       | cache-first, runtime-filled       |
// | /globe/*  (earth textures, 2.5 MB)  | cache-first, runtime-filled       |
// | /icons/*, /icon.svg                 | cache-first                       |
// | precached statics (see PRECACHE)    | cache-first                       |
// | navigations (HTML)                  | network, /offline on failure      |
// | /api/*                              | not intercepted                   |
// | anything not GET                    | not intercepted                   |
// | cross-origin                        | not intercepted                   |
//
// "Not intercepted" means this worker calls neither respondWith nor fetch — the
// request goes to the network exactly as it would with no worker installed.
// That is stronger than a network-only handler: there is no code path in which
// a bug here can answer one of those requests out of a cache.
//
// THE APP DOES NOT RUN OFFLINE, AND IS NOT PRETENDING TO. Every screen is a
// view of numbers that live on the server, so a navigation that cannot reach it
// gets the offline page rather than a stale copy of itself. What the cache buys
// is a launch that paints instantly and a globe that does not re-download 2.5
// MB of Earth on a train.
// =============================================================================

/* global __SW_PRECACHE__ */
// ^ Substituted with a JSON array by ServiceWorkerController before this file
//   is ever served. It is a global to ESLint and a literal to the browser.

const VERSION = '__SW_VERSION__'
const PREFIX = 'orbit-'
const CACHE = PREFIX + VERSION

const OFFLINE_URL = '/offline'

/** The current build's shell — see App\Services\Pwa\BuildAssets. */
const PRECACHE = __SW_PRECACHE__

/**
 * Install: fill the cache, then take over immediately.
 *
 * `allSettled`, not `all`: one 404 in the precache list — an icon renamed, a
 * manifest read halfway through a deploy — must not abort the install, because
 * a failed install means NO worker at all, and the app would lose its offline
 * page over a missing PNG. Whatever did land is still worth having.
 *
 * `cache: 'reload'` bypasses the HTTP cache for these fetches. Without it the
 * worker can install a copy of an asset the browser cached before the deploy,
 * which is the one way a content-hashed URL can still go stale.
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
 * Activate: drop every older cache of ours, then claim the open pages.
 *
 * skipWaiting + claim rather than waiting for every tab to close, because
 * "every tab" on a home-screen app means "until the user swipes it away", which
 * can be weeks.
 *
 * Only caches with our prefix are deleted. Nothing else on this origin owns one
 * today, and a blanket delete would eat whatever does tomorrow.
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
 * "Stop waiting and take over" — the page asking, on the user's behalf.
 *
 * ADDITIVE AND, TODAY, UNREACHABLE. `install` above already calls
 * `skipWaiting()`, so this worker never parks in the `waiting` state and nothing
 * ever needs to ask it to leave. It is here because resources/js/lib/pwa.js
 * handles both shapes of the update handshake and this is the half that lives in
 * the worker: the day the install-time skip is reconsidered — it is a real
 * trade-off, and the note on `activate` argues one side of it — the toast keeps
 * working instead of quietly doing nothing.
 *
 * NARROW BY DESIGN. Exactly one message type does exactly one thing; a worker
 * that dispatched on arbitrary payloads from a page would be a second, quieter
 * API surface for the app.
 */
self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        self.skipWaiting()
    }
})

self.addEventListener('fetch', (event) => {
    const request = event.request

    // Writes are never intercepted: adding a route, pausing one, saving alert
    // settings. There is no offline queue in this app and a replayed PATCH would
    // be worse than a failed one.
    if (request.method !== 'GET') return

    const url = new URL(request.url)

    // Anything off-origin.
    if (url.origin !== self.location.origin) return

    // The fares themselves. Every screen's data comes from here, and none of it
    // has a shelf life this worker could judge.
    if (url.pathname.startsWith('/api/')) return

    if (isCacheable(url)) {
        event.respondWith(cacheFirst(request))

        return
    }

    if (request.mode === 'navigate') {
        event.respondWith(navigate(request))
    }

    // Everything else falls through to the network untouched.
})

/**
 * The three families of file whose URL is enough to know they are current.
 *
 * `/build/` is content-hashed, so a changed file is a changed URL by
 * construction and nginx already serves it `immutable`. `/globe/` and `/icons/`
 * are not hashed — they are referenced by literal path — but they are also
 * replaced roughly never, and both are served with a week's max-age; a stale
 * texture is a slightly older photograph of the Earth, which is a cost worth
 * paying to keep 2.5 MB off a phone's data plan.
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

    // 200 only, and same-origin only. A 206 is a fragment, and caching a fragment
    // as if it were the whole file is how a texture ends up decoding to nothing.
    if (response.status === 200 && response.type === 'basic') {
        cache.put(request, response.clone())
    }

    return response
}

/**
 * A page: always the network, and the offline page when there isn't one.
 *
 * Note what this does NOT do — it never serves a cached copy of a real page.
 * The fallback contains no fares, says so, and gets out of the way.
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
