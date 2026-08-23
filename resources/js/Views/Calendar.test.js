// @vitest-environment jsdom
// The calendar's month navigation and the empty-far-month edge — not covered
// by e2e/specs/calendar.spec.js (docs/BUSINESS-LOGIC.md §36).
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia } from 'pinia'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { computed, ref } from 'vue'

const get = vi.fn()

/* jsdom has no matchMedia, so the real composable would answer 'phone' and nothing else; this is
   the switch the wide branch is behind. Deferred inside the arrow, as vi.mock is hoisted. */
const desktop = ref(false)

vi.mock('@/lib/http', () => ({ http: { get: (...args) => get(...args) } }))
vi.mock('@/lib/layout', () => ({
    useLayout: () => ({
        layout: computed(() => (desktop.value ? 'desktop' : 'phone')),
        isPhone: computed(() => !desktop.value),
        isDesktop: desktop,
        stop: () => {},
    }),
}))

import Calendar from './Calendar.vue'
import { addMonths, currentMonthKey, monthLabel } from '@/Components/calendar/month'

const ROUTE = {
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
    active: true,
}

/** docs/API.md's calendar payload, with one fare on the 4th. */
const PRICED = {
    data: {
        days: [{ date: null, price: 74, verdict: 'cheap' }],
        min: 74,
        max: 74,
        cheapest: { date: null, price: 74 },
    },
    meta: { bookingUrlTemplate: 'https://www.skyscanner.nl/transport/flights/ams/lis/{date}/' },
}

/** The same 200 with nothing in it — a month past the end of the window. */
const EMPTY = {
    data: { days: [], min: null, max: null, cheapest: null },
    meta: { bookingUrlTemplate: 'https://www.skyscanner.nl/transport/flights/ams/lis/{date}/' },
}

/** The fare's date has to sit in whichever month is being asked for. */
function priced(month) {
    return {
        data: {
            ...PRICED.data,
            days: [{ ...PRICED.data.days[0], date: `${month}-04` }],
            cheapest: { ...PRICED.data.cheapest, date: `${month}-04` },
        },
        meta: PRICED.meta,
    }
}

/**
 * Answer the watchlist with one route; the calendar endpoint returns priced
 * data, or empty for months in `emptyMonths`.
 */
function answering({ emptyMonths = [] } = {}) {
    get.mockImplementation((url, config) => {
        if (url === '/api/watchlist') {
            return Promise.resolve({ data: { data: [ROUTE], meta: { count: 1, active: 1 } } })
        }

        const month = config?.params?.month

        return Promise.resolve({ data: emptyMonths.includes(month) ? EMPTY : priced(month) })
    })
}

beforeEach(() => {
    desktop.value = false
})

async function mountCalendar() {
    const wrapper = mount(Calendar, {
        global: { plugins: [createPinia()], stubs: { RouterLink: RouterLinkStub } },
    })

    await flushPromises()

    return wrapper
}

const subtitle = (wrapper) => wrapper.find('.calendar__subtitle').text()
const arrows = (wrapper) => wrapper.findAll('.month-nav__button')
const prev = (wrapper) => arrows(wrapper)[0]
const next = (wrapper) => arrows(wrapper)[1]

async function step(wrapper, button) {
    await button.trigger('click')
    await flushPromises()
}

beforeEach(() => {
    vi.clearAllMocks()
    answering()
})

