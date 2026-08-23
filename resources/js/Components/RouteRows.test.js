// @vitest-environment jsdom
// The master pane's list, shared by three screens — the rules the frame's selection depends on
// (docs/DESKTOP-LAYOUT-PLAN.md phase 2).
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import RouteRows from './RouteRows.vue'

const route = (code, price, active = true) => ({
    code,
    origin: { iata: code.slice(0, 3), city: 'Amsterdam' },
    destination: { iata: code.slice(4), city: 'Lisbon' },
    price: { current: price },
    verdict: { tone: 'good' },
    active,
})

const ROUTES = [route('AMS-LIS', 74), route('AMS-OPO', null, false)]

function rows(props = {}) {
    return mount(RouteRows, { props: { routes: ROUTES, label: 'Watched routes', ...props } })
}

describe('the master pane rows', () => {
    it('names the list and marks the chosen row selected', () => {
        const wrapper = rows({ active: 'AMS-OPO' })
        const all = wrapper.findAll('.route-row')

        expect(wrapper.get('.route-rows').attributes('aria-label')).toBe('Watched routes')
        expect(all[1].classes()).toContain('route-row--active')
        expect(all[1].attributes('aria-selected')).toBe('true')
        expect(all[0].attributes('aria-selected')).toBe('false')
    })

    it('reports the code that was pressed', async () => {
        const wrapper = rows()

        await wrapper.findAll('.route-row')[1].trigger('click')

        expect(wrapper.emitted('select')).toEqual([['AMS-OPO']])
    })

    // An em dash, never €0: a missing price is not a free flight. A paused route stays readable.
    it('dims a paused route and refuses to invent its fare', () => {
        const paused = rows().findAll('.route-row')[1]

        expect(paused.classes()).toContain('route-row--paused')
        expect(paused.get('.route-row__price').text()).toBe('—')
    })
})

// The watch list's rows mark one of a list that is already on screen; they do not swap a pane, so
// they are pressed rather than selected (docs/BUSINESS-LOGIC.md §36).
describe('a group rather than a tab list', () => {
    it('says pressed, not selected', () => {
        const wrapper = rows({ active: 'AMS-LIS', kind: 'group' })
        const all = wrapper.findAll('.route-row')

        expect(wrapper.get('.route-rows').attributes('role')).toBe('group')
        expect(all[0].attributes('role')).toBeUndefined()
        expect(all[0].attributes('aria-pressed')).toBe('true')
        expect(all[1].attributes('aria-pressed')).toBe('false')
        expect(all[0].attributes('aria-selected')).toBeUndefined()
    })

    it('is a tab list by default, where a pane really does swap', () => {
        const wrapper = rows({ active: 'AMS-LIS' })

        expect(wrapper.get('.route-rows').attributes('role')).toBe('tablist')
        expect(wrapper.findAll('.route-row')[0].attributes('role')).toBe('tab')
        expect(wrapper.findAll('.route-row')[0].attributes('aria-pressed')).toBeUndefined()
    })
})

