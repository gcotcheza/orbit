// @vitest-environment jsdom
// The day sheet's two actions — the contract half a browser test can only
// observe by leaving the app (docs/BUSINESS-LOGIC.md §36).
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
    // `1509` is 15 September; `0915` would search September's 15th month.
    it('sends the tapped day to Aviasales, day before month', () => {
        expect(booking(sheet()).attributes('href')).toBe(
            'https://www.aviasales.com/search/AMS1509OPO1?marker=123456',
        )
    })

    it('offers Skyscanner as a quiet comparison on the same day', () => {
        const link = compare(sheet())

        expect(link.attributes('href')).toBe(
            'https://www.skyscanner.nl/transport/flights/ams/opo/260915/',
        )
        expect(link.text()).toBe('Compare on Skyscanner')
    })

    // Which one is loud is a correctness matter (docs/BUSINESS-LOGIC.md §36).
    it('makes Aviasales the loud action and Skyscanner the quiet one', () => {
        const wrapper = sheet()

        expect(booking(wrapper).attributes('href')).toContain('aviasales.com')
        expect(wrapper.findAll('.action--solid')).toHaveLength(1)
        expect(compare(wrapper).classes()).not.toContain('action--solid')
    })

    it('draws the second opinion as a button beside the first, not as small print', () => {
        const wrapper = sheet()

        expect(compare(wrapper).classes()).toContain('action')
        // Skyscanner first, then Aviasales: the check before the act.
        expect(wrapper.findAll('.actions .action').map((link) => link.attributes('href'))).toEqual([
            BOOKING.skyscanner.replace('{yymmdd}', '260915'),
            BOOKING.aviasales.replace('{ddmm}', '1509'),
        ])
    })

    it('labels the heat swatch', () => {
        expect(sheet().get('.sheet__swatch-label').text()).toBe('Price vs month')
    })

    // The date is a calendar day with no zone (docs/API.md) — a month's first
    // day, where a `Date` re-read would slide, is checked on both links.
    it('does not slide the date into another timezone', () => {
        const wrapper = sheet({ fare: { date: '2027-01-01', price: 61, verdict: 'mid' } })

        expect(booking(wrapper).attributes('href')).toContain('AMS0101OPO1')
        expect(compare(wrapper).attributes('href')).toContain('/270101/')
    })

    it('leaves the app with noopener and keeps the referrer', () => {
        for (const link of [booking(sheet()), compare(sheet())]) {
            const rel = link.attributes('rel')

            expect(rel).toContain('noopener')
            // The affiliate attribution rides on the referrer (docs/BUSINESS-LOGIC.md §36).
            expect(rel).not.toContain('noreferrer')
            expect(link.attributes('target')).toBe('_blank')
        }
    })

    it.each([
        ['2026-09-11T11:30:00+02:00', 'Seen just now'],
        ['2026-09-11T09:00:00+02:00', 'Seen 3 hours ago'],
        ['2026-09-07T12:00:00+02:00', 'Seen 4 days ago'],
    ])('says when the price was found (%s)', (foundAt, expected) => {
        const wrapper = sheet({ fare: { date: '2026-09-15', price: 44, verdict: 'cheap', foundAt } })

        expect(wrapper.get('.sheet__seen').text()).toBe(expected)
    })

    // Null is "not known" (docs/BUSINESS-LOGIC.md §2) — the element is ABSENT.
    it('draws no freshness line at all when the age is unknown', () => {
        const wrapper = sheet()

        expect(wrapper.find('.sheet__seen').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('Seen')
    })

    // Word for word BookingCta.vue's — duplicated, not shared, so this
    // catches drift (docs/BUSINESS-LOGIC.md §36).
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

    // An older build or config without an Aviasales base costs that link only.
    it('draws whichever hand-offs it was given', () => {
        const only = sheet({ booking: { skyscanner: BOOKING.skyscanner } })

        expect(only.find('.action--solid').exists()).toBe(false)
        expect(only.get('.compare').attributes('href')).toContain('skyscanner')
    })

    // The actions sit INSIDE the sheet; the backdrop is its sibling, so a
    // tap on either cannot also dismiss.
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
