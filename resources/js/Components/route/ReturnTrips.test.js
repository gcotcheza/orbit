// @vitest-environment jsdom
// The "Return trips" section on its own terms: four rows, whatever the server holds for each
// (design/README.md §2, docs/API.md `returns`).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import ReturnTrips from './ReturnTrips.vue'

/** The clock every "seen" assertion below is written against. */
const NOW = new Date('2026-10-11T12:00:00+02:00')

const band = (label, nights, fare = null) => ({ band: { label, nights }, fare })

const FARE = {
    current: 112,
    usual: 122,
    pctBelow: 8,
    nights: 2,
    departure: '2026-10-11',
    foundAt: '2026-10-11T06:12:07+02:00',
    mayBeGone: false,
    sampleCount: 7,
    booking: { aviasales: 'https://www.aviasales.com/search/AMS1110OPO13101?marker=123456' },
    verdict: { label: 'Good price — book', short: 'Good', tone: 'good' },
}

/** A second priced band, so "one link per fare" is a count and not a coincidence. */
const WEEK = {
    ...FARE,
    current: 134,
    nights: 7,
    booking: { aviasales: 'https://www.aviasales.com/search/AMS1110OPO18101?marker=123456' },
}

const section = (returns) => mount(ReturnTrips, { props: { returns } })

const rows = (wrapper) => wrapper.findAll('.ret__row')

beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
})

afterEach(() => {
    vi.useRealTimers()
})

describe('a band with a fare in it', () => {
    it('prints what it costs from, how that compares, and which trip it is', () => {
        const wrapper = section([band('A long weekend', [2, 3], FARE)])
        const row = rows(wrapper)[0]

        expect(row.get('.ret__name').text()).toBe('A long weekend')
        expect(row.get('.ret__nights').text()).toBe('2–3 nights')
        expect(row.get('.ret__price').text()).toBe('from €112')
        expect(row.get('.ret__vs').text()).toBe('8% below its usual €122')
        expect(row.get('.ret__meta').text()).toBe('Sun, Oct 11 · 2 nights')
        expect(row.find('.ret__gone').exists()).toBe(false)
    })

    // R5: most bands on a real route are too thin for a distribution, and a price
    // with no verdict attached is the honest answer.
    it('says there is no usual price yet rather than inventing one', () => {
        const wrapper = section([band('A fortnight', [13, 15], { ...FARE, usual: null, pctBelow: null })])

        expect(wrapper.get('.ret__vs').text()).toBe('No usual price yet')
        expect(wrapper.get('.ret__vs').classes()).toContain('ret__vs--none')
        expect(wrapper.get('.ret__price').text()).toBe('from €112')
    })

    it('prints a price level with its usual one without a percentage', () => {
        const wrapper = section([band('A week away', [6, 8], { ...FARE, current: 122, usual: 122, pctBelow: 0 })])

        expect(wrapper.get('.ret__vs').text()).toBe('Right at its usual €122')
    })

    it('says how old a fare is once it has survived a morning it should have been repriced in', () => {
        const wrapper = section([band('A week away', [6, 8], { ...FARE, foundAt: '2026-10-07T06:12:07+02:00' })])

        expect(wrapper.get('.ret__meta').text()).toBe('Sun, Oct 11 · 2 nights · seen 4 days ago')
    })

    it('leaves the age off a fare found this morning', () => {
        expect(section([band('A week away', [6, 8], FARE)]).get('.ret__meta').text()).not.toContain('seen')
    })

    // The pill REPLACES the seen phrase — the same age twice in two voices is
    // the screen arguing with itself (design/README.md §2).
    it('replaces the age with the pill when the server says the fare may be gone', () => {
        const wrapper = section([
            band('A fortnight', [13, 15], { ...FARE, foundAt: '2026-10-05T06:12:07+02:00', mayBeGone: true }),
        ])

        expect(wrapper.get('.ret__gone').text()).toBe('Seen 6 days ago — may be gone')
        expect(wrapper.get('.ret__meta').text()).toBe('Sun, Oct 11 · 2 nights')
    })
})

