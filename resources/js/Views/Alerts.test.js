// @vitest-environment jsdom
// The alerts screen inside the frame: a section list beside two columns of cards, and the
// `#account` link that still has to land on one of them (docs/DESKTOP-LAYOUT-PLAN.md phase 3).
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { computed, ref } from 'vue'

const get = vi.fn()
const put = vi.fn()

vi.mock('@/lib/http', () => ({
    http: {
        get: (...args) => get(...args),
        put: (...args) => put(...args),
        post: vi.fn(),
    },
}))

/* jsdom has no matchMedia, so the real composable would answer 'phone' and nothing else; this is
   the switch the wide branch is behind. Deferred inside the arrow, as vi.mock is hoisted. */
const desktop = ref(false)

vi.mock('@/lib/layout', () => ({
    useLayout: () => ({
        layout: computed(() => (desktop.value ? 'desktop' : 'phone')),
        isPhone: computed(() => !desktop.value),
        isDesktop: desktop,
        stop: () => {},
    }),
}))

const hash = ref('')

vi.mock('vue-router', () => ({
    useRoute: () => ({ get hash() { return hash.value } }),
    useRouter: () => ({ push: vi.fn() }),
}))

import Alerts from './Alerts.vue'
import { useAuthStore } from '@/stores/auth'

/** `GET /api/settings`, in the shape docs/API.md sends. */
const SETTINGS = {
    data: {
        emailAlerts: true,
        pushAlerts: false,
        sensitivity: 'balanced',
        quietHours: true,
        quietStart: '22:00',
        quietEnd: '08:00',
        weeklyDigest: true,
    },
    meta: {
        sensitivities: [
            { level: 'relaxed', name: 'Relaxed', minimumScore: 75, blurb: 'Only the rare ones.' },
            { level: 'balanced', name: 'Balanced', minimumScore: 65, blurb: 'A handful a month.' },
            { level: 'eager', name: 'Eager', minimumScore: 55, blurb: 'Tell me about everything.' },
        ],
    },
}

let pinia

async function screen() {
    const wrapper = mount(Alerts, { global: { plugins: [pinia] } })

    useAuthStore().user = { name: 'E2E', email: 'e2e@orbit.test' }

    await flushPromises()

    return wrapper
}

const rows = (wrapper) => wrapper.findAll('.seclist__item')

const active = (wrapper) => rows(wrapper).find((one) => one.classes().includes('seclist__item--active'))

beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.clearAllMocks()
    desktop.value = false
    hash.value = ''
    get.mockResolvedValue({ data: SETTINGS })
    put.mockResolvedValue({ data: SETTINGS })
})

describe('inside the frame', () => {
    beforeEach(() => {
        desktop.value = true
    })

    it('puts the five sections in the master and every card in the pane', async () => {
        const wrapper = await screen()

        expect(wrapper.get('.screen').classes()).toContain('screen--wide')
        expect(rows(wrapper).map((one) => one.text())).toEqual([
            'Channels',
            'Sensitivity',
            'Timing',
            'Account',
            'This app',
        ])

        // Every card is in the pane; the master carries navigation and nothing else.
        expect(wrapper.findAll('.screen__pane .card')).toHaveLength(5)
        expect(wrapper.find('.screen__master .card').exists()).toBe(false)
    })

    it('places the artboard’s two columns without reordering the phone’s reading', async () => {
        const wrapper = await screen()

        // The DOM order is the phone's; the columns are a placement on top of it.
        expect(wrapper.findAll('.set').map((one) => one.attributes('class'))).toEqual([
            'set set--channels',
            'set set--sensitivity',
            'set set--timing',
            'set set--account',
            'set set--app',
        ])

        expect(wrapper.findAll('.section').map((one) => one.attributes('id'))).toEqual([
            'channels',
            'sensitivity',
            'timing',
            'account',
            'this-app',
        ])
    })

    it('lights the section that was clicked, and leaves the first lit until then', async () => {
        const wrapper = await screen()

        expect(active(wrapper).text()).toBe('Channels')

        await rows(wrapper)[2].trigger('click')

        expect(active(wrapper).text()).toBe('Timing')
        expect(rows(wrapper)[2].attributes('aria-current')).toBe('true')
        expect(rows(wrapper)[0].attributes('aria-current')).toBeUndefined()
    })

    it('follows the account link there rather than to the top of the screen', async () => {
        hash.value = '#account'

        const wrapper = await screen()

        expect(active(wrapper).text()).toBe('Account')
        expect(wrapper.get('#account').text()).toBe('Account')
    })

    it('lists only the sections that are on the page while the settings are still coming', async () => {
        get.mockImplementation(() => new Promise(() => {}))

        const wrapper = mount(Alerts, { global: { plugins: [pinia] } })

        await flushPromises()

        expect(rows(wrapper).map((one) => one.text())).toEqual(['Account', 'This app'])
        expect(wrapper.get('.screen__state').text()).toContain('Loading your settings…')
    })

    it('still saves a setting from the pane', async () => {
        const wrapper = await screen()

        await wrapper.get('.set--channels [role="switch"]').trigger('click')
        await flushPromises()

        expect(put).toHaveBeenCalledWith('/api/settings', expect.objectContaining({ emailAlerts: false }))
    })
})

describe('on a phone', () => {
    it('draws no section list at all, and the same cards it always did', async () => {
        const wrapper = await screen()

        expect(wrapper.get('.screen').classes()).not.toContain('screen--wide')
        expect(wrapper.find('.seclist').exists()).toBe(false)
        expect(wrapper.findAll('.card')).toHaveLength(5)
        expect(wrapper.get('#account').text()).toBe('Account')
    })
})
