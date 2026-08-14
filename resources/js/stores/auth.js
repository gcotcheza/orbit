// =============================================================================
// Who is signed in
// =============================================================================
// One user, one session, one source of truth. The router's guard reads this,
// the login screen writes it, and nothing else in the app needs to know how
// authentication works.
//
// `resolved` is the flag that matters: on a cold boot the client does not yet
// KNOW whether it is signed in, and that third state is not the same as being a
// guest. Routing on it before /api/me has answered would flash the login screen
// at somebody who is already signed in, on every single launch.
// =============================================================================
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
            // Anything that is NOT a 401 — a 500, a dropped connection, a
            // deploy mid-request — is treated as "not signed in" so the app
            // still draws something, but it is reported: silently showing the
            // login screen to somebody whose session is fine is the kind of
            // fault that gets described as "it logged me out again" and never
            // reproduced.
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
     * Sign in. Throws on a bad password (422) or a tripped throttle (429) —
     * the login screen renders the message rather than the store swallowing it.
     */
    async function login(credentials) {
        await ensureCsrfCookie()

        const { data } = await http.post('/login', credentials)

        user.value = data.data
        resolved.value = true
    }

    async function logout() {
        try {
            await http.post('/logout')
        } finally {
            // Whatever the server said, this client is done with the session.
            // A logout that fails and leaves the app looking signed in is worse
            // than one that fails quietly.
            user.value = null
        }
    }

    return { user, resolved, isAuthenticated, check, ready, login, logout }
})