// The server's opinion of this band, in the watchlist's own pill — the tone is the only
// thing it switches on (design/README.md §2, docs/API.md `returns[].fare.verdict`).
describe('the verdict on a row', () => {
    it('draws the pill in the left column, in the tone the server sent', () => {
        const row = rows(section([band('A long weekend', [2, 3], FARE)]))[0]
        const pill = row.get('.pill')

        expect(pill.text()).toBe('Good')
        expect(pill.attributes('data-tone')).toBe('good')
        expect(pill.attributes('data-size')).toBe('sm')

        // Under the nights range, not beside the price: a row without one must not grow.
        expect(row.element.firstElementChild.contains(pill.element)).toBe(true)
    })

    // R5/R9: below `min_samples` there is no usual price, so there is nothing to judge.
    it('draws no pill on a band the server would not judge', () => {
        const wrapper = section([band('A fortnight', [13, 15], { ...FARE, usual: null, pctBelow: null, verdict: null })])

        expect(wrapper.find('.pill').exists()).toBe(false)
        expect(wrapper.get('.ret__vs').text()).toBe('No usual price yet')
    })

    it('draws no pill on a band with nothing in it', () => {
        expect(section([band('Three to four weeks', [21, 28])]).find('.pill').exists()).toBe(false)
    })

    // A ghost is not scored, so the two pills can never share a row.
    it('leaves a may-be-gone row its warning pill and no verdict', () => {
        const wrapper = section([
            band('A fortnight', [13, 15], {
                ...FARE,
                foundAt: '2026-10-05T06:12:07+02:00',
                mayBeGone: true,
                verdict: null,
            }),
        ])

        expect(wrapper.get('.ret__gone').text()).toBe('Seen 6 days ago — may be gone')
        expect(wrapper.find('.pill').exists()).toBe(false)
    })
})

// R6: a band that vanished when its fares thinned would read as a broken feature
// rather than as a route nobody has searched a long weekend on.
describe('a band with nothing in it', () => {
    it('is a quiet row, not a missing one', () => {
        const wrapper = section([band('Three to four weeks', [21, 28])])
        const row = rows(wrapper)[0]

        expect(row.classes()).toContain('ret__row--none')
        expect(row.get('.ret__name').text()).toBe('Three to four weeks')
        expect(row.get('.ret__nights').text()).toBe('21–28 nights')
        expect(row.get('.ret__none').text()).toBe('No return fares seen yet')
        expect(row.find('.ret__price').exists()).toBe(false)
    })
})

describe('the section itself', () => {
    it('draws every band the server sent, in the order it sent them', () => {
        const wrapper = section([
            band('A long weekend', [2, 3], FARE),
            band('A week away', [6, 8]),
            band('A fortnight', [13, 15], { ...FARE, usual: null, pctBelow: null }),
            band('Three to four weeks', [21, 28]),
        ])

        expect(rows(wrapper).map((row) => row.get('.ret__name').text())).toEqual([
            'A long weekend',
            'A week away',
            'A fortnight',
            'Three to four weeks',
        ])
    })

    // An answer from a build that had no such field: the section owns its own emptiness, so
    // nothing around it has to guard for it.
    it('draws nothing at all when there are no bands', () => {
        expect(section([]).find('.ret').exists()).toBe(false)
    })

    it('is a real section under a real heading', () => {
        const wrapper = section([band('A long weekend', [2, 3], FARE)])

        expect(wrapper.get('section h2').text()).toBe('Return trips')
        expect(wrapper.get('.ret__sub').text()).toBe('Round trip, by length of stay')
    })
})

// The whole card is the tap target and it goes to the server's own round-trip search
// (design/README.md §2, docs/API.md `returns[].fare.booking.aviasales`).
describe('tapping a priced row', () => {
    const priced = () =>
        section([
            band('A long weekend', [2, 3], FARE),
            band('A week away', [6, 8], WEEK),
            band('A fortnight', [13, 15]),
        ])

    it('opens the round trip the row is priced for', () => {
        const link = priced().findAll('.ret__row')[0]

        expect(link.element.tagName).toBe('A')
        expect(link.attributes('href')).toBe(FARE.booking.aviasales)
    })

    it('leaves the app the way the Aviasales button does', () => {
        const link = priced().findAll('.ret__row')[0]

        expect(link.attributes('target')).toBe('_blank')
        expect(link.attributes('rel')).toBe('noopener')
    })

    it('gives every band with a fare its own link and no band without one', () => {
        const wrapper = priced()

        expect(wrapper.findAll('a.ret__row').map((row) => row.attributes('href'))).toEqual([
            FARE.booking.aviasales,
            WEEK.booking.aviasales,
        ])
    })

    // Nothing is held, so there is nothing to open — and no chevron promising there is.
    it('leaves a band with no fare unlinked and without a chevron', () => {
        const wrapper = priced()
        const quiet = wrapper.findAll('.ret__row')[2]

        expect(quiet.element.tagName).toBe('DIV')
        expect(quiet.attributes('href')).toBeUndefined()
        expect(quiet.find('.ret__chevron').exists()).toBe(false)
        expect(wrapper.findAll('.ret__chevron')).toHaveLength(2)
    })
})
