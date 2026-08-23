// @vitest-environment jsdom
// The watch list inside the frame: a master pane that chooses which pass leads, and the rules as
// a column rather than a section two screens down (docs/DESKTOP-LAYOUT-PLAN.md phase 2).
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia } from 'pinia'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { computed, ref } from 'vue'

const get = vi.fn()
const del = vi.fn()

/* jsdom has no matchMedia; this is the switch the wide branch is behind. Deferred inside the
   arrow, as vi.mock is hoisted above the const. */
const desktop = ref(false)

vi.mock('@/lib/http', () => ({
    http: {
        get: (...args) => get(...args),
        post: vi.fn(),
        patch: vi.fn().mockResolvedValue({ data: { data: {} } }),
        delete: (...args) => del(...args),
    },
}))
vi.mock('@/lib/layout', () => ({
    useLayout: () => ({
        layout: computed(() => (desktop.value ? 'desktop' : 'phone')),
        isPhone: computed(() => !desktop.value),
        isDesktop: desktop,
        stop: () => {},
    }),
}))

import Watchlist from './Watchlist.vue'

const route = (code, city, price) => ({
    code,
    origin: { iata: code.slice(0, 3), city: 'Amsterdam', country: 'Netherlands', countryCode: 'NL' },
    destination: { iata: code.slice(4), city, country: 'Portugal', countryCode: 'PT' },
    price: { current: price, usual: 111, pctBelow: 32 },
    score: 65,
    tier: 'great',
    confident: true,
    verdict: { label: 'Good price — book', short: 'Good', tone: 'good' },
    sparkline: [65, 68, 71, 74],
    trackingDays: 60,
    active: true,
})

const ROUTES = [route('AMS-LIS', 'Lisbon', 74), route('AMS-OPO', 'Porto', 44), route('AMS-FAO', 'Faro', 34)]

const RULES = [
    {
        id: 7,
        text: 'cheap weekend somewhere sunny',
        chips: [{ label: 'AMS' }, { label: 'Max €80' }],
        matches: { count: 0, cheapest: null, sample: [] },
        active: true,
    },
]

beforeEach(() => {
    vi.clearAllMocks()
    desktop.value = false
    del.mockResolvedValue({})
    get.mockImplementation((url) =>
        url === '/api/watchlist'
            ? Promise.resolve({ data: { data: ROUTES, meta: { count: 3, active: 3 } } })
            : Promise.resolve({ data: { data: RULES } }),
    )
})

async function mountWatchlist() {
    const wrapper = mount(Watchlist, {
        global: { plugins: [createPinia()], stubs: { RouterLink: RouterLinkStub } },
    })

    await flushPromises()

    return wrapper
}

describe('the phone list', () => {
    it('is one column of passes with the rules under them', async () => {
        const wrapper = await mountWatchlist()

        expect(wrapper.get('.screen').classes()).not.toContain('screen--wide')
        expect(wrapper.findAll('.pass')).toHaveLength(3)
        expect(wrapper.find('.route-rows').exists()).toBe(false)
        // Nothing leads on a screen you scroll: no pass is singled out.
        expect(wrapper.find('.is-selected').exists()).toBe(false)
    })

    it('keeps the jump chip that stands in for the rules column', async () => {
        const wrapper = await mountWatchlist()

        expect(wrapper.text()).toContain('Rules · 1')
    })
})

describe('inside the frame', () => {
    beforeEach(() => {
        desktop.value = true
    })

    it('opens on the first route, and gives its pass the lead', async () => {
        const wrapper = await mountWatchlist()

        expect(wrapper.get('.screen').classes()).toContain('screen--wide')

        const rows = wrapper.findAll('.route-row')

        expect(rows.map((row) => row.attributes('data-code'))).toEqual(['AMS-LIS', 'AMS-OPO', 'AMS-FAO'])
        expect(rows[0].classes()).toContain('route-row--active')
        expect(wrapper.findAll('.pass')).toHaveLength(3)
        expect(wrapper.findAll('.is-selected')).toHaveLength(1)
    })

    it('moves the lead to the row that was picked', async () => {
        const wrapper = await mountWatchlist()

        await wrapper.findAll('.route-row')[1].trigger('click')

        expect(wrapper.findAll('.route-row')[1].classes()).toContain('route-row--active')
        expect(wrapper.get('.is-selected').text()).toContain('OPO')
    })

    // The rules are a column here, so the chip that scrolled to them has nothing to do.
    it('draws the deal rules beside the passes rather than below them', async () => {
        const wrapper = await mountWatchlist()

        expect(wrapper.get('.rules__title').text()).toBe('Deal rules')
        expect(wrapper.text()).not.toContain('Rules · 1')
        expect(wrapper.find('.screen__chip').text()).toContain('Search for a route')
    })

    it('still removes a route, and still offers the undo', async () => {
        const wrapper = await mountWatchlist()

        await wrapper.findAll('.stub__remove')[0].trigger('click')
        await wrapper.get('.confirm__button--go').trigger('click')
        await flushPromises()

        expect(del).toHaveBeenCalledWith('/api/watchlist/AMS-LIS')
        expect(wrapper.get('.screen__notice--undo').text()).toContain('Stopped watching AMS→LIS')
        // The pane cannot be left pointing at a route that is gone.
        expect(wrapper.get('.is-selected').text()).toContain('OPO')
    })

    it('pauses a route from its own switch', async () => {
        const wrapper = await mountWatchlist()

        await wrapper.findAll('.switch')[0].trigger('click')
        await flushPromises()

        expect(wrapper.findAll('.route-row')[0].classes()).toContain('route-row--paused')
    })
})
