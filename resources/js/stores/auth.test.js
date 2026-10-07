// @vitest-environment jsdom
// Signing out: the client lets go of the session only once the server has (docs/BUSINESS-LOGIC.md §36).
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const calls = []
const ensureCsrfCookie = vi.fn()
const post = vi.fn()

vi.mock('@/lib/http', () => ({
    ensureCsrfCookie: (...args) => ensureCsrfCookie(...args),
    http: { post: (...args) => post(...args) },
}))

import { useAuthStore } from './auth'

const OWNER = { name: 'Owner', email: 'owner@example.test' }

function signedIn() {
    const auth = useAuthStore()

    auth.$patch({ user: { ...OWNER }, resolved: true })

    return auth
}

beforeEach(() => {
    setActivePinia(createPinia())
    calls.length = 0
    ensureCsrfCookie.mockReset().mockImplementation(async () => calls.push('csrf'))
    post.mockReset().mockImplementation(async (url) => calls.push(url))
})

describe('logout', () => {
    it('refreshes the CSRF cookie before it posts', async () => {
        await signedIn().logout()

        expect(calls).toEqual(['csrf', '/logout'])
    })

    it('forgets the user when the server signs out', async () => {
        const auth = signedIn()

        await auth.logout()

        expect(auth.user).toBeNull()
        expect(auth.isAuthenticated).toBe(false)
    })

    it('forgets the user when the server says the session was already over', async () => {
        post.mockRejectedValue({ response: { status: 401 } })

        const auth = signedIn()

        await auth.logout()

        expect(auth.user).toBeNull()
    })

    it('keeps the user, and throws, when the sign-out did not reach the server', async () => {
        const offline = new Error('Network Error')

        post.mockRejectedValue(offline)

        const auth = signedIn()

        await expect(auth.logout()).rejects.toBe(offline)
        expect(auth.user).toEqual(OWNER)
    })

    it('keeps the user, and throws, on a stale-page 419', async () => {
        post.mockRejectedValue({ response: { status: 419 } })

        const auth = signedIn()

        await expect(auth.logout()).rejects.toEqual({ response: { status: 419 } })
        expect(auth.isAuthenticated).toBe(true)
    })
})
