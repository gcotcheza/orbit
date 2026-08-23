// @vitest-environment jsdom
// The panel on its own terms — the desktop pane holds it by a `code` prop and swaps that prop
// rather than navigating (Views/RouteDetail.test.js covers the screen around it).
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { h, KeepAlive, ref } from 'vue'

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

describe('the back way out', () => {
    it('offers one on a screen of its own', async () => {
        const wrapper = mount(RouteDetailPanel, {
            props: { code: 'ZZ-YY' },
            global: { plugins: [createPinia()] },
        })
        await flushPromises()

        expect(wrapper.get('.empty__title').text()).toBe('No such route')
        expect(wrapper.get('.empty__action').text()).toBe('Go back')
    })

    // Inside the landing pane, "back" would leave the page nobody navigated away from.
    it('offers none inside a pane', async () => {
        const wrapper = mount(RouteDetailPanel, {
            props: { code: 'ZZ-YY', embedded: true },
            global: { plugins: [createPinia()] },
        })
        await flushPromises()

        expect(wrapper.get('.empty__title').text()).toBe('No such route')
        expect(wrapper.find('.empty__action').exists()).toBe(false)
    })
})

/*
 * The pane rides inside Home's <KeepAlive> (App.vue), so it is the one place in this app where a
 * detail document survives a navigation and can go stale.
 */
describe('coming back to a pane that was cached', () => {
    /** Home, as far as this panel can tell: a KeepAlive that can be switched off and on. */
    function cached() {
        const shown = ref(true)
        const wrapper = mount(
            {
                setup: () => () => h(KeepAlive, null, {
                    default: () => (shown.value ? h(RouteDetailPanel, { code: 'AMS-LIS' }) : null),
                }),
            },
            { global: { plugins: [createPinia()] } },
        )

        return { shown, wrapper }
    }

    it('refetches the fares rather than showing the ones it cached', async () => {
        get.mockResolvedValue(document_('AMS-LIS', 'Lisbon', 75))

        const { shown, wrapper } = cached()
        await flushPromises()

        expect(get).toHaveBeenCalledTimes(1)

        shown.value = false
        await flushPromises()

        get.mockResolvedValue(document_('AMS-LIS', 'Lisbon', 61))
        shown.value = true
        await flushPromises()

        expect(get).toHaveBeenCalledTimes(2)
        expect(wrapper.get('.price__value').text()).toBe('€61')
    })

    // Quietly: a skeleton drawn over fares somebody can already read is a step backwards.
    it('keeps the fares on screen while the refetch runs', async () => {
        get.mockResolvedValue(document_('AMS-LIS', 'Lisbon', 75))

        const { shown, wrapper } = cached()
        await flushPromises()

        shown.value = false
        await flushPromises()

        let answer
        get.mockReturnValue(new Promise((resolve) => { answer = resolve }))
        shown.value = true
        await flushPromises()

        expect(wrapper.find('.skeleton').exists()).toBe(false)
        expect(wrapper.get('.price__value').text()).toBe('€75')

        answer(document_('AMS-LIS', 'Lisbon', 61))
        await flushPromises()

        expect(wrapper.get('.price__value').text()).toBe('€61')
    })
})
