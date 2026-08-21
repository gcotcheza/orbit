// @vitest-environment jsdom
// The home screen's four states — loading, failure, empty, and that the
// spotlight, rail and tour agree on THE SAME ROUTE (docs/BUSINESS-LOGIC.md §36).
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia } from 'pinia'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
let webgl = true

vi.mock('@/lib/http', () => ({ http: { get: (...args) => get(...args) } }))
vi.mock('@/Components/globe/globeScene', () => ({
    hasWebgl: () => webgl,
    createGlobeScene: () => Promise.reject(new Error('not in this test')),
}))

import Home from './Home.vue'

const GlobeStageStub = {
    name: 'GlobeStage',
    props: ['routes', 'activeCode'],
    emits: ['advance', 'unavailable'],
    template: '<div class="globe-stub"></div>',
}

const LIS = {
    code: 'AMS-LIS',
    origin: { iata: 'AMS', city: 'Amsterdam', country: 'Netherlands', countryCode: 'NL', lat: 52.3105, lng: 4.7683 },
    destination: { iata: 'LIS', city: 'Lisbon', country: 'Portugal', countryCode: 'PT', lat: 38.7742, lng: -9.1342 },
    price: { current: 74, usual: 111, pctBelow: 33 },
    score: 65,
    tier: 'great',
    confident: true,
    verdict: { label: 'Good price — book', short: 'Good', tone: 'good' },
    sparkline: [65, 68, 71, 74],
    trackingDays: 60,
    cheapest: { date: '2026-09-09', price: 74 },
    active: true,
}

const OPO = {
    ...LIS,
    code: 'AMS-OPO',
    destination: { iata: 'OPO', city: 'Porto', country: 'Portugal', countryCode: 'PT', lat: 41.2481, lng: -8.6814 },
    price: { current: 44, usual: 93, pctBelow: 53 },
    verdict: { label: 'Cheap & still falling', short: 'Falling', tone: 'info' },
}

const PAUSED = { ...LIS, code: 'AMS-BCN', destination: { ...LIS.destination, iata: 'BCN', city: 'Barcelona' }, active: false }

const BRAND_NEW = {
    ...LIS,
    code: 'AMS-KEF',
    destination: { ...LIS.destination, iata: 'KEF', city: 'Reykjavík', country: 'Iceland' },
    price: { current: null, usual: null, pctBelow: null },
    score: 0,
    tier: 'none',
    confident: false,
    // `New`, not `Normal`: a route Orbit has no opinion about must not wear the
    // same word as one it has judged and found unremarkable (docs/API.md).
    verdict: { label: 'Not enough data yet', short: 'New', tone: 'normal' },
    sparkline: [],
    trackingDays: 3,
    // No poll, no fare, and therefore no date to put on one.
    cheapest: null,
}

const watchlist = (data) => ({ data: { data, meta: { count: data.length, active: data.filter((r) => r.active).length } } })

async function mountHome(data = [LIS, OPO]) {
    get.mockResolvedValue(watchlist(data))

    const wrapper = mount(Home, {
        global: { plugins: [createPinia()], stubs: { RouterLink: RouterLinkStub, GlobeStage: GlobeStageStub } },
    })

    await flushPromises()

    return wrapper
}

const stage = (wrapper) => wrapper.findComponent(GlobeStageStub)

beforeEach(() => {
    webgl = true
    vi.clearAllMocks()
})

describe('loading', () => {
    it('holds the screen still until the watchlist arrives', async () => {
        get.mockReturnValue(new Promise(() => {}))

        const wrapper = mount(Home, {
            global: { plugins: [createPinia()], stubs: { RouterLink: RouterLinkStub, GlobeStage: GlobeStageStub } },
        })

        expect(wrapper.find('.home__skeleton').exists()).toBe(true)
        expect(stage(wrapper).exists()).toBe(false)
        // The header is not part of the wait: it says something true before any
        // request has been made.
        expect(wrapper.text()).toMatch(/Good (morning|afternoon|evening)/)
    })

    it('asks for the whole watchlist exactly once', async () => {
        await mountHome()

        expect(get).toHaveBeenCalledTimes(1)
        expect(get).toHaveBeenCalledWith('/api/watchlist')
    })
})

