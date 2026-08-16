// @vitest-environment jsdom
// =============================================================================
// The discovery card — and the badge, which is the whole feature in one span
// =============================================================================
// Most of what this component does is print fields. The part worth testing is
// the part that makes a CLAIM: a card that says "verified low by Google" when
// Google was never asked, or that reads as a warning when nothing is wrong, is
// the difference between a feature the owner trusts and one they learn to
// ignore.
//
// THE TWO BADGE STATES ARE NOT A GOOD/BAD PAIR. Unverified is the ORDINARY
// state — no SERPAPI_KEY is the default on this box — so it must be quiet, not
// yellow. That is a rendering fact and jsdom can only see the class/attribute;
// the colour itself is checked by eye in the browser gate's screenshots.
// =============================================================================
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

    /*
     * THE HAND-OFF, AND IT IS THE REUSE THIS WHOLE FEATURE RESTS ON. A card
     * links into `/route/DUS-AGP` — the existing lookup flow, which prices the
     * pair and offers the watch button. Nothing here books, and nothing here
     * watches.
     */
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
     * THE SENTENCE IS THE SERVER'S. A hard-coded "Verified low by Google" in
     * this template is a claim that goes on being made the day the check behind
     * it is switched off.
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

    /*
     * A VERIFICATION STAGE THAT LEARNED NOTHING SAYS NOTHING. Travelpayouts'
     * calendar coverage runs 41–87% even on watched routes, and a discovery is
     * by definition an obscure pair — so a null window is the ordinary outcome
     * and the honest rendering is no line at all, not "0%".
     */
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

    /*
     * NULL RENDERS AS NOTHING AT ALL AND NEVER AS FRESH — the rule the whole
     * `foundAt` field exists to enforce. A discovery should never reach the
     * screen without one (DiscoveryPolicy discards unknown ages), and the card
     * must not be the thing that breaks if that is ever retuned.
     */
    it('says nothing at all rather than guessing', () => {
        expect(card({ foundAt: null }).find('.find__seen').exists()).toBe(false)
    })
})

/*
 * ============================================================================
 * WHICH LANE — and why only one of the two says anything
 * ============================================================================
 * An ABSOLUTE find needs no explanation: "€29 to Málaga" is remarkable against
 * every fare in the sweep and the price is the whole sentence. A RELATIVE find
 * is by construction ORDINARY per kilometre — that is exactly what disqualified
 * it from the other lane — so without a word of context the reader is right to
 * wonder what a €60 Dublin is doing on a strip of insane fares.
 *
 * THE LINE IS A CLAIM ABOUT WHICH COMPARISON WAS MADE, not decoration, which is
 * why it is asserted here rather than left to the screenshot: the colour is a
 * browser-gate question, the SENTENCE is a correctness one.
 */
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

    /*
     * THE ROUTE PAIR STAYS IN ITS OWN ELEMENT AND KEEPS ITS OWN TEXT. The lane
     * line is a SIBLING, not text folded into the eyebrow — the e2e journey
     * reads `.find__from` to derive which route to navigate to, and a card that
     * appended a sentence there would break the one card type that needs it
     * least.
     */
    it('leaves the route pair alone', () => {
        const wrapper = card({ lane: 'relative' })

        expect(wrapper.find('.find__from').text()).toBe('DUS → AGP')
    })

    /*
     * AN UNKNOWN LANE IS TREATED AS ABSOLUTE — i.e. the card says LESS rather
     * than more. A client reading an older or newer API than it expects must
     * never invent the stronger claim; silence is the safe direction here, and
     * it is the same call `foundAt: null` makes two blocks up.
     */
    it('says nothing when it does not recognise the lane', () => {
        expect(card({ lane: undefined }).find('.find__lane').exists()).toBe(false)
    })
})
