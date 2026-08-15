// @vitest-environment jsdom
// =============================================================================
// The day sheet's two actions
// =============================================================================
// The sheet's CONTENT — the date, the fare, the verdict pill — is covered by
// e2e/specs/calendar.spec.js against real data. What is checked here is the
// half that is a contract rather than a picture, and that a browser test can
// only observe by leaving the app:
//
//   - the booking link is aimed at the day that was TAPPED. It is built by
//     substituting `{date}` in the template the calendar endpoint sends, and
//     the failure mode is a URL that is well-formed, opens fine, and books a
//     different day — which no smoke test notices.
//   - it opens away from the app with `rel="noopener"` and WITHOUT
//     `noreferrer`, because the referrer is the affiliate attribution
//     (Components/route/BookingCta.vue says why at length).
//   - the details link goes to the route the month is for.
//   - a response with no template costs the sheet one action, not the sheet.
//
// `RouterLinkStub` rather than a real router: what is under test is the `to`
// that is handed over, and installing vue-router to read it back would be
// testing vue-router.
// =============================================================================
import { RouterLinkStub, mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import DaySheet from './DaySheet.vue'

const TEMPLATE = 'https://www.skyscanner.nl/transport/flights/ams/opo/{date}/'

function sheet(overrides = {}) {
    return mount(DaySheet, {
        props: {
            fare: { date: '2026-09-15', price: 44, verdict: 'cheap' },
            min: 40,
            max: 100,
            code: 'AMS-OPO',
            bookingUrlTemplate: TEMPLATE,
            ...overrides,
        },
        global: { stubs: { RouterLink: RouterLinkStub } },
    })
}

/** The outward link — the only anchor in the sheet that leaves the app. */
function booking(wrapper) {
    return wrapper.get('a[target="_blank"]')
}

describe('DaySheet', () => {
    it('books the day that was tapped, as six digits', () => {
        const wrapper = sheet()

        expect(booking(wrapper).attributes('href')).toBe(
            'https://www.skyscanner.nl/transport/flights/ams/opo/260915/',
        )
    })

    /*
     * The date is a calendar day with no zone (docs/API.md). Anything that
     * routes it through a `Date` re-reads it in the viewer's own timezone and
     * books the day before for half the planet — so the first of a month, which
     * is where that fault surfaces, is checked explicitly.
     */
    it('does not slide the date into another timezone', () => {
        const wrapper = sheet({ fare: { date: '2027-01-01', price: 61, verdict: 'mid' } })

        expect(booking(wrapper).attributes('href')).toContain('/270101/')
    })

    it('leaves the app with noopener and keeps the referrer', () => {
        const rel = booking(sheet()).attributes('rel')

        expect(rel).toContain('noopener')
        // The affiliate attribution rides on the referrer; see BookingCta.
        expect(rel).not.toContain('noreferrer')
    })

    it('points the other action at the route the month is for', () => {
        const link = sheet().findComponent(RouterLinkStub)

        expect(link.props('to')).toEqual({ name: 'route-detail', params: { id: 'AMS-OPO' } })
        expect(link.text()).toBe('Route details')
    })

    it('drops one action rather than the sheet when no template arrived', () => {
        const wrapper = sheet({ bookingUrlTemplate: null })

        expect(wrapper.findAll('a[target="_blank"]')).toHaveLength(0)
        // …and nothing is left promising a hand-off that cannot happen.
        expect(wrapper.text()).not.toContain("We don't sell tickets")

        expect(wrapper.findComponent(RouterLinkStub).exists()).toBe(true)
        expect(wrapper.get('.sheet__price').text()).toBe('€44')
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
