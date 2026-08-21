// One user, one session, one source of truth. `resolved` matters: cold boot means "don't know
// yet", a third state distinct from guest (docs/BUSINESS-LOGIC.md §36).
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
            // Non-401 errors (500, dropped connection, mid-deploy) read as "not signed in" so the
            // app still draws, but are logged: silent failures read as phantom logout bugs.
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
     * Change the password of the account that is signed in. Here, not in the component, and it
     * throws like `login`; nothing in this store changes on success (docs/BUSINESS-LOGIC.md §36).
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
