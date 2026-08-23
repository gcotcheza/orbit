// @vitest-environment jsdom
// The deal rules section, now that two screens draw it: the watch list's and the create screen's
// master pane (docs/DESKTOP-LAYOUT-PLAN.md phase 3).
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const patch = vi.fn()
const post = vi.fn()

vi.mock('@/lib/http', () => ({
    http: {
        get: (...args) => get(...args),
        patch: (...args) => patch(...args),
        post: (...args) => post(...args),
    },
}))

import DealRules from './DealRules.vue'
import { useWatchlistStore } from '@/stores/watchlist'

/** One `GET /api/rules` row, in the shape docs/API.md sends. */
const rule = (overrides = {}) => ({
    id: 7,
    text: 'cheap weekend somewhere sunny, under €80',
    active: true,
    chips: [{ id: 'max_price', category: 'Max price', label: '€80' }],
    matches: { count: 0, cheapest: null, sample: [] },
    ...overrides,
})

/** One pinia for the component and the test, so both read the same watch list store. */
let pinia

const draw = (props = {}) => mount(DealRules, {
    props,
    global: { plugins: [pinia], stubs: { RouterLink: RouterLinkStub } },
})

async function section(rules = [rule()], props = {}) {
    get.mockResolvedValue({ data: { data: rules } })

    const wrapper = draw(props)

    await flushPromises()

    return wrapper
}

beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.clearAllMocks()
})

describe('the deal rules section', () => {
    it('loads the rules itself, so neither screen has to', async () => {
        const wrapper = await section()

        expect(get).toHaveBeenCalledWith('/api/rules')
        expect(wrapper.findAll('.rule')).toHaveLength(1)
        expect(wrapper.get('.rules__title').text()).toBe('Deal rules')
    })

    // It is the only door to /create, so hiding it when empty would hide the way in.
    it('is drawn with its + even when there is nothing on it', async () => {
        const wrapper = await section([])

        expect(wrapper.get('.rules__empty').text()).toContain('plain English')
        expect(wrapper.get('.rules__new').text()).toContain('New rule')
    })

    it('offers a retry when the list could not be loaded', async () => {
        get.mockRejectedValue(new Error('nope'))

        const wrapper = draw()

        await flushPromises()

        expect(wrapper.get('.screen__state').text()).toContain('Could not load your rules.')

        get.mockResolvedValue({ data: { data: [rule()] } })
        await wrapper.get('.screen__retry').trigger('click')
        await flushPromises()

        expect(wrapper.findAll('.rule')).toHaveLength(1)
    })

    it('pauses a rule from its own switch and holds it inert while the write is out', async () => {
        let land
        patch.mockImplementationOnce(() => new Promise((resolve) => { land = resolve }))

        const wrapper = await section()

        await wrapper.get('.rule [role="switch"]').trigger('click')

        expect(patch).toHaveBeenCalledWith('/api/rules/7', { active: false })
        expect(wrapper.get('.rule').classes()).toContain('rule--paused')

        land({ data: { data: rule({ active: false }) } })
        await flushPromises()
    })

    // The create screen answers for the store's `error` under the box that was refused, so the
    // section beside it must not say the same sentence twice.
    it('can be told to leave the notice to the screen that mounted it', async () => {
        get.mockRejectedValue(new Error('nope'))

        const quiet = draw({ notice: false })

        await flushPromises()

        expect(quiet.find('.screen__notice').exists()).toBe(false)
        // The state is still drawn — it is about the list, not about the write that failed.
        expect(quiet.get('.screen__state').text()).toContain('Could not load your rules.')
    })

    it('adds a promoted match to the watch list store the watch screen reads', async () => {
        const match = {
            code: 'AMS-AGP',
            origin: { iata: 'AMS', city: 'Amsterdam' },
            destination: { iata: 'AGP', city: 'Málaga' },
            cheapest: { date: '2026-10-24', price: 34 },
            watched: false,
        }

        const wrapper = await section([rule({ matches: { count: 1, cheapest: 34, sample: [match] } })])

        post.mockResolvedValue({ data: { data: { code: 'AMS-AGP', active: true } } })

        await wrapper.get('.rule__open').trigger('click')
        await wrapper.get('.match__watch').trigger('click')
        await flushPromises()

        expect(post).toHaveBeenCalledWith('/api/watchlist', { origin: 'AMS', destination: 'AGP' })
        expect(useWatchlistStore().routes).toContainEqual({ code: 'AMS-AGP', active: true })
    })
})
