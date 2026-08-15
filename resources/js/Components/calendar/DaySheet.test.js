// @vitest-environment jsdom
// =============================================================================
// The day sheet's two actions
// =============================================================================
// The sheet's CONTENT — the date, the fare, the verdict pill — is covered by
// e2e/specs/calendar.spec.js against real data. What is checked here is the
// half that is a contract rather than a picture, and that a browser test can
// only observe by leaving the app:
//
//   - each booking link is aimed at the day that was TAPPED. They are built by
//     substituting a named date hole in the templates the calendar endpoint
//     sends, and the failure mode is a URL that is well-formed, opens fine, and
//     books a different day — which no smoke test notices.
//   - AVIASALES IS THE LOUD ONE and Skyscanner the quiet second opinion, which
//     is a correctness matter rather than a style one: Orbit's prices come out
//     of Aviasales' cache, and the sheet used to send people to Skyscanner,
//     which had often never had the fare (€29 here against €68 there).
//   - they open away from the app with `rel="noopener"` and WITHOUT
//     `noreferrer`, because the referrer is the affiliate attribution
//     (Components/route/BookingCta.vue says why at length).
//   - the details link goes to the route the month is for.
//   - a response with no templates costs the sheet its outward actions, not the
//     sheet.
//   - the freshness line says how old the price is, and says NOTHING when that
//     is not known.
//
// `RouterLinkStub` rather than a real router: what is under test is the `to`
// that is handed over, and installing vue-router to read it back would be
// testing vue-router.
// =============================================================================
import { RouterLinkStub, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import DaySheet from './DaySheet.vue'

const BOOKING = {
    aviasales: 'https://www.aviasales.com/search/AMS{ddmm}OPO1?marker=123456',
    skyscanner: 'https://www.skyscanner.nl/transport/flights/ams/opo/{yymmdd}/',
}

/** A fixed clock, so "Seen …" is a fact rather than a race with the suite. */
const NOW = new Date('2026-09-11T12:00:00+02:00')

beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
})

afterEach(() => {
    vi.useRealTimers()
})

function sheet(overrides = {}) {
    return mount(DaySheet, {
        props: {
            fare: { date: '2026-09-15', price: 44, verdict: 'cheap', foundAt: null },
            min: 40,
            max: 100,
            code: 'AMS-OPO',
            booking: BOOKING,
            ...overrides,
        },
        global: { stubs: { RouterLink: RouterLinkStub } },
    })
}

/** The primary hand-off — the accent-filled action in the pair. */
function booking(wrapper) {
    return wrapper.get('.action--solid')
}

/** The quiet second opinion under it. */
function compare(wrapper) {
    return wrapper.get('.compare')
}

