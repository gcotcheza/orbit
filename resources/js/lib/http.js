// =============================================================================
// The one HTTP client
// =============================================================================
// Every request this app makes goes through here, so that "does the server know
// who I am" has exactly one answer rather than one per call site.
//
// COOKIE AUTHENTICATION, NO TOKENS. The session cookie is httpOnly, SameSite
// Lax and Secure — it is not readable from JavaScript, which is the point, and
// `withCredentials` is what makes the browser attach it. There is nothing in
// localStorage to steal because there is nothing in localStorage.
// =============================================================================
import axios from 'axios'

export const http = axios.create({
    // `withXSRFToken` is what makes axios read the XSRF-TOKEN cookie and send
    // it back as X-XSRF-TOKEN. Laravel sets that cookie on EVERY response
    // through the web group, so the token is always the current one — which
    // matters because logging in regenerates the session and its token, and a
    // value captured once at page load would be stale from the first POST
    // after login onwards. That is the whole reason the meta tag in
    // app.blade.php is not read here.
    withCredentials: true,
    withXSRFToken: true,

    headers: {
        // Both of these make Laravel answer with JSON rather than with a
        // redirect or an HTML error page: `Accept` drives expectsJson(), which
        // bootstrap/app.php uses to decide how to render an exception, and
        // X-Requested-With is what ajax() looks at.
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
})

/*
 * =============================================================================
 * The session ending while the app is open
 * =============================================================================
 * Sessions expire, and they do it between requests rather than politely at a
 * navigation: a phone left on the globe screen overnight comes back to a
 * watchlist request that answers 401. Without this the screen renders its
 * "could not be reached" state and the user taps "try again" forever, because
 * the thing that is broken is not the network.
 *
 * ONE PLACE, NOT ONE PER CALL SITE. Every request in this app goes through this
 * client (docs/API.md says so), so this is the only spot where "your session is
 * over" needs handling — and the answer is the same one the router's guard
 * gives to a cold boot: clear the user and go to the login screen, carrying
 * where they were in `?redirect=`.
 *
 * WHY THE IMPORTS ARE DYNAMIC. stores/auth.js imports this file and
 * router/index.js imports that store, so importing either at the top of this
 * one closes the circle — and a circular import does not fail loudly, it
 * quietly hands somebody `undefined` at module-evaluation time. By the moment
 * this handler can possibly run, both modules are loaded and the `import()` is
 * a resolved promise, not a second download.
 *
 * The build says INEFFECTIVE_DYNAMIC_IMPORT about both of these, because they
 * are also imported statically elsewhere and so cannot be split into a chunk of
 * their own. That is expected and is not what they are for: the point is the
 * ORDER modules are evaluated in, not the shape of the bundle.
 *
 * TWO ENDPOINTS ARE EXEMPT, both because a 401 from them is an expected answer
 * rather than an expired session:
 *   - `/api/me` is the boot probe; the store treats a 401 as "guest" and the
 *     router's guard is already mid-decision. Redirecting from here would race
 *     that navigation and lose the `?redirect=` it is building.
 *   - `/login` answers 401 to a `guest` middleware bounce; sending a failed
 *     sign-in back to the sign-in screen would clear the form for no reason.
 */
const SESSION_EXEMPT = ['/api/me', '/login']

http.interceptors.response.use(null, async (error) => {
    const status = error.response?.status
    const url = error.config?.url ?? ''

    if (status === 401 && !SESSION_EXEMPT.includes(url)) {
        const [{ useAuthStore }, { router }] = await Promise.all([
            import('@/stores/auth'),
            import('@/router'),
        ])

        // $patch rather than an action: this is not the app deciding to sign
        // out — it is the app being TOLD it already is, and there is no server
        // call left to make. `resolved` stays true; the answer is known.
        useAuthStore().$patch({ user: null })

        const from = router.currentRoute.value

        if (from.name !== 'login') {
            // replace(), not push(): the page whose session just died has no
            // business in the back stack.
            router.replace({
                name: 'login',
                query: from.fullPath === '/' ? {} : { redirect: from.fullPath },
            })
        }
    }

    // Rejected either way. The call site still gets its error — this handler
    // decides where the USER goes, not what the caller sees.
    return Promise.reject(error)
})

/**
 * Ask the server for a fresh CSRF cookie.
 *
 * Called before signing in. Strictly speaking the shell's own response already
 * carried one, but a login form can sit open for hours — on a phone, in a
 * backgrounded tab, restored from bfcache — and by then the session behind that
 * cookie may have been garbage-collected. This is one cheap round trip that
 * turns "the login button did nothing" (a 419 the user cannot interpret) into a
 * working sign-in.
 */
export function ensureCsrfCookie() {
    return http.get('/sanctum/csrf-cookie')
}