describe('how far the arrows go', () => {
    it('opens on this month with nowhere to go back to', async () => {
        const wrapper = await mountCalendar()

        expect(subtitle(wrapper)).toBe(`Cheapest fare per day · ${monthLabel(currentMonthKey())}`)
        expect(prev(wrapper).attributes('disabled')).toBeDefined()
        expect(next(wrapper).attributes('disabled')).toBeUndefined()
    })

    it('walks forward eleven months and stops', async () => {
        const wrapper = await mountCalendar()

        for (let month = 1; month <= 11; month += 1) {
            expect(next(wrapper).attributes('disabled'), `the arrow died at +${month}`).toBeUndefined()

            await step(wrapper, next(wrapper))

            expect(subtitle(wrapper)).toBe(
                `Cheapest fare per day · ${monthLabel(addMonths(currentMonthKey(), month))}`,
            )
        }

        // Eleven months out is the maintained horizon's edge, so the arrow
        // disables rather than fetching a grid that can only be empty.
        expect(next(wrapper).attributes('disabled')).toBeDefined()
        expect(prev(wrapper).attributes('disabled')).toBeUndefined()
    })

    it('asks the endpoint for the month it walked to', async () => {
        const wrapper = await mountCalendar()

        await step(wrapper, next(wrapper))

        expect(get).toHaveBeenLastCalledWith('/api/routes/AMS-LIS/calendar', {
            params: { month: addMonths(currentMonthKey(), 1) },
        })
    })

    it('comes back the way it went', async () => {
        const wrapper = await mountCalendar()

        await step(wrapper, next(wrapper))
        await step(wrapper, prev(wrapper))

        expect(subtitle(wrapper)).toBe(`Cheapest fare per day · ${monthLabel(currentMonthKey())}`)
        expect(prev(wrapper).attributes('disabled')).toBeDefined()
    })
})

describe('a month at the far end with no fares in it', () => {
    it('says so rather than drawing a legend across nothing', async () => {
        const far = addMonths(currentMonthKey(), 11)
        answering({ emptyMonths: [far] })

        const wrapper = await mountCalendar()

        for (let month = 1; month <= 11; month += 1) {
            await step(wrapper, next(wrapper))
        }

        expect(subtitle(wrapper)).toBe(`Cheapest fare per day · ${monthLabel(far)}`)
        expect(wrapper.text()).toContain('No fares seen for this month yet.')

        // The grid still draws — an empty month is blank cells, not a missing
        // screen — but the legend and "cheapest" banner don't appear for it.
        expect(wrapper.find('.grid-card').exists()).toBe(true)
        expect(wrapper.find('.legend').exists()).toBe(false)
        expect(wrapper.find('.banner').exists()).toBe(false)
    })

    it('is a 200 and not an error — the "could not load" copy stays away', async () => {
        answering({ emptyMonths: [addMonths(currentMonthKey(), 11)] })

        const wrapper = await mountCalendar()

        for (let month = 1; month <= 11; month += 1) {
            await step(wrapper, next(wrapper))
        }

        expect(wrapper.text()).not.toContain('Could not load this month')
    })
})

