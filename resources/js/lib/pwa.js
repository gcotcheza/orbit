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
// =============================================================================

let registered = false

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
        navigator.serviceWorker.register('/sw.js').catch((error) => {
            // Nowhere to report this to, and nothing the user could do about
            // it. The app works without a worker; it just starts colder.
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
