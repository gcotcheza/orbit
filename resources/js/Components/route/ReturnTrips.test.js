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

    // A heading and no controls: the rows are not interactive, so there is
    // nothing here to reach by keyboard (docs/STANDARDS.md T8).
    it('is a real section under a real heading, with nothing to click', () => {
        const wrapper = section([band('A long weekend', [2, 3], FARE)])

        expect(wrapper.get('section h2').text()).toBe('Return trips')
        expect(wrapper.get('.ret__sub').text()).toBe('Round trip, by length of stay')
        expect(wrapper.findAll('button, a')).toHaveLength(0)
    })
})