describe('DaySheet', () => {
    /*
     * THE PRIMARY LINK IS AVIASALES AND ITS DATE IS DAY-FIRST. `1509` is 15
     * September; `0915` would be a link that opens perfectly and searches
     * September's fifteenth month. The marker rides along, which is the
     * attribution finally going somewhere.
     */
    it('sends the tapped day to Aviasales, day before month', () => {
        expect(booking(sheet()).attributes('href')).toBe(
            'https://www.aviasales.com/search/AMS1509OPO1?marker=123456',
        )
    })

    /* And the second opinion gets the same day in its own encoding. */
    it('offers Skyscanner as a quiet comparison on the same day', () => {
        const link = compare(sheet())

        expect(link.attributes('href')).toBe(
            'https://www.skyscanner.nl/transport/flights/ams/opo/260915/',
        )
        expect(link.text()).toBe('Compare on Skyscanner')
    })

    /*
     * WHICH ONE IS LOUD IS THE POINT, not a detail of styling. Orbit quotes
     * Aviasales' cached fares; sending the reader to a different meta-search as
     * the obvious action is how the app came to show €29 for a flight listed at
     * €68 elsewhere.
     */
    it('makes Aviasales the loud action and Skyscanner the quiet one', () => {
        const wrapper = sheet()

        expect(booking(wrapper).attributes('href')).toContain('aviasales.com')
        expect(wrapper.findAll('.action--solid')).toHaveLength(1)
        expect(compare(wrapper).classes()).not.toContain('action--solid')
    })

    /*
     * The date is a calendar day with no zone (docs/API.md). Anything that
     * routes it through a `Date` re-reads it in the viewer's own timezone and
     * books the day before for half the planet — so the first of a month, which
     * is where that fault surfaces, is checked explicitly, on both links.
     */
    it('does not slide the date into another timezone', () => {
        const wrapper = sheet({ fare: { date: '2027-01-01', price: 61, verdict: 'mid' } })

        expect(booking(wrapper).attributes('href')).toContain('AMS0101OPO1')
        expect(compare(wrapper).attributes('href')).toContain('/270101/')
    })

    it('leaves the app with noopener and keeps the referrer', () => {
        for (const link of [booking(sheet()), compare(sheet())]) {
            const rel = link.attributes('rel')

            expect(rel).toContain('noopener')
            // The affiliate attribution rides on the referrer; see BookingCta.
            expect(rel).not.toContain('noreferrer')
            expect(link.attributes('target')).toBe('_blank')
        }
    })

    // -- How old the price is -------------------------------------------------

    /*
     * THE LINE THE WHOLE FEATURE EXISTS FOR. The sheet is the screen with a
     * booking link on it, so a big confident number over it was the app
     * implying a fare is on sale right now — when it may be a cached price
     * somebody's search turned up days ago.
     */
    it.each([
        ['2026-09-11T11:30:00+02:00', 'Seen just now'],
        ['2026-09-11T09:00:00+02:00', 'Seen 3 hours ago'],
        ['2026-09-07T12:00:00+02:00', 'Seen 4 days ago'],
    ])('says when the price was found (%s)', (foundAt, expected) => {
        const wrapper = sheet({ fare: { date: '2026-09-15', price: 44, verdict: 'cheap', foundAt } })

        expect(wrapper.get('.sheet__seen').text()).toBe(expected)
    })

    /*
     * AND SAYS NOTHING WHEN IT DOES NOT KNOW. `foundAt` is null for any row
     * written before the column existed. A "Seen just now" there would be a
     * claim rather than an omission, and worse than the silence this replaced —
     * so the element is ABSENT, not empty.
     */
    it('draws no freshness line at all when the age is unknown', () => {
        const wrapper = sheet()

        expect(wrapper.find('.sheet__seen').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('Seen')
    })

    /*
     * THE EXPECTATION LINE, WORD FOR WORD, and it is asserted here because it
     * is duplicated in Components/route/BookingCta.vue rather than shared — the
     * two must not be able to drift. It MERGED the old "we don't sell tickets"
     * disclaimer rather than being stacked under it: two greyed sentences read
     * as small print, and small print is not read.
     */
    it('sets one expectation about the price, next to the hand-off', () => {
        const wrapper = sheet()

        expect(wrapper.get('.disclaimer').text()).toBe(
            'Prices come from recent searches — the booking site shows live availability.',
        )
        expect(wrapper.text()).not.toContain("We don't sell tickets")
    })

    it('points the other action at the route the month is for', () => {
        const link = sheet().findComponent(RouterLinkStub)

        expect(link.props('to')).toEqual({ name: 'route-detail', params: { id: 'AMS-OPO' } })
        expect(link.text()).toBe('Route details')
    })

    it('drops the outward actions rather than the sheet when no templates arrived', () => {
        const wrapper = sheet({ booking: null })

        expect(wrapper.findAll('a[target="_blank"]')).toHaveLength(0)
        // …and nothing is left promising a hand-off that cannot happen.
        expect(wrapper.find('.disclaimer').exists()).toBe(false)

        expect(wrapper.findComponent(RouterLinkStub).exists()).toBe(true)
        expect(wrapper.get('.sheet__price').text()).toBe('€44')
    })

    /*
     * A response carrying only one of the two — an older build, or a config
     * without an Aviasales base — costs that link and not the other.
     */
    it('draws whichever hand-offs it was given', () => {
        const only = sheet({ booking: { skyscanner: BOOKING.skyscanner } })

        expect(only.find('.action--solid').exists()).toBe(false)
        expect(only.get('.compare').attributes('href')).toContain('skyscanner')
    })

    /*
     * The actions sit INSIDE the sheet and the backdrop is its sibling, so a
     * tap on either cannot also dismiss. Both dismissals are re-checked here
     * because adding tappable things to a dialog is exactly when they break.
     */
    it('still closes on the backdrop and on Escape, and not on its own actions', async () => {
        const wrapper = sheet()

        await booking(wrapper).trigger('click')
        await wrapper.findComponent(RouterLinkStub).trigger('click')
        expect(wrapper.emitted('close')).toBeUndefined()

        await wrapper.get('.backdrop').trigger('click')
        expect(wrapper.emitted('close')).toHaveLength(1)

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
        expect(wrapper.emitted('close')).toHaveLength(2)
    })
})
