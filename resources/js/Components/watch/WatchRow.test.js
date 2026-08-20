// @vitest-environment jsdom
// WatchRow: the boarding-pass card's link, switch and remove button.

// Added the missing hand-off into the route's detail screen (the switch and bin existed; opening the route from the
// card did not) (docs/BUSINESS-LOGIC.md §36).

// Tests assert the link/controls boundary as structure, not behaviour — the risk is a link that swallowed the switch,
// or a stopPropagation that silently breaks the toggle (docs/BUSINESS-LOGIC.md §36).

// RouterLinkStub, not a real router (as Home.test.js does for the spotlight card): asserts the `to` prop this
// component hands the router, not vue-router itself (docs/BUSINESS-LOGIC.md §36).
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
        // Paused rows stay dimmed but tappable (Watchlist.vue); losing that would hide the detail screen behind the switch
        // going off (docs/BUSINESS-LOGIC.md §36).
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

        // The whole point: nested interactives — a switch inside the link — would let one tap both flip the route and navigate
        // away (docs/BUSINESS-LOGIC.md §36).
        expect(open.find('[role="switch"]').exists()).toBe(false)
        expect(open.find('.stub__remove').exists()).toBe(false)
        // There is only ever one link on the card.
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

// The barcode is set dressing; the tracking-text slot is the only place the row says what's happening — a paused route
// previously said nothing (just a dimmed switch) (docs/BUSINESS-LOGIC.md §36).
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

    // Paused-and-day-one both apply here; "Paused" wins since "Tracking 1 day" promises a morning check a paused route
    // won't run (docs/BUSINESS-LOGIC.md §36).
    it('says "Paused" rather than counting days a paused route will not have', () => {
        expect(pass({ active: false, trackingDays: 1 }).get('.stub__tracking').text()).toBe('Paused')
    })
})
