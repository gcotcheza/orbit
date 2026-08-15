// @vitest-environment jsdom
// =============================================================================
// The boarding pass, and the one thing it did not do
// =============================================================================
// The card draws itself from a `GET /api/watchlist` row and has three jobs: it
// opens that route's detail screen, it pauses the route, and it removes it. The
// first of those is new — the design gave the card the switch and the bin and
// no way into /route/AMS-LIS, so a row you could see and name did nothing when
// it was tapped.
//
// WHAT IS ACTUALLY UNDER TEST IS THE BOUNDARY. Navigation and the two controls
// share one small card, and the failure worth catching is not "the link is
// missing" — it is a link that swallowed the switch, so that pausing a route
// also opened it, or a stop-propagation that silently stopped the toggle from
// reaching the screen. Both are asserted below as structure (what is INSIDE the
// link) rather than as behaviour, because that is what the fix is: the link
// stops at the tear line and the controls are its siblings.
//
// `RouterLinkStub` rather than a real router, exactly as Home.test.js does for
// the spotlight card: the assertion is the `to` this component hands the router,
// not vue-router's ability to honour it.
// =============================================================================
import { describe, expect, it } from 'vitest'
import { RouterLinkStub, mount } from '@vue/test-utils'

import WatchRow from './WatchRow.vue'

/** docs/API.md's own example row. */
const LIS = {
    code: 'AMS-LIS',
    origin: { iata: 'AMS', city: 'Amsterdam', country: 'Netherlands', countryCode: 'NL' },
    destination: { iata: 'LIS', city: 'Lisbon', country: 'Portugal', countryCode: 'PT' },
    price: { current: 74, usual: 111, pctBelow: 33 },
    score: 65,
    tier: 'great',
    confident: true,
    verdict: { label: 'Good price — book', short: 'Good', tone: 'good' },
    sparkline: [65, 68, 71, 74],
    trackingDays: 60,
    active: true,
}

function pass(route = {}) {
    return mount(WatchRow, {
        props: { route: { ...LIS, ...route } },
        global: { stubs: { RouterLink: RouterLinkStub } },
    })
}

const link = (wrapper) => wrapper.findComponent(RouterLinkStub)

describe('opening the route', () => {
    it('points at the detail screen for the route it is drawing', () => {
        expect(link(pass()).props('to')).toEqual({ name: 'route-detail', params: { id: 'AMS-LIS' } })
    })

    it('opens a paused route as readily as a watched one', () => {
        // Watchlist.vue dims a paused row and leaves it in the list precisely so
        // it can still be reached; a card that stopped being tappable when the
        // switch went off would put its own detail screen out of reach.
        const wrapper = pass({ active: false })

        expect(link(wrapper).props('to')).toEqual({ name: 'route-detail', params: { id: 'AMS-LIS' } })
    })

    it('says what it opens rather than reciting the card', () => {
        expect(link(pass()).attributes('aria-label')).toBe('Open AMS-LIS')
    })

    it('shows a chevron, so that there is something to say it is tappable', () => {
        expect(pass().get('.pass__open').find('.chevron').exists()).toBe(true)
    })
})

describe('the controls are not in the link', () => {
    it('leaves the switch and the bin outside it', () => {
        const wrapper = pass()
        const open = wrapper.get('.pass__open')

        // The whole point: a switch inside a link is nested interactives, and a
        // tap that lands on it would both flip the route and navigate away from
        // the screen that shows the result.
        expect(open.find('[role="switch"]').exists()).toBe(false)
        expect(open.find('.stub__remove').exists()).toBe(false)
        // …and there is only ever the one link on the card.
        expect(wrapper.findAllComponents(RouterLinkStub)).toHaveLength(1)
    })

    it('leaves the remove confirmation outside it too', async () => {
        const wrapper = pass()

        await wrapper.get('.stub__remove').trigger('click')

        expect(wrapper.get('.confirm').text()).toContain('Stop watching AMS-LIS?')
        expect(wrapper.get('.pass__open').find('.confirm').exists()).toBe(false)
    })
})

describe('the controls still work', () => {
    it('asks to be paused, and asks for the state it is going to', async () => {
        const wrapper = pass()

        await wrapper.get('[role="switch"]').trigger('click')

        expect(wrapper.emitted('toggle')).toEqual([[false]])
    })

    it('asks to be removed only after the question is answered', async () => {
        const wrapper = pass()

        await wrapper.get('.stub__remove').trigger('click')
        expect(wrapper.emitted('remove')).toBeUndefined()

        await wrapper.get('.confirm__button--go').trigger('click')
        expect(wrapper.emitted('remove')).toHaveLength(1)
    })
})

/*
 * ============================================================================
 * THE STUB'S ONE LINE OF PROSE
 * ============================================================================
 * A mature, watched route shows the barcode, which is set dressing. Anything
 * else the row has to SAY about what Orbit is doing with it goes in that slot —
 * and a paused route said nothing at all. The cues were an opacity of 0.58,
 * which reads as "loading" at least as readily as "off", and a switch somebody
 * has to already know the meaning of.
 */
describe('what the stub says', () => {
    it('says nothing but the barcode once a route is established', () => {
        const wrapper = pass()

        expect(wrapper.find('.stub__tracking').exists()).toBe(false)
        expect(wrapper.find('.stub__barcode').exists()).toBe(true)
    })

    it('counts the mornings while a route is still new', () => {
        expect(pass({ trackingDays: 0 }).get('.stub__tracking').text()).toBe('Waiting for the first fare')
        expect(pass({ trackingDays: 1 }).get('.stub__tracking').text()).toBe('Tracking 1 day')
        expect(pass({ trackingDays: 5 }).get('.stub__tracking').text()).toBe('Tracking 5 days')
    })

    it('says "Paused" when the switch is off', () => {
        const wrapper = pass({ active: false })

        expect(wrapper.get('.stub__tracking').text()).toBe('Paused')
        expect(wrapper.find('.stub__barcode').exists()).toBe(false)
    })

    /*
     * Both are true of a route paused on the day it was added, and only one of
     * them matters then: "Tracking 1 day" is a promise about tomorrow morning
     * that a paused route is not going to keep.
     */
    it('says "Paused" rather than counting days a paused route will not have', () => {
        expect(pass({ active: false, trackingDays: 1 }).get('.stub__tracking').text()).toBe('Paused')
    })
})
