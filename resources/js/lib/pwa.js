// =============================================================================
// Orbit — installing the service worker
// =============================================================================
// One function, called once from resources/js/app.js after mount. It lives here
// rather than in the entry point because the entry point is the boot sequence
// and this is a side errand: nothing on any screen waits for it, and nothing
// breaks if it never finishes.
//
// WHY NOT AN INLINE <script> IN THE BLADE, which is the usual way to do this:
// deploy/nginx/flights-ghiecode.conf ships `script-src 'self'`, so an inline
// script is exactly what the CSP forbids. Registering from the bundle costs one
// import and keeps that policy intact.
//
// =============================================================================
// AND TELLING SOMEBODY WHEN THE APP THEY ARE LOOKING AT IS NO LONGER THE APP
// =============================================================================
// The owner deployed, went back to the installed app, and saw the old screens
// for hours. Nothing was broken: an installed PWA is a page that was opened once
// and never closed, running the JavaScript it downloaded that morning, and no
// amount of correct caching changes that. The service worker updates itself
// silently in the background — `install` here calls `skipWaiting()` and
// `activate` calls `clients.claim()`, so the NEW worker takes over the page
// immediately — and the page carries on executing the OLD bundle, because a
// bundle that is already parsed and running is not something a worker can
// replace. Only a navigation can.
//
// So the missing piece was never caching. It was a sentence. This file finds out
// that a new version has arrived and offers one tap to take it; `updateReady` is
// the whole public surface, and Components/UpdateToast.vue draws it.
//
// WHAT MAKES THIS APP'S CASE UNUSUAL, and why the code below looks
// belt-and-braces: the textbook pattern waits for `registration.waiting` — a new
// worker parked because the old one still controls the page — and offers to
// SKIP_WAITING it. Orbit's worker does not park: it skips waiting during install
// (see the reasoning in service-worker.js, which is about home-screen apps that
// are never closed). So in practice the new worker is already ACTIVE by the time
// anybody could be told about it, and the honest offer is simply "reload".
// Both paths are handled, because "in practice" is a statement about today's
// service-worker.js and this file should not break the day it changes.
// =============================================================================
import { ref } from 'vue'

let registered = false

/**
 * A build newer than the one this page is running has arrived.
 *
 * A module-level ref and not a Pinia store: it is one boolean, it is written by
 * exactly one file, and the store layer exists for server state that screens
 * share. App.vue reads it.
 */
export const updateReady = ref(false)

/** The registration to ask, once there is one. */
let current = null

/**
 * Has the user asked for the new version? Nothing reloads without this.
 *
 * IT IS THE WHOLE SAFETY OF THE FEATURE. `controllerchange` fires on its own
 * here — the new worker claims the page as soon as it activates, which happens
 * without anybody being asked — so a handler that reloaded on that event would
 * throw the user off whatever they were doing, mid-tour, minutes after a deploy
 * they had no part in. A reload is a thing a person chooses.
 */
let wanted = false

/** And it happens at most once, whatever the browser fires. */
let reloading = false

/**
 * Register /sw.js, once, in production, where the browser supports it.
 *
 * IDEMPOTENT BY A FLAG rather than by trusting the browser. Calling
 * `register()` twice with the same URL and scope is harmless — the browser
 * returns the existing registration — but this function also attaches a `load`
 * listener, and two of those would be two registrations racing on a cold start.
 *
 * PRODUCTION ONLY. Under `npm run dev` the module graph is served unbundled and
 * re-written on every save; a worker caching `/build/` URLs that the dev server
 * does not serve would hand back a stale module graph and make hot reload look
 * broken. `import.meta.env.PROD` is replaced by a literal at build time, so in a
 * development bundle the whole body is dead code the minifier removes.
 *
 * FEATURE DETECTED because `navigator.serviceWorker` is undefined in a private
 * window in some browsers and on any page that is not a secure context — and
 * reading `.register` off undefined would be a TypeError during boot, i.e. a
 * blank screen, for a feature that is decoration.
 */
export function registerServiceWorker() {
    if (registered) return

    if (!import.meta.env.PROD) return

    if (!('serviceWorker' in navigator)) return

    registered = true

    const register = () => {
        navigator.serviceWorker.register('/sw.js').then(watchForUpdate).catch((error) => {
            // Nowhere to report this to, and nothing the user could do about it. The app works
            // without a worker; it just starts colder.
            console.warn('Service worker registration failed', error)
        })
    }

    // AFTER `load`, so the registration's own fetches — the whole precache
    // list — do not compete for bandwidth with the first paint. If the document
    // has already finished loading (a route change, a bfcache restore), there
    // is no second `load` event coming and waiting for one would mean never
    // registering at all.
    if (document.readyState === 'complete') {
        register()
    } else {
        window.addEventListener('load', register, { once: true })
    }
}

