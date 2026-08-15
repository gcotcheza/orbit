// @vitest-environment jsdom
// =============================================================================
// The calendar's month navigation, and the edge of the poll window
// =============================================================================
// The grid, the heat scale and the day sheet are covered by their own files and
// by e2e/specs/calendar.spec.js, which is the only place the computed colours
// can actually be read back. This is about the two things the six-month window
// changed and neither of those can see:
//
//   1. HOW FAR THE ARROWS GO. `orbit.poll.window_days` is six months, so the
//      screen offers this month and six more. One arrow too few hides a month
//      of real fares; one too many is a promise of data that cannot exist.
//
//   2. WHAT THE FAR END LOOKS LIKE WHEN IT IS EMPTY. A window that opens on the
//      1st of a short month closes INSIDE the sixth one, so on a few mornings a
//      year the last reachable month holds nothing — and the same is true of
//      every month of a route added this morning. The e2e suite cannot produce
//      that case: its fake provider prices every day of the window, always.
//      Here the endpoint is a stub and an empty month is one line of fixture.
//
// EVERY EXPECTED MONTH IS DERIVED, never written out. The screen's first month
// is `currentMonthKey()` read at import time, so a test that named "February
// 2027" would be a test with a shelf life — and freezing the clock would not
// help, because the component reads it before any hook in here runs.
// =============================================================================
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia } from 'pinia'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()

vi.mock('@/lib/http', () => ({ http: { get: (...args) => get(...args) } }))

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
 * Answer the watchlist with one route, and the calendar endpoint with whatever
 * `month` it is asked for — priced by default, empty for the months in
 * `emptyMonths`.
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

    it('walks forward six months and stops', async () => {
        const wrapper = await mountCalendar()

        for (let month = 1; month <= 6; month += 1) {
            expect(next(wrapper).attributes('disabled'), `the arrow died at +${month}`).toBeUndefined()

            await step(wrapper, next(wrapper))

            expect(subtitle(wrapper)).toBe(
                `Cheapest fare per day · ${monthLabel(addMonths(currentMonthKey(), month))}`,
            )
        }

        // Six months out is the edge of the poll window: there is nothing
        // beyond it to ask for, and the arrow says so rather than fetching an
        // empty grid.
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
        const far = addMonths(currentMonthKey(), 6)
        answering({ emptyMonths: [far] })

        const wrapper = await mountCalendar()

        for (let month = 1; month <= 6; month += 1) {
            await step(wrapper, next(wrapper))
        }

        expect(subtitle(wrapper)).toBe(`Cheapest fare per day · ${monthLabel(far)}`)
        expect(wrapper.text()).toContain('No fares seen for this month yet.')

        // The grid is still drawn — an empty month is a month of blank cells,
        // not a missing screen — and neither the heat legend nor the "cheapest
        // this month" banner appears over a range that does not exist.
        expect(wrapper.find('.grid-card').exists()).toBe(true)
        expect(wrapper.find('.legend').exists()).toBe(false)
        expect(wrapper.find('.banner').exists()).toBe(false)
    })

    it('is a 200 and not an error — the "could not load" copy stays away', async () => {
        answering({ emptyMonths: [addMonths(currentMonthKey(), 6)] })

        const wrapper = await mountCalendar()

        for (let month = 1; month <= 6; month += 1) {
            await step(wrapper, next(wrapper))
        }

        expect(wrapper.text()).not.toContain('Could not load this month')
    })
})