describe('the tour', () => {
    it('flies the active routes only, and says so on the chip', async () => {
        const wrapper = await mountHome([LIS, PAUSED, OPO])

        expect(stage(wrapper).props('routes').map((route) => route.code)).toEqual(['AMS-LIS', 'AMS-OPO'])
        expect(wrapper.text()).toContain('2 watched')
        expect(wrapper.text()).not.toContain('Barcelona')
    })

    it('starts on the first route and shows it in the spotlight', async () => {
        const wrapper = await mountHome()

        expect(stage(wrapper).props('activeCode')).toBe('AMS-LIS')
        expect(wrapper.text()).toContain('Lisbon')
        expect(wrapper.text()).toContain('€74')
        expect(wrapper.text()).toContain('33% below usual')
        expect(wrapper.text()).toContain('Good price — book')
        // The DAY the €74 is for. A fare with no date on it is not something
        // anybody can act on, and this card printed one for months.
        expect(wrapper.find('.spotlight__when').text()).toBe('Wed, Sep 9')
    })

    it('moves the whole screen on when the globe has finished a route', async () => {
        const wrapper = await mountHome()

        await stage(wrapper).vm.$emit('advance')

        expect(stage(wrapper).props('activeCode')).toBe('AMS-OPO')
        expect(wrapper.text()).toContain('Porto')
        expect(wrapper.text()).toContain('€44')

        // …and wraps back round to the start.
        await stage(wrapper).vm.$emit('advance')
        expect(stage(wrapper).props('activeCode')).toBe('AMS-LIS')
    })

    it('flies where the rail is tapped', async () => {
        const wrapper = await mountHome()

        await wrapper.findAll('.rail__chip')[1].trigger('click')

        expect(stage(wrapper).props('activeCode')).toBe('AMS-OPO')
        expect(wrapper.find('.rail__chip--active').text()).toContain('AMS→OPO')
    })

    it('opens the route detail from the spotlight card', async () => {
        const wrapper = await mountHome()

        const spotlight = wrapper
            .findAllComponents(RouterLinkStub)
            .find((link) => link.classes().includes('spotlight'))

        expect(spotlight.props('to')).toEqual({ name: 'route-detail', params: { id: 'AMS-LIS' } })
    })
})

describe('a route we know nothing about yet', () => {
    it('says what it is actually watching rather than inventing a fare', async () => {
        const wrapper = await mountHome([BRAND_NEW])

        expect(wrapper.text()).toContain('No fare yet')
        expect(wrapper.text()).toContain('tracking 3 days')
        expect(wrapper.text()).toContain('Not enough data yet')
        // docs/API.md: null is "not known yet", never zero.
        expect(wrapper.text()).not.toContain('€0')
        expect(wrapper.text()).not.toContain('0% below usual')
        expect(wrapper.find('.spark').exists()).toBe(false)
        // And no departure date either: `cheapest: null` is not today.
        expect(wrapper.find('.spotlight__when').exists()).toBe(false)
    })
})

describe('nothing to show', () => {
    // Day one is still this app's screen (docs/BUSINESS-LOGIC.md §36).
    it('draws an empty globe and one way out of the empty state', async () => {
        const wrapper = await mountHome([PAUSED])

        expect(wrapper.text()).toContain('Nothing orbiting yet')

        expect(stage(wrapper).exists()).toBe(true)
        expect(stage(wrapper).props('routes')).toEqual([])
        expect(wrapper.text()).not.toContain('Barcelona')

        const cta = wrapper.findAllComponents(RouterLinkStub).find((link) => link.classes().includes('home__cta'))

        expect(cta.text()).toBe('Add your first route')
        expect(cta.props('to')).toEqual({ name: 'watch' })
    })

    it('drops the globe from the empty state too when there is no GPU', async () => {
        webgl = false

        const wrapper = await mountHome([PAUSED])

        expect(wrapper.text()).toContain('Nothing orbiting yet')
        expect(stage(wrapper).exists()).toBe(false)
    })

    it('offers another go when the watchlist could not be reached', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {})
        get.mockRejectedValueOnce(new Error('offline'))

        const wrapper = mount(Home, {
            global: { plugins: [createPinia()], stubs: { RouterLink: RouterLinkStub, GlobeStage: GlobeStageStub } },
        })
        await flushPromises()

        expect(wrapper.text()).toContain('could not be reached')

        get.mockResolvedValue(watchlist([LIS]))
        await wrapper.find('.home__retry').trigger('click')
        await flushPromises()

        expect(stage(wrapper).exists()).toBe(true)
        expect(wrapper.text()).toContain('Lisbon')
    })
})

describe('without a GPU', () => {
    it('draws the watchlist as cards instead of a globe', async () => {
        webgl = false

        const wrapper = await mountHome([LIS, OPO, PAUSED])

        expect(stage(wrapper).exists()).toBe(false)
        // Every active route, each one a card of its own — and the paused one
        // still left out of it.
        expect(wrapper.findAll('.spotlight')).toHaveLength(2)
        expect(wrapper.text()).toContain('Lisbon')
        expect(wrapper.text()).toContain('Porto')
        expect(wrapper.text()).not.toContain('Barcelona')
    })

    it('falls back when the globe gives up after it has started', async () => {
        const wrapper = await mountHome()

        expect(wrapper.findAll('.spotlight')).toHaveLength(1)

        await stage(wrapper).vm.$emit('unavailable')

        expect(stage(wrapper).exists()).toBe(false)
        expect(wrapper.findAll('.spotlight')).toHaveLength(2)
    })
})
