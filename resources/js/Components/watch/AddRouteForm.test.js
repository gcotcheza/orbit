// @vitest-environment jsdom
// =============================================================================
// The add-route form, and the typeahead it grew
// =============================================================================
// The box used to take three letters and nothing else, which quietly required
// the person using it to know that Bilbao is BIO. It now suggests as you type,
// and the two halves of that are tested differently:
//
//   - THE RANKING IS A PURE FUNCTION and is tested as one. "por" meaning Porto
//     rather than Portugal is an opinion, and an opinion is worth a test that
//     does not mount anything to state it.
//   - THE FORM IS TESTED FOR ITS BEHAVIOUR — what a click does, what Enter
//     does, and the two things that must NOT have changed: a raw three-letter
//     code still goes straight through, and the list is fetched once rather
//     than per keystroke.
//
// WHAT jsdom CANNOT HOLD, and so is left to the browser gate: whether a tap on
// a suggestion beats the blur that would close the list (the classic focus
// race — `@mousedown.prevent` is the fix and jsdom dispatches neither in
// anger), and whether the panel is actually on top of the button it covers.
// Both are in e2e/specs/watchlist.spec.js.
// =============================================================================
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()

vi.mock('@/lib/http', () => ({ http: { get: (...args) => get(...args) } }))

import AddRouteForm from './AddRouteForm.vue'
import { fold, searchDestinations } from '@/stores/destinations'

const BIO = { iata: 'BIO', city: 'Bilbao', country: 'Spain', countryCode: 'ES' }
const OPO = { iata: 'OPO', city: 'Porto', country: 'Portugal', countryCode: 'PT' }
const LIS = { iata: 'LIS', city: 'Lisbon', country: 'Portugal', countryCode: 'PT' }
const AGP = { iata: 'AGP', city: 'Málaga', country: 'Spain', countryCode: 'ES' }
const LPA = { iata: 'LPA', city: 'Las Palmas', country: 'Spain', countryCode: 'ES' }

const ALL = [BIO, LPA, LIS, AGP, OPO]

const codes = (results) => results.map((result) => result.iata)

// -----------------------------------------------------------------------------
// The ranking
// -----------------------------------------------------------------------------

describe('searchDestinations', () => {
    it('says nothing about an empty box', () => {
        expect(searchDestinations(ALL, '')).toEqual([])
        expect(searchDestinations(ALL, '   ')).toEqual([])
    })

    it('finds a city by the start of its name', () => {
        expect(codes(searchDestinations(ALL, 'bilb'))).toEqual(['BIO'])
    })

    it('puts the city ahead of the country that contains it', () => {
        // "por" is the start of Porto and the start of Portugal, and somebody
        // typing it in a destination box means the city.
        expect(codes(searchDestinations(ALL, 'por'))).toEqual(['OPO', 'LIS'])
    })

    it('puts an airport code ahead of everything', () => {
        // LIS is Lisbon's code; it is also the middle of nothing else here, so
        // add a decoy that only a substring rule would find.
        const results = searchDestinations([{ ...OPO, city: 'Lisbon-ish' }, LIS], 'lis')

        expect(codes(results)).toEqual(['LIS', 'OPO'])
    })

    it('finds a word inside a name, not only the first one', () => {
        expect(codes(searchDestinations(ALL, 'palmas'))).toEqual(['LPA'])
    })

    it('does not care about accents in either direction', () => {
        expect(codes(searchDestinations(ALL, 'malaga'))).toEqual(['AGP'])
        expect(codes(searchDestinations(ALL, 'MÁLAGA'))).toEqual(['AGP'])
    })

    it('never shows more than it was asked for', () => {
        expect(searchDestinations(ALL, 'a', 2)).toHaveLength(2)
    })

    /*
     * The highlight is index arithmetic over a folded string and rendered
     * against the original, so an accent anywhere before the match is exactly
     * where it would go wrong — "Málaga" is six characters and its folded form
     * has to be six too, or the bold run starts a letter early.
     */
    it('marks the matched run in the original spelling', () => {
        const [malaga] = searchDestinations(ALL, 'laga')

        expect(malaga.marks.city).toEqual({ before: 'Má', match: 'laga', after: '' })

        const [bilbao] = searchDestinations(ALL, 'bi')

        expect(bilbao.marks.city).toEqual({ before: '', match: 'Bi', after: 'lbao' })
        // A field the query is not in keeps its text and marks nothing.
        expect(bilbao.marks.country).toEqual({ before: 'Spain', match: '', after: '' })
    })

    it('folds one character to one character, whatever it is', () => {
        for (const word of ['Málaga', 'Düsseldorf', 'Reykjavík', 'Lisbon', 'Las Palmas']) {
            expect(fold(word)).toHaveLength(word.length)
        }
    })
})

