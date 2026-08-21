// @vitest-environment jsdom
// The verified/unverified badge — worth testing since it makes a claim,
// not just prints fields (docs/BUSINESS-LOGIC.md §16).
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-router', () => ({
    RouterLink: { props: ['to'], template: '<a :data-to="to.params.id"><slot /></a>' },
}))

import DiscoveryCard from './DiscoveryCard.vue'

/** One `GET /api/discoveries` row, in the shape docs/API.md sends. */
const discovery = (overrides = {}) => ({
    code: 'DUS-AGP',
    origin: { iata: 'DUS', city: 'Düsseldorf', country: 'Germany' },
    destination: { iata: 'AGP', city: 'Málaga', country: 'Spain' },
    lane: 'absolute',
    price: 29,
    departureDate: '2026-10-24',
    milliEurosPerKm: 15.6,
    percentile: 0,
    savings: 49,
    foundAt: '2026-08-15T08:00:00+02:00',
    verdict: {
        verified: false,
        label: 'Unverified',
        level: 'typical',
        googleLowest: 70,
        typicalLow: 55,
        typicalHigh: 175,
    },
    ...overrides,
})

const card = (overrides) => mount(DiscoveryCard, { props: { discovery: discovery(overrides) } })

describe('what the card says', () => {
    it('leads with the city and the price', () => {
        const wrapper = card()

        expect(wrapper.find('.find__city').text()).toBe('Málaga')
        expect(wrapper.find('.find__country').text()).toBe('Spain')
        expect(wrapper.find('.find__from').text()).toBe('DUS → AGP')
        expect(wrapper.find('.find__price').text()).toBe('€29')
    })

    it('says which day, because a fare without one is not an offer', () => {
        expect(card().find('.find__when').text()).toBe('Sat, Oct 24')
    })

    // Links into the existing route lookup rather than duplicating
    // booking/watch behaviour here (docs/BUSINESS-LOGIC.md §16).
    it('is a link into the ordinary route screen', () => {
        expect(card().find('a').attributes('data-to')).toBe('DUS-AGP')
    })
})

describe('the badge', () => {
    it('is quiet, and not a warning, when Google was never asked', () => {
        const wrapper = card({ verdict: { verified: false, label: 'Unverified' } })
        const badge = wrapper.find('.find__badge')

        expect(badge.attributes('data-verified')).toBe('false')
        expect(badge.text()).toBe('Unverified')
        /* No tick: the mark is what an earned verdict looks like. */
        expect(badge.find('svg').exists()).toBe(false)
    })

    it('earns a tick when Google agreed', () => {
        const wrapper = card({ verdict: { verified: true, label: 'Verified low by Google' } })
        const badge = wrapper.find('.find__badge')

        expect(badge.attributes('data-verified')).toBe('true')
        expect(badge.text()).toContain('Verified low by Google')
        expect(badge.find('svg').exists()).toBe(true)
    })

    /*
     * The sentence is the server's; a hard-coded string here would keep
     * making the claim after the check behind it is switched off.
     */
    it('prints the server\'s words rather than composing its own', () => {
        const wrapper = card({ verdict: { verified: true, label: 'Checked against something else' } })

        expect(wrapper.find('.find__badge').text()).toContain('Checked against something else')
    })
})

describe('the evidence line', () => {
    it('says outright when the fare is the cheapest date on the route', () => {
        expect(card().find('.find__evidence').text())
            .toBe('Cheapest date on this route · €49 under its usual')
    })

    it('counts the dates it beat when it is not the outright cheapest', () => {
        expect(card({ percentile: 8, savings: 30 }).find('.find__evidence').text())
            .toBe('Cheaper than 92% of dates · €30 under its usual')
    })

    // A null window renders no line at all — never "0%" (docs/BUSINESS-LOGIC.md §16).
    it('is absent when the window could not be measured', () => {
        expect(card({ percentile: null, savings: null }).find('.find__evidence').exists()).toBe(false)
    })

    it('drops the savings clause rather than printing a zero', () => {
        expect(card({ percentile: 0, savings: null }).find('.find__evidence').text())
            .toBe('Cheapest date on this route')
    })
})

describe('how old the price is', () => {
    it('always says, because a swept fare can be three days old', () => {
        vi.setSystemTime(new Date('2026-08-17T08:00:00+02:00'))

        expect(card().find('.find__seen').text()).toBe('seen 2 days ago')

        vi.useRealTimers()
    })

    // Null renders as nothing, never as fresh (docs/BUSINESS-LOGIC.md §16).
    it('says nothing at all rather than guessing', () => {
        expect(card({ foundAt: null }).find('.find__seen').exists()).toBe(false)
    })
})

// Only relative finds get an explanatory sentence (docs/BUSINESS-LOGIC.md §16).
describe('which argument the card is making', () => {
    it('says nothing extra on an absolute find', () => {
        expect(card().find('.find__lane').exists()).toBe(false)
    })

    it('explains a relative find, because its price does not speak for itself', () => {
        const wrapper = card({
            code: 'AMS-DUB',
            lane: 'relative',
            origin: { iata: 'AMS', city: 'Amsterdam', country: 'Netherlands' },
            destination: { iata: 'DUB', city: 'Dublin', country: 'Ireland' },
            price: 60,
        })

        expect(wrapper.find('.find__lane').text()).toBe('Rare price for this route')
    })

    // `.find__from` stays route-pair-only — e2e reads it to navigate.
    it('leaves the route pair alone', () => {
        const wrapper = card({ lane: 'relative' })

        expect(wrapper.find('.find__from').text()).toBe('DUS → AGP')
    })

    // An unknown lane says less, not more — the safe default.
    it('says nothing when it does not recognise the lane', () => {
        expect(card({ lane: undefined }).find('.find__lane').exists()).toBe(false)
    })
})