/**
 * Watch one registration for a newer build, and say so when there is one.
 *
 * EXPORTED SEPARATELY FROM `registerServiceWorker` so it can be tested. That
 * function is a no-op outside a production bundle by design (see its note), and
 * a test that had to fake `import.meta.env` to reach this logic would be testing
 * the build tool. This takes a registration and is the whole contract.
 *
 * THREE WAYS TO FIND OUT, and the app needs all three:
 *
 *   1. `registration.waiting` at load — a worker that installed while the app
 *      was closed and parked behind the old one. Never happens with today's
 *      service-worker.js, which skips waiting; kept because that is a property
 *      of that file rather than of this one.
 *   2. `updatefound` — the live case. A new worker is downloading right now;
 *      when it reaches `installed` there is a newer build on this device.
 *   3. `update()` on the way back to the screen — the case that started all
 *      this. A phone's home-screen app is opened, backgrounded and reopened for
 *      days without ever navigating, and without a manual check the browser
 *      may not look for a new worker for hours. See `checkOnReturn`.
 *
 * `navigator.serviceWorker.controller` IS THE TEST FOR "NEWER" in cases 1 and 2,
 * and it is not decoration. A first-ever install also reaches `installed`, and
 * announcing a new version to somebody who has just opened the app for the first
 * time would be an offer to reload the page they are still reading. A null
 * controller means nothing was serving this page, i.e. this worker is the first.
 */
export function watchForUpdate(registration) {
    if (!registration) return

    current = registration

    if (registration.waiting && navigator.serviceWorker.controller) {
        updateReady.value = true
    }

    registration.addEventListener('updatefound', () => {
        const arriving = registration.installing

        if (!arriving) return

        arriving.addEventListener('statechange', () => {
            if (arriving.state === 'installed' && navigator.serviceWorker.controller) {
                updateReady.value = true
            }
        })
    })

    // The reload, when and only when it was asked for.
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (!wanted || reloading) return

        reloading = true
        window.location.reload()
    })

    checkOnReturn(registration)
}

/**
 * Ask the browser to look for a new worker whenever the app comes back into view.
 *
 * THIS IS THE PIECE THAT WOULD HAVE CAUGHT THE DEPLOY THE OWNER SAT THROUGH. A
 * registration is checked on navigation and then roughly daily; an installed PWA
 * that is opened and backgrounded twenty times without ever navigating gets none
 * of those. `update()` is a conditional request for /sw.js — a few hundred bytes
 * and a 304 almost always — so asking on the way back in is cheap.
 *
 * THROTTLED, because "visible" fires on every app switch and somebody flicking
 * between Orbit and their messages should not generate a request per flick.
 * Fifteen minutes is far below the interval that matters (a deploy happens at
 * most a few times a day) and far above the interval that would be rude.
 */
const UPDATE_CHECK_MS = 15 * 60 * 1000

function checkOnReturn(registration) {
    let lastChecked = Date.now()

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) return

        if (Date.now() - lastChecked < UPDATE_CHECK_MS) return

        lastChecked = Date.now()

        // Nothing waits on this and nothing recovers from it: a failed check is an offer that is
        // not made yet, and the next one is one app switch away.
        registration.update().catch(() => {})
    })
}

/**
 * Take the new version — the toast's one button.
 *
 * A WAITING WORKER IS ASKED TO STAND DOWN FIRST, and the reload then happens in
 * the `controllerchange` handler above, so the page that comes back is served by
 * the new worker rather than by the one it is replacing. With today's
 * service-worker.js there is no waiting worker (it skips waiting on install), so
 * the new one is already in charge and the reload is immediate — which is why
 * this does not simply post the message and hope.
 */
export function applyUpdate() {
    wanted = true
    updateReady.value = false

    const waiting = current?.waiting

    if (waiting) {
        waiting.postMessage({ type: 'SKIP_WAITING' })

        return
    }

    if (reloading) return

    reloading = true
    window.location.reload()
}

/** "Not now." The offer goes; the next deploy makes it again. */
export function dismissUpdate() {
    updateReady.value = false
}
