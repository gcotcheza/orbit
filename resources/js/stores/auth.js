// One user, one session, one source of truth: router guard reads it, login
// screen writes it, nothing else needs to know how auth works.
// Why: docs/BUSINESS-LOGIC.md §36.
//
// `resolved` matters: cold boot means "don't know yet", a third state
// distinct from guest. Routing before /api/me answers would flash the
// login screen at an already-signed-in user on every launch.
// Why: docs/BUSINESS-LOGIC.md §36.
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { ensureCsrfCookie, http } from '@/lib/http'

export const useAuthStore = defineStore('auth', () => {
    const user = ref(null)
    const resolved = ref(false)

    const isAuthenticated = computed(() => user.value !== null)

    /**
     * Ask the server. A 401 is an answer, not a failure.
     */
    async function check() {
        try {
            const { data } = await http.get('/api/me')
            user.value = data.data
        } catch (error) {
            // Non-401 errors (500, dropped connection, mid-deploy) are
            // treated as "not signed in" so the app still draws, but logged —
            // silent failures here read as phantom "logged me out" bugs.
            // Why: docs/BUSINESS-LOGIC.md §36.
            if (error.response?.status !== 401) {
                console.error('Could not determine the session state.', error)
            }

            user.value = null
        } finally {
            resolved.value = true
        }
    }

    /**
     * Resolve the session exactly once per page load.
     */
    async function ready() {
        if (!resolved.value) {
            await check()
        }
    }

    /**
     * Sign in. Throws on a bad password (422) or a tripped throttle (429) — the login screen
     * renders the message rather than the store swallowing it.
     */
    async function login(credentials) {
        await ensureCsrfCookie()

        const { data } = await http.post('/login', credentials)

        user.value = data.data
        resolved.value = true
    }

    /**
     * Change the password of the account that is signed in.
     *
     * Here, not in the component: this is the third thing that knows how auth works, and the other two are in this file —
     * ChangePassword.vue shouldn't need to know a URL or a session either (docs/BUSINESS-LOGIC.md §36).
     *
     * Throws, like `login`: the 422 is a per-rule sentence written by UpdatePasswordRequest for a person to read, not a
     * boolean to swallow (docs/BUSINESS-LOGIC.md §36).
     *
     * Nothing in this store changes on success: session is rotated server-side via cookies; `user` (who's signed in)
     * doesn't change (docs/BUSINESS-LOGIC.md §36).
     *
     * No `ensureCsrfCookie()` — unlike `login`, the caller already holds a
     * current token from making authenticated requests.
     */
    async function changePassword(fields) {
        await http.put('/api/profile/password', fields)
    }

    async function logout() {
        try {
            await http.post('/logout')
        } finally {
            // Client clears its session regardless of server response — a logout that fails and
            // still looks signed in is worse (docs/BUSINESS-LOGIC.md §36).
            user.value = null
        }
    }

    return { user, resolved, isAuthenticated, check, ready, login, changePassword, logout }
})
