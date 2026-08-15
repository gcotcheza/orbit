// @vitest-environment jsdom
// =============================================================================
// The change-password form
// =============================================================================
// The endpoint has its own suite (tests/Feature/PasswordChangeTest); what is
// under test here is the half no PHP test can see — that the sentences the 422
// carries reach the box they belong to, that a success collapses the form and
// leaves nothing typed behind, and that the button cannot be pressed twice into
// two requests.
//
// `PUT /api/profile/password` is stubbed with the shapes docs/API.md publishes,
// including the rejections: a form's behaviour on the unhappy path IS the
// feature here.
// =============================================================================
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const put = vi.fn()

vi.mock('@/lib/http', () => ({ http: { put: (...args) => put(...args) } }))

import ChangePassword from './ChangePassword.vue'

/** A 422 as Laravel sends it, and as axios throws it. */
function rejection(status, errors = undefined) {
    return Object.assign(new Error('Request failed'), {
        response: { status, data: errors ? { errors } : {} },
    })
}

async function form() {
    const wrapper = mount(ChangePassword, { global: { plugins: [createPinia()] } })

    await wrapper.get('button[aria-expanded]').trigger('click')

    return wrapper
}

/** Fill all three boxes and submit. */
async function submit(wrapper, current = 'the-old-one', next = 'a-long-new-password') {
    await wrapper.get('input[name="current_password"]').setValue(current)
    await wrapper.get('input[name="password"]').setValue(next)
    await wrapper.get('input[name="password_confirmation"]').setValue(next)

    await wrapper.get('form').trigger('submit')
    await flushPromises()
}

describe('ChangePassword', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
        put.mockReset()
        put.mockResolvedValue({ data: { data: { changed: true } } })
    })

    it('stays collapsed until it is asked for', async () => {
        const wrapper = mount(ChangePassword, { global: { plugins: [createPinia()] } })

        expect(wrapper.find('form').exists()).toBe(false)

        await wrapper.get('button[aria-expanded]').trigger('click')

        expect(wrapper.find('form').exists()).toBe(true)
        expect(wrapper.findAll('input[type="password"]')).toHaveLength(3)
    })

    it('sends the three fields under the names the server validates', async () => {
        const wrapper = await form()

        await submit(wrapper, 'currently', 'twelve-plus-characters')

        expect(put).toHaveBeenCalledWith('/api/profile/password', {
            current_password: 'currently',
            password: 'twelve-plus-characters',
            password_confirmation: 'twelve-plus-characters',
        })
    })

    it('collapses, says so and leaves nothing typed behind on success', async () => {
        const wrapper = await form()

        await submit(wrapper)

        expect(wrapper.find('form').exists()).toBe(false)
        expect(wrapper.text()).toContain('Password changed')

        // Reopening gets empty boxes, not the previous attempt.
        await wrapper.get('button[aria-expanded]').trigger('click')

        expect(wrapper.findAll('input[type="password"]').map((input) => input.element.value)).toEqual(['', '', ''])
    })

    it('puts the server sentence under the field it is about', async () => {
        put.mockRejectedValue(rejection(422, {
            current_password: ['That is not your current password.'],
        }))

        const wrapper = await form()

        await submit(wrapper)

        // Still open, with the field's own message beneath it.
        expect(wrapper.find('form').exists()).toBe(true)
        expect(wrapper.text()).toContain('That is not your current password.')
        expect(wrapper.get('input[name="current_password"]').attributes('aria-invalid')).toBe('true')
    })

    it('reports a tripped throttle as its own line rather than as a field', async () => {
        put.mockRejectedValue(rejection(429))

        const wrapper = await form()

        await submit(wrapper)

        expect(wrapper.get('[role="alert"]').text()).toContain('Too many attempts')
    })

    it('says the request never left when there is no response at all', async () => {
        put.mockRejectedValue(new Error('Network Error'))

        const wrapper = await form()

        await submit(wrapper)

        expect(wrapper.get('[role="alert"]').text()).toContain('Could not reach Orbit')
    })

    it('cannot be submitted twice into two requests', async () => {
        let release
        put.mockReturnValue(new Promise((resolve) => { release = resolve }))

        const wrapper = await form()

        await submit(wrapper)

        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()

        await wrapper.get('form').trigger('submit')
        await flushPromises()

        expect(put).toHaveBeenCalledTimes(1)

        release({ data: { data: { changed: true } } })
        await flushPromises()
    })

    it('forgets a half-typed attempt when the form is cancelled', async () => {
        const wrapper = await form()

        await wrapper.get('input[name="password"]').setValue('half-typed-secret')
        await wrapper.get('button[aria-expanded]').trigger('click')
        await wrapper.get('button[aria-expanded]').trigger('click')

        expect(wrapper.get('input[name="password"]').element.value).toBe('')
    })
})
