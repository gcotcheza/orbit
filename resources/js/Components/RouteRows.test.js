// @vitest-environment jsdom
// The master pane's list, shared by three screens — the rules the frame's selection depends on
// (docs/DESKTOP-LAYOUT-PLAN.md phase 2).
import { afterEach, describe, expect, it } from 'vitest'
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

// One tab stop for the list and the arrows to walk it, which is what a tab list owes a keyboard
// (WAI-ARIA "Tabs with Manual Activation" — see docs/BUSINESS-LOGIC.md §36).
describe('the keyboard', () => {
    const THREE = [route('AMS-LIS', 74), route('AMS-OPO', 61), route('AMS-NAP', 88)]

    let wrapper

    const attached = (props = {}) => mount(RouteRows, {
        props: { routes: THREE, label: 'Watched routes', ...props },
        attachTo: document.body,
    })

    const codeOf = (element) => element?.dataset?.code ?? null

    const stops = () => wrapper.findAll('.route-row').map((row) => row.attributes('tabindex'))

    afterEach(() => wrapper?.unmount())

    it('offers one tab stop, and it is the row whose pane is on screen', () => {
        wrapper = attached({ active: 'AMS-OPO' })

        expect(stops()).toEqual(['-1', '0', '-1'])
    })

    it('walks the list on Down and Up, and wraps at both ends', async () => {
        wrapper = attached({ active: 'AMS-LIS' })
        const rows = wrapper.findAll('.route-row')

        await rows[0].trigger('keydown', { key: 'ArrowDown' })
        expect(codeOf(document.activeElement)).toBe('AMS-OPO')
        expect(stops()).toEqual(['-1', '0', '-1'])

        await rows[1].trigger('keydown', { key: 'ArrowUp' })
        expect(codeOf(document.activeElement)).toBe('AMS-LIS')

        await rows[0].trigger('keydown', { key: 'ArrowUp' })
        expect(codeOf(document.activeElement)).toBe('AMS-NAP')

        await rows[2].trigger('keydown', { key: 'ArrowRight' })
        expect(codeOf(document.activeElement)).toBe('AMS-LIS')
    })

    it('takes Home and End to the ends', async () => {
        wrapper = attached({ active: 'AMS-LIS' })
        const rows = wrapper.findAll('.route-row')

        await rows[0].trigger('keydown', { key: 'End' })
        expect(codeOf(document.activeElement)).toBe('AMS-NAP')

        await rows[2].trigger('keydown', { key: 'Home' })
        expect(codeOf(document.activeElement)).toBe('AMS-LIS')
    })

    // Enter and Space are the button's own, and they are what chooses a route.
    it('leaves selection to the press, so arrowing past a route does not fetch it', async () => {
        wrapper = attached({ active: 'AMS-LIS' })

        await wrapper.findAll('.route-row')[0].trigger('keydown', { key: 'ArrowDown' })

        expect(wrapper.emitted('select')).toBeUndefined()
    })

    /* `roving` outlives the row that set it: a list that shrinks under it would otherwise leave the
       list's one tab stop on a row nobody can reach. */
    it('clamps the tab stop when the list shrinks under it', async () => {
        wrapper = attached({ active: 'AMS-LIS' })

        await wrapper.findAll('.route-row')[0].trigger('keydown', { key: 'End' })
        expect(stops()).toEqual(['-1', '-1', '0'])

        await wrapper.setProps({ routes: THREE.slice(0, 2) })

        expect(stops()).toEqual(['-1', '0'])
    })

    // Nothing swaps for these, so they are three ordinary buttons and Tab reaches each of them.
    it('leaves a group of toggles alone', async () => {
        wrapper = attached({ active: 'AMS-LIS', kind: 'group' })
        const rows = wrapper.findAll('.route-row')

        expect(stops()).toEqual([undefined, undefined, undefined])

        rows[0].element.focus()
        await rows[0].trigger('keydown', { key: 'ArrowDown' })

        expect(codeOf(document.activeElement)).toBe('AMS-LIS')
    })
})
