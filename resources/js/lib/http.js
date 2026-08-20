// Every request goes through here, so "does the server know who I am" has one
// answer. Cookie auth only, no tokens: the session cookie is httpOnly and
// unreadable from JS, so there is nothing in localStorage to steal.
// Why: docs/BUSINESS-LOGIC.md §36.
import axios from 'axios'

export const http = axios.create({
    // withXSRFToken re-reads the XSRF-TOKEN cookie per-request (login rotates it); a meta tag captured once at page load would go stale.
    // Why: docs/BUSINESS-LOGIC.md §36.
    withCredentials: true,
    withXSRFToken: true,

    headers: {
        // Both make Laravel answer JSON instead of a redirect/HTML error page: Accept drives expectsJson(), X-Requested-With is what ajax() checks.
        // Why: docs/BUSINESS-LOGIC.md §36.
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
})

/*
 * DO NOT make these imports static (circular with stores/auth.js -> router,
 * which fails silently as `undefined`, not loudly). This interceptor is the
 * one place a dead session redirects to login; `/api/me` and `/login` are
 * exempt since a 401 from either is expected, not an expired session.
 * Why: docs/BUSINESS-LOGIC.md §36.
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

        // $patch, not an action: the app is being TOLD it's signed out already, not deciding to be — `resolved` stays true since the answer is already known.
        // Why: docs/BUSINESS-LOGIC.md §36.
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
 * Called before signing in: a login form left open for hours can outlive the shell's original cookie, turning an uninterpretable 419 into a working sign-in.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
export function ensureCsrfCookie() {
    return http.get('/sanctum/csrf-cookie')
}
