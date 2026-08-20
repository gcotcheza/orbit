// Installing the service worker, and telling somebody when the app they are looking at is no
// longer the app. `updateReady` is the whole public surface (docs/BUSINESS-LOGIC.md §35).
import { ref } from 'vue'

let registered = false

/**
 * A build newer than the one this page is running has arrived. A module-level ref and not a
 * Pinia store: one boolean, written by one file, read by App.vue.
 */
export const updateReady = ref(false)

/** The registration to ask, once there is one. */
let current = null

/**
 * Has the user asked for the new version? Nothing reloads without this — `controllerchange`
 * fires on its own, and reloading on it would throw the user off mid-tour.
 */
let wanted = false

/** And it happens at most once, whatever the browser fires. */
let reloading = false

/**
 * Register /sw.js, once, in production, where the browser supports it — idempotent by a flag
 * (the `load` listener must not double up), and feature detected before it is read.
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

    // AFTER `load`, so the precache list does not compete with the first paint. If the document
    // has already loaded there is no second `load` event, and waiting means never.
    if (document.readyState === 'complete') {
        register()
    } else {
        window.addEventListener('load', register, { once: true })
    }
}

/**
 * Watch one registration for a newer build: `waiting`, `updatefound`, and `update()` on the
 * way back in. A null `controller` means a first install, not a new version.
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
 * Ask the browser to look for a new worker whenever the app comes back into view — the piece
 * a home-screen app needs, throttled because "visible" fires on every app switch.
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
 * Take the new version — the toast's one button. A waiting worker is asked to stand down first,
 * so the page that comes back is served by the new worker rather than the old one.
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