// The watched route's cheapest-departure month, clamped into the
// eleven-month arrow window and never in the past (docs/BUSINESS-LOGIC.md §36).
describe('which month it opens on', () => {
    /** One watched route whose cheapest departure is on `date`. */
    function watching(date) {
        get.mockImplementation((url, config) => {
            if (url === '/api/watchlist') {
                const route = { ...ROUTE, cheapest: date === null ? null : { date, price: 74 } }

                return Promise.resolve({ data: { data: [route], meta: { count: 1, active: 1 } } })
            }

            return Promise.resolve({ data: priced(config?.params?.month) })
        })
    }

    it('opens on the month the cheapest departure is in', async () => {
        const third = addMonths(currentMonthKey(), 3)
        watching(`${third}-09`)

        const wrapper = await mountCalendar()

        expect(subtitle(wrapper)).toBe(`Cheapest fare per day · ${monthLabel(third)}`)
        expect(get).toHaveBeenLastCalledWith('/api/routes/AMS-LIS/calendar', { params: { month: third } })
    })

    it('opens on this month when the route has no fare yet', async () => {
        // docs/API.md: `cheapest` is null before the first poll. Null is not a
        // date, and the month we are in is the honest place to start.
        watching(null)

        const wrapper = await mountCalendar()

        expect(subtitle(wrapper)).toBe(`Cheapest fare per day · ${monthLabel(currentMonthKey())}`)
    })

    it('clamps a date past the end of the window back to the last month', async () => {
        const far = addMonths(currentMonthKey(), 14)
        watching(`${far}-04`)

        const wrapper = await mountCalendar()

        expect(subtitle(wrapper)).toBe(`Cheapest fare per day · ${monthLabel(addMonths(currentMonthKey(), 11))}`)
        // The far edge, so forward is dead and back is not: the screen landed
        // INSIDE the window rather than past the end of it.
        expect(next(wrapper).attributes('disabled')).toBeDefined()
        expect(prev(wrapper).attributes('disabled')).toBeUndefined()
    })

    it('clamps a date behind today up to this month', async () => {
        // A row read from a cache, or a fare that expired between the poll and
        // the page load. The past is not offered at all.
        watching(`${addMonths(currentMonthKey(), -2)}-14`)

        const wrapper = await mountCalendar()

        expect(subtitle(wrapper)).toBe(`Cheapest fare per day · ${monthLabel(currentMonthKey())}`)
        expect(prev(wrapper).attributes('disabled')).toBeDefined()
    })

    it('follows the chip to the month that route is cheapest in', async () => {
        const second = addMonths(currentMonthKey(), 2)

        get.mockImplementation((url, config) => {
            if (url === '/api/watchlist') {
                return Promise.resolve({
                    data: {
                        data: [
                            { ...ROUTE, cheapest: null },
                            { ...ROUTE, code: 'AMS-OPO', cheapest: { date: `${second}-19`, price: 44 } },
                        ],
                        meta: { count: 2, active: 2 },
                    },
                })
            }

            return Promise.resolve({ data: priced(config?.params?.month) })
        })

        const wrapper = await mountCalendar()

        expect(subtitle(wrapper)).toBe(`Cheapest fare per day · ${monthLabel(currentMonthKey())}`)

        await step(wrapper, wrapper.findAll('.chip')[1])

        expect(subtitle(wrapper)).toBe(`Cheapest fare per day · ${monthLabel(second)}`)
        expect(get).toHaveBeenLastCalledWith('/api/routes/AMS-OPO/calendar', { params: { month: second } })
    })
})

// 1024px and up: the master pane's rows replace the chip strip, and a tapped day is a card beside
// the month rather than a sheet over it (docs/DESKTOP-LAYOUT-PLAN.md phase 2).
describe('inside the frame', () => {
    beforeEach(() => {
        desktop.value = true
    })

    it('lists the routes as rows instead of chips, on the same selection', async () => {
        answering()

        const wrapper = await mountCalendar()

        expect(wrapper.get('.calendar').classes()).toContain('calendar--wide')
        expect(wrapper.find('.chips').exists()).toBe(false)

        const rows = wrapper.findAll('.route-row')

        expect(rows).toHaveLength(1)
        expect(rows[0].attributes('data-code')).toBe('AMS-LIS')
        expect(rows[0].classes()).toContain('route-row--active')
    })

    it('docks the day beside the month, with no backdrop and no dialog', async () => {
        answering()

        const wrapper = await mountCalendar()
        await wrapper.get('.cell--fare').trigger('click')

        const panel = wrapper.get('.calendar__day .sheet')

        expect(panel.classes()).toContain('sheet--docked')
        expect(panel.attributes('role')).toBe('region')
        expect(wrapper.find('.backdrop').exists()).toBe(false)
        expect(panel.get('.sheet__price').text()).toBe('€74')
    })

    it('keeps the month nav out of the master head', async () => {
        answering()

        const wrapper = await mountCalendar()

        expect(wrapper.findAll('.month-nav')).toHaveLength(1)
        expect(wrapper.get('.calendar__nav .month-nav')).toBeTruthy()
    })

    // The phone is the branch every baseline was recorded against.
    it('is a chip strip and a teleported sheet on a phone', async () => {
        desktop.value = false
        answering()

        const wrapper = await mountCalendar()
        await wrapper.get('.cell--fare').trigger('click')

        expect(wrapper.find('.chips').exists()).toBe(true)
        expect(wrapper.find('.route-rows').exists()).toBe(false)
        expect(wrapper.find('.sheet--docked').exists()).toBe(false)
    })
})

