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
