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

    /*
     * ⚠ The other side of that guard: a pair CHANGE that fails must not keep the previous route's
     * document, or one route's fares end up under another route's name.
     */
    it('does not leave the old route on screen when a new pair fails to load', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {})
        get.mockResolvedValue(document_('AMS-LIS', 'Lisbon', 75))

        const wrapper = mount(RouteDetailPanel, {
            props: { code: 'AMS-LIS' },
            global: { plugins: [createPinia()] },
        })
        await flushPromises()

        get.mockRejectedValue({ response: { status: 500 } })
        await wrapper.setProps({ code: 'AMS-OPO' })
        await flushPromises()

        expect(wrapper.get('.empty__title').text()).toBe('Could not load this route')
        expect(wrapper.find('.price__value').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('€75')
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

    // A flaky connection on the way back in is a refresh that did not happen, not a screen that
    // broke — and the fares it already had are the same fares.
    it('keeps the fares on screen when the refetch on the way back in fails', async () => {
        get.mockResolvedValue(document_('AMS-LIS', 'Lisbon', 75))

        const { shown, wrapper } = cached()
        await flushPromises()

        shown.value = false
        await flushPromises()

        get.mockRejectedValue({ response: { status: 500 } })
        shown.value = true
        await flushPromises()

        expect(wrapper.get('.price__value').text()).toBe('€75')
        expect(wrapper.find('.empty__title').exists()).toBe(false)
        expect(wrapper.get('.detail__notice--quiet').text()).toContain('Could not check today’s fares')
        expect(wrapper.get('.detail__notice--quiet').text()).toContain('Aug 14')
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

// The landing pane lays the panel out in two columns by making these four wrappers into grid
// items; everywhere else they are `display: contents` (Views/Home.vue).
describe('the two-column wrappers', () => {
    it('groups the body without reordering a line of it', async () => {
        get.mockResolvedValue(document_('AMS-LIS', 'Lisbon', 75))

        const wrapper = mount(RouteDetailPanel, {
            props: { code: 'AMS-LIS' },
            global: { plugins: [createPinia()] },
        })
        await flushPromises()

        const groups = wrapper.findAll('.detail__group')

        expect(groups.map((group) => group.classes()[1])).toEqual([
            'detail__group--summary',
            'detail__group--chart',
            'detail__group--advice',
            'detail__group--booking',
        ])

        expect(groups[0].find('.detail__head').exists()).toBe(true)
        expect(groups[0].find('.price').exists()).toBe(true)
        expect(groups[1].find('.chart-card').exists()).toBe(true)
        expect(groups[2].find('.callout').exists()).toBe(true)
        expect(groups[3].find('.booking').exists()).toBe(true)
    })

    // A skeleton or a "no such route" is one thing, and wrapping it would give the grid a column
    // of nothing to put beside it.
    it('leaves every other state ungrouped', async () => {
        get.mockResolvedValue(document_('AMS-LIS', 'Lisbon', 75))

        const wrapper = mount(RouteDetailPanel, {
            props: { code: 'nonsense' },
            global: { plugins: [createPinia()] },
        })
        await flushPromises()

        expect(wrapper.get('.empty__title').text()).toBe('No such route')
        expect(wrapper.findAll('.detail__group')).toHaveLength(0)
    })
})


// The pane swaps under the reader without a navigation, so nothing else would move the focus
// (docs/DESKTOP-LAYOUT-PLAN.md phase 4).
describe('the focus when a pane swaps', () => {
    const panel = (props) => mount(RouteDetailPanel, {
        props: { code: 'AMS-LIS', ...props },
        global: { plugins: [createPinia()] },
        attachTo: document.body,
    })

    it('sends it to the heading once the panel has one', async () => {
        get.mockResolvedValue(document_('AMS-LIS', 'Lisbon', 75))

        const wrapper = panel({ embedded: true, autofocus: true })
        await flushPromises()

        expect(document.activeElement).toBe(wrapper.get('.detail__code').element)

        wrapper.unmount()
    })

    // Not-found has a heading of its own, and it is the one worth being sent to.
    it('sends it to whichever heading rendered', async () => {
        get.mockResolvedValue(document_('AMS-LIS', 'Lisbon', 75))

        const wrapper = panel({ embedded: true, autofocus: true, code: 'nope' })
        await flushPromises()

        expect(document.activeElement).toBe(wrapper.get('.empty__title').element)

        wrapper.unmount()
    })

    it('leaves the focus alone on a screen nobody swapped', async () => {
        get.mockResolvedValue(document_('AMS-LIS', 'Lisbon', 75))

        const wrapper = panel()
        await flushPromises()

        expect(document.activeElement).toBe(document.body)

        wrapper.unmount()
    })
})