// -----------------------------------------------------------------------------
// The form
// -----------------------------------------------------------------------------

async function form(destinations = ALL, options = {}) {
    get.mockResolvedValue({ data: { data: destinations, meta: { count: destinations.length } } })

    const wrapper = mount(AddRouteForm, { ...options, global: { plugins: [createPinia()] } })

    await flushPromises()

    return wrapper
}

const box = (wrapper) => wrapper.get('#add-destination')
const options = (wrapper) => wrapper.findAll('[role="option"]')

/** A real event, so `defaultPrevented` can be read back off it. */
async function press(wrapper, key) {
    const event = new KeyboardEvent('keydown', { key, cancelable: true, bubbles: true })

    box(wrapper).element.dispatchEvent(event)
    await flushPromises()

    return event
}

beforeEach(() => {
    vi.clearAllMocks()
})

describe('the destination typeahead', () => {
    it('asks for the list once, however much is typed', async () => {
        const wrapper = await form()

        await box(wrapper).setValue('b')
        await box(wrapper).setValue('bi')
        await box(wrapper).setValue('bil')

        expect(get).toHaveBeenCalledTimes(1)
        expect(get).toHaveBeenCalledWith('/api/destinations')
    })

    it('suggests nothing until something is typed', async () => {
        const wrapper = await form()

        expect(wrapper.get('.options').isVisible()).toBe(false)
        expect(box(wrapper).attributes('aria-expanded')).toBe('false')
    })

    it('offers the match, spelled out', async () => {
        const wrapper = await form()

        await box(wrapper).setValue('bilb')

        expect(options(wrapper)).toHaveLength(1)
        expect(options(wrapper)[0].text()).toContain('Bilbao')
        expect(options(wrapper)[0].text()).toContain('BIO')
        expect(options(wrapper)[0].text()).toContain('Spain')
        expect(box(wrapper).attributes('aria-expanded')).toBe('true')
    })

    it('fills the box with the code when a suggestion is taken', async () => {
        const wrapper = await form()

        await box(wrapper).setValue('bilb')
        await options(wrapper)[0].trigger('click')

        expect(box(wrapper).element.value).toBe('BIO')
        expect(wrapper.get('.options').isVisible()).toBe(false)
    })

    it('says so when there is nothing to suggest', async () => {
        const wrapper = await form()

        await box(wrapper).setValue('zzz')

        expect(options(wrapper)).toHaveLength(0)
        expect(wrapper.get('.option--empty').text()).toBe('No matching destination.')
    })

    /*
     * A DEFECT THE BROWSER GATE FOUND, written down here as well because the
     * shape of it is testable even though the symptom was not.
     *
     * The list used to close on the input's `@blur`. Blur fires on MOUSEDOWN,
     * the panel is in the flow, and removing it moves the Add button ~50 px up
     * — so the mouseup landed on empty space and the press never became a
     * click. The button was unpressable whenever there were suggestions.
     *
     * What is asserted is the rule that replaced it: focus moving INSIDE the
     * form leaves the list alone, focus leaving the form closes it. jsdom
     * cannot show the missed click; it can hold the rule that prevents it.
     */
    it('stays open while focus moves to the button that sends it', async () => {
        // `attachTo`, uniquely in this file: focus events on a tree that is not
        // in a document are not the events this is about.
        const wrapper = await form(ALL, { attachTo: document.body })

        await box(wrapper).setValue('bilb')

        const leave = (relatedTarget) => {
            box(wrapper).element.dispatchEvent(new FocusEvent('focusout', { bubbles: true, relatedTarget }))

            return flushPromises()
        }

        await leave(wrapper.get('.add__submit').element)
        expect(wrapper.get('.options').isVisible()).toBe(true)

        // And closes when focus leaves the form for good.
        await leave(null)
        expect(wrapper.get('.options').isVisible()).toBe(false)

        wrapper.unmount()
    })

    it('closes on Escape and leaves what was typed alone', async () => {
        const wrapper = await form()

        await box(wrapper).setValue('bilb')
        await press(wrapper, 'Escape')

        expect(wrapper.get('.options').isVisible()).toBe(false)
        expect(box(wrapper).element.value).toBe('BILB')
    })

    it('walks the list with the arrow keys and takes one with Enter', async () => {
        const wrapper = await form()

        await box(wrapper).setValue('por')
        expect(options(wrapper)).toHaveLength(2)

        // Nothing is highlighted until the keyboard says so.
        expect(box(wrapper).attributes('aria-activedescendant')).toBeUndefined()

        await press(wrapper, 'ArrowDown')
        await press(wrapper, 'ArrowDown')

        expect(box(wrapper).attributes('aria-activedescendant')).toBe('add-destination-option-1')
        expect(options(wrapper)[1].attributes('aria-selected')).toBe('true')

        const enter = await press(wrapper, 'Enter')

        expect(enter.defaultPrevented).toBe(true)
        // The second of Porto and Lisbon.
        expect(box(wrapper).element.value).toBe('LIS')
    })

    it('takes the first suggestion when Enter comes before any arrow key', async () => {
        const wrapper = await form()

        await box(wrapper).setValue('bilb')
        const enter = await press(wrapper, 'Enter')

        expect(enter.defaultPrevented).toBe(true)
        expect(box(wrapper).element.value).toBe('BIO')
    })

    /*
     * THE PATH THAT EXISTED BEFORE ANY OF THIS. Somebody who knows the code
     * types it and presses Enter, and the form submits rather than making them
     * accept a suggestion that says the same thing back to them.
     */
    it('leaves Enter alone when the box already holds a code', async () => {
        const wrapper = await form()

        await box(wrapper).setValue('lis')
        const enter = await press(wrapper, 'Enter')

        expect(enter.defaultPrevented).toBe(false)
        expect(box(wrapper).element.value).toBe('LIS')
    })

    it('still sends a typed code, upper-cased, with the chosen origin', async () => {
        const wrapper = await form()

        await wrapper.findAll('[role="radio"]')[1].trigger('click')
        await box(wrapper).setValue('lis')
        await wrapper.get('form').trigger('submit')

        expect(wrapper.emitted('submit')).toEqual([[{ origin: 'EIN', destination: 'LIS' }]])
    })

    it('refuses to send a half-typed place name', async () => {
        const wrapper = await form()

        await box(wrapper).setValue('bilb')
        await wrapper.get('form').trigger('submit')

        expect(wrapper.emitted('submit')).toBeUndefined()
        expect(wrapper.get('.add__error').text()).toContain('Pick a destination from the list')
    })

    /*
     * The strip that keeps a code field a code field had to widen when the box
     * started taking place names — and the half that mattered, digits, has a
     * defect behind it (see the component, and the browser test that reads the
     * ELEMENT back rather than the model).
     */
    it('keeps letters, spaces and accents, and still drops digits', async () => {
        const wrapper = await form()

        await box(wrapper).setValue('las palmas')
        expect(box(wrapper).element.value).toBe('LAS PALMAS')

        await box(wrapper).setValue('málaga')
        expect(box(wrapper).element.value).toBe('MÁLAGA')

        await box(wrapper).setValue('l1s')
        expect(box(wrapper).element.value).toBe('LS')
    })

    /*
     * A list that never arrives is not an outage: the box is exactly the box it
     * was before the typeahead existed, and it says so rather than claiming
     * there is no such place.
     */
    it('still takes a code when the suggestions could not be loaded', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {})
        get.mockRejectedValue(new Error('offline'))

        const wrapper = mount(AddRouteForm, { global: { plugins: [createPinia()] } })
        await flushPromises()

        await box(wrapper).setValue('lis')
        expect(wrapper.get('.option--empty').text()).toContain('a three-letter code still works')

        await wrapper.get('form').trigger('submit')
        expect(wrapper.emitted('submit')).toEqual([[{ origin: 'AMS', destination: 'LIS' }]])
    })
})
