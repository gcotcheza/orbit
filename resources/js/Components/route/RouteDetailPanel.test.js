// @vitest-environment jsdom
// The panel on its own terms — the desktop pane holds it by a `code` prop and swaps that prop
// rather than navigating (Views/RouteDetail.test.js covers the screen around it).
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()

vi.mock('@/lib/http', () => ({ http: { get: (...args) => get(...args), post: vi.fn() } }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn(), back: vi.fn() }) }))

import RouteDetailPanel from './RouteDetailPanel.vue'

const document_ = (code, city, price) => ({
    data: {
        data: {
            code,
            origin: { iata: code.slice(0, 3), city: 'Amsterdam', country: 'Netherlands' },
            destination: { iata: code.slice(4), city, country: 'Portugal' },
            price: { current: price, usual: 111, pctBelow: 32 },
            score: 65,
            tier: 'great',
            confident: true,
            verdict: { label: 'Good price — book', short: 'Good', tone: 'good' },
            trackingDays: 60,
            cheapest: { date: '2026-09-09', price, foundAt: null },
            history: [{ date: '2026-08-12', price }],
            stats: { min: 46, median: 111, max: 149 },
            advice: { title: 'Good price — book', body: 'A solid time to lock it in.', tone: 'good' },
            booking: { aviasales: 'https://example.test/a', skyscanner: 'https://example.test/s' },
        },
        meta: { watched: true, fares: { fetchedAt: '2026-08-14T06:12:00+02:00', fresh: true } },
    },
})

beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
})

describe('the route detail panel', () => {
    it('draws the pair it is given, without a screen around it', async () => {
        get.mockResolvedValue(document_('AMS-LIS', 'Lisbon', 75))

        const wrapper = mount(RouteDetailPanel, {
            props: { code: 'AMS-LIS' },
            global: { plugins: [createPinia()] },
        })
        await flushPromises()

        expect(get).toHaveBeenCalledWith('/api/routes/AMS-LIS')
        expect(wrapper.get('.detail__code').text()).toBe('AMS → LIS')
        expect(wrapper.get('.price__value').text()).toBe('€75')
        // No back bar: that belongs to the screen, not to the panel.
        expect(wrapper.find('.detail__bar').exists()).toBe(false)
    })

    // How the desktop master pane changes route: the prop moves, nothing navigates.
    it('reloads for a new pair when the prop changes', async () => {
        get.mockResolvedValue(document_('AMS-LIS', 'Lisbon', 75))

        const wrapper = mount(RouteDetailPanel, {
            props: { code: 'AMS-LIS' },
            global: { plugins: [createPinia()] },
        })
        await flushPromises()

        get.mockResolvedValue(document_('AMS-OPO', 'Porto', 44))

        await wrapper.setProps({ code: 'AMS-OPO' })
        await flushPromises()

        expect(get).toHaveBeenLastCalledWith('/api/routes/AMS-OPO')
        expect(wrapper.get('.detail__code').text()).toBe('AMS → OPO')
        expect(wrapper.get('.price__value').text()).toBe('€44')
    })

    // A pasted lower-case link is normalised here rather than by every caller.
    it('normalises the case of the pair it is handed', async () => {
        get.mockResolvedValue(document_('AMS-LIS', 'Lisbon', 75))

        mount(RouteDetailPanel, {
            props: { code: 'ams-lis' },
            global: { plugins: [createPinia()] },
        })
        await flushPromises()

        expect(get).toHaveBeenCalledWith('/api/routes/AMS-LIS')
    })
})
