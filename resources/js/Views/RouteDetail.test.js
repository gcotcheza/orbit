// @vitest-environment jsdom
// =============================================================================
// The route detail's two sentences and one button
// =============================================================================
// The screen is mostly its children — the gauge, the chart, the callout have
// their own reasons to exist — and what is genuinely THIS file's is the prose
// under the price and how loudly the hand-off at the bottom speaks. Both were
// wrong in ways a screenshot makes obvious and no test noticed:
//
//   - a headline fare with NO DATE ON IT. €75 could be this Friday or eleven
//     weeks out, and those are different answers to "should I book";
//   - "36% below its usual €99" printed in full confidence beside a gauge
//     drawing no arc at all, because `confident: false` — the percentage was
//     computed from statistics made of the one fare it is being compared to;
//   - "Above usual — wait" in the callout with a glowing accent Book button
//     under it, which is the app arguing with itself and losing.
//
// docs/API.md is the fixture, as it is for Home.test.js.
// =============================================================================
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()

vi.mock('@/lib/http', () => ({ http: { get: (...args) => get(...args) } }))
// The screen resolves a router only for its Back button, which is not what any
// of this is about.
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn(), back: vi.fn() }) }))

import RouteDetail from './RouteDetail.vue'

const DETAIL = {
    code: 'AMS-LIS',
    origin: { iata: 'AMS', city: 'Amsterdam', country: 'Netherlands', countryCode: 'NL', lat: 52.3105, lng: 4.7683 },
    destination: { iata: 'LIS', city: 'Lisbon', country: 'Portugal', countryCode: 'PT', lat: 38.7742, lng: -9.1342 },
    price: { current: 75, usual: 111, pctBelow: 32 },
    score: 65,
    tier: 'great',
    confident: true,
    verdict: { label: 'Good price — book', short: 'Good', tone: 'good' },
    sparkline: [80, 78, 76, 75],
    trackingDays: 60,
    cheapest: { date: '2026-09-09', price: 75 },
    history: [
        { date: '2026-08-12', price: 80 },
        { date: '2026-08-13', price: 78 },
        { date: '2026-08-14', price: 75 },
    ],
    stats: { min: 46, p25: 79, median: 111, p75: 130, max: 149 },
    advice: { title: 'Good price — book', body: '€75 is 32% under its usual €111 — a solid time to lock it in.', tone: 'good' },
    bookingUrl: 'https://www.skyscanner.nl/transport/flights/ams/lis/260909/',
}

async function detail(overrides = {}) {
    get.mockResolvedValue({ data: { data: { ...DETAIL, ...overrides } } })

    const wrapper = mount(RouteDetail, { props: { id: 'AMS-LIS' } })

    await flushPromises()

    return wrapper
}

beforeEach(() => {
    vi.clearAllMocks()
})

describe('the day the price is for', () => {
    it('prints it under the fare, named as a departure', async () => {
        const wrapper = await detail()

        expect(wrapper.get('.price__value').text()).toBe('€75')
        /*
         * "Cheapest departure", spelled out. This screen's OTHER dates — the
         * chart's — are the days we looked, and a line reading "Wed, Sep 9"
         * with nothing in front of it would be read as either.
         */
        expect(wrapper.get('.price__when').text()).toBe('Cheapest departure · Wed, Sep 9')
    })

    it('prints no date at all when there is no fare to date', async () => {
        // docs/API.md: `cheapest` is null before the first poll, and null is
        // not today.
        const wrapper = await detail({ cheapest: null, price: { current: null, usual: null, pctBelow: null } })

        expect(wrapper.find('.price__when').exists()).toBe(false)
        expect(wrapper.get('.price__value').text()).toBe('—')
    })
})

describe('the caption under the price', () => {
    it('states the comparison when Orbit means it', async () => {
        const wrapper = await detail()

        expect(wrapper.get('.price__caption').text()).toBe('32% below its usual €111')
    })

    /*
     * THE HONESTY RULE, and it is the same one the gauge beside it already
     * follows. `confident: false` means Orbit is not expressing an opinion
     * (docs/API.md) — under a week of this route's own prices, so the
     * statistics are computed from the handful of fares it collected itself and
     * the current price IS the median. A bold "36% below its usual €99" next to
     * a ring drawing no arc is that placeholder arithmetic read out as a
     * finding.
     *
     * The usual price SURVIVES, because it is a fact. What goes is the
     * comparison drawn from it.
     */
    it('drops the percentage while it is still learning the route', async () => {
        const wrapper = await detail({
            confident: false,
            score: 0,
            tier: 'none',
            trackingDays: 1,
            price: { current: 63, usual: 99, pctBelow: 36 },
            verdict: { label: 'Not enough data yet', short: 'New', tone: 'normal' },
        })

        expect(wrapper.get('.price__caption').text()).toBe('Usual €99 · still learning')
        expect(wrapper.text()).not.toContain('36%')

        // And the ring says the same thing in its own language.
        expect(wrapper.get('.gauge__value').text()).toBe('—')
        expect(wrapper.get('.gauge__caption').text()).toBe('Deal score')
    })

    it('says the scale out loud when there is a score to scale', async () => {
        const wrapper = await detail()

        expect(wrapper.get('.gauge__caption').text()).toBe('Deal score /100')
    })

    it('says so plainly when there is no fare and no usual price', async () => {
        const wrapper = await detail({ price: { current: null, usual: null, pctBelow: null } })

        expect(wrapper.get('.price__caption').text()).toBe('No fare seen for this route yet.')
    })
})

describe('the booking hand-off', () => {
    it('is the loud one when the advice is not a warning', async () => {
        const wrapper = await detail()

        expect(wrapper.get('.booking__cta').classes()).toContain('booking__cta--primary')
    })

    /*
     * A callout reading "Above usual — wait" over a glowing accent button is
     * the app contradicting itself, and the button wins because it is the
     * loudest thing on the screen. Same link, same tap target, quieter.
     */
    it('goes quiet under advice that says to wait', async () => {
        const wrapper = await detail({
            verdict: { label: 'Above usual — wait', short: 'Wait', tone: 'warn' },
            advice: {
                title: 'Above usual — wait',
                body: '€75 is 10% above its usual €68. Hold off — fares this far up tend to settle back.',
                tone: 'warn',
            },
        })

        expect(wrapper.get('.booking__cta').classes()).toContain('booking__cta--secondary')
        // Still a link, still to the same place.
        expect(wrapper.get('.booking__cta').attributes('href')).toBe(DETAIL.bookingUrl)
    })
})
