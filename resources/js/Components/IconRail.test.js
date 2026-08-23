// @vitest-environment jsdom
// The rail is the tab bar's five destinations, upright — that it is the SAME five, in the same
// order, is the assertion (docs/DESKTOP-LAYOUT-PLAN.md).
import { describe, expect, it } from 'vitest'
import { RouterLinkStub, mount } from '@vue/test-utils'
import IconRail from './IconRail.vue'
import TabBar from './TabBar.vue'

const mountWith = (component) =>
    mount(component, { global: { stubs: { RouterLink: RouterLinkStub } } })

const destinations = (wrapper, selector) =>
    wrapper.findAll(selector).map((item) => item.getComponent(RouterLinkStub).props('to').name)

describe('the icon rail', () => {
    it('carries the tab bar\'s five destinations, in its order', () => {
        expect(destinations(mountWith(IconRail), '.rail-nav__item')).toEqual(
            destinations(mountWith(TabBar), '.tab'),
        )
    })

    it('labels them as the bar does, and answers to the same name', () => {
        const wrapper = mountWith(IconRail)

        expect(wrapper.findAll('.rail-nav__item').map((item) => item.text())).toEqual([
            'Orbit',
            'Calendar',
            'Search',
            'Watch',
            'Alerts',
        ])
        expect(wrapper.get('nav').attributes('aria-label')).toBe('Primary')
    })

    // Search keeps the raised accent button it has in the bar, and only Search has one.
    it('keeps Search as the one filled button', () => {
        const wrapper = mountWith(IconRail)

        expect(wrapper.findAll('.rail-nav__button')).toHaveLength(1)
        expect(wrapper.get('.rail-nav__item--search').find('.rail-nav__button').exists()).toBe(true)
    })

    it('puts the account at the foot of the rail', () => {
        const profile = mountWith(IconRail).get('.rail-nav__profile').getComponent(RouterLinkStub)

        expect(profile.props('to')).toEqual({ name: 'alerts', hash: '#account' })
    })
})
