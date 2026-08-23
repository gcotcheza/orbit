// @vitest-environment jsdom
// The landing header, now that the phone branch and the frame's master pane draw the same one
// (docs/DESKTOP-LAYOUT-PLAN.md phase 2).
import { describe, expect, it } from 'vitest'
import { RouterLinkStub, mount } from '@vue/test-utils'

import HomeHeader from './HomeHeader.vue'

function header(props = {}) {
    return mount(HomeHeader, {
        props: { greeting: 'Good morning', ...props },
        global: { stubs: { RouterLink: RouterLinkStub } },
    })
}

describe('the landing header', () => {
    it('says what it is tracking and who it is talking to', () => {
        const wrapper = header()

        expect(wrapper.get('.home__eyebrow').text()).toBe('Tracking live')
        expect(wrapper.get('.home__greeting').text()).toBe('Good morning')
        // The pulsing dot is the "live" half of that claim, and it is not decoration.
        expect(wrapper.find('.home__live').exists()).toBe(true)
    })

    it('carries the account link on the phone', () => {
        const link = header({ profile: true }).getComponent(RouterLinkStub)

        expect(link.props('to')).toEqual({ name: 'alerts', hash: '#account' })
        expect(link.attributes('aria-label')).toBe('Your account and alert settings')
    })

    // Inside the frame the rail already has one, and two links of the same name to the same place
    // is one too many.
    it('leaves it out by default', () => {
        expect(header().find('.home__profile').exists()).toBe(false)
    })
})
