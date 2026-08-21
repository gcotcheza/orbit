// @vitest-environment jsdom
// The airport box and its typeahead — ranking, typo fallback and behaviour.
// The focus race and panel stacking are jsdom-blind; e2e/specs/search.spec.js.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()

vi.mock('@/lib/http', () => ({ http: { get: (...args) => get(...args) } }))

import AirportField from './AirportField.vue'
import { editDistance, fold, nearestDestination, searchDestinations } from '@/stores/destinations'
import { DEBOUNCE_MS } from '@/stores/airports'

const BIO = { iata: 'BIO', city: 'Bilbao', country: 'Spain', countryCode: 'ES' }
const OPO = { iata: 'OPO', city: 'Porto', country: 'Portugal', countryCode: 'PT' }
const LIS = { iata: 'LIS', city: 'Lisbon', country: 'Portugal', countryCode: 'PT' }
const AGP = { iata: 'AGP', city: 'Málaga', country: 'Spain', countryCode: 'ES' }
const LPA = { iata: 'LPA', city: 'Las Palmas', country: 'Spain', countryCode: 'ES' }

const ALL = [BIO, LPA, LIS, AGP, OPO]

const codes = (results) => results.map((result) => result.iata)

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
        // typing it in an airport box means the city.
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

    // Index arithmetic over a folded string, rendered against the original —
    // an accent anywhere before the match is exactly where it goes wrong.
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

// One wrong letter fails every rank above at once; edit distance is the way
// out (docs/BUSINESS-LOGIC.md §36).

const BCN = { iata: 'BCN', city: 'Barcelona', country: 'Spain', countryCode: 'ES' }

/** ALL plus a city far enough from the rest to be typo'd unambiguously. */
const WIDER = [...ALL, BCN]

describe('editDistance', () => {
    it('is zero for the same word and one per slip', () => {
        expect(editDistance('barcelona', 'barcelona')).toBe(0)
        // A dropped letter, a wrong letter, an extra one.
        expect(editDistance('barcelna', 'barcelona')).toBe(1)
        expect(editDistance('barcelona', 'barcelena')).toBe(1)
        expect(editDistance('barcelonaa', 'barcelona')).toBe(1)
    })

    it('counts a transposition as the two edits it is', () => {
        // Plain Levenshtein, not Damerau — and two is still inside the budget,
        // which is the whole reason the budget is two.
        expect(editDistance('bracelona', 'barcelona')).toBe(2)
    })

    it('gives up rather than measuring a distance nobody asked for', () => {
        // Over the budget, so the answer is only "further than max" — and it is
        // reached without walking the whole matrix.
        expect(editDistance('lisbon', 'barcelona')).toBe(3)
        expect(editDistance('zzz', 'barcelona')).toBe(3)
        expect(editDistance('barcelona', 'barcelona', 0)).toBe(0)
        expect(editDistance('barcelna', 'barcelona', 0)).toBe(1)
    })
})

describe('nearestDestination', () => {
    it('finds the city behind one wrong letter', () => {
        expect(nearestDestination(WIDER, 'barcelna').iata).toBe('BCN')
        expect(nearestDestination(WIDER, 'lisbn').iata).toBe('LIS')
        // Two edits is still a typo somebody makes.
        expect(nearestDestination(WIDER, 'bracelona').iata).toBe('BCN')
    })

    it('ignores accents and capitals, like the search does', () => {
        expect(nearestDestination(WIDER, 'MALGA').iata).toBe('AGP')
    })

    it('guesses nothing when there is nothing close', () => {
        expect(nearestDestination(WIDER, 'qwertyuiop')).toBeNull()
    })

    // Not fussiness: three characters are within two edits of half this list.
    it('refuses to guess from a query too short to mean anything', () => {
        expect(nearestDestination(WIDER, 'bar')).toBeNull()
        expect(nearestDestination(WIDER, 'zzz')).toBeNull()
    })
})

const ID = 'search-to'

/**
 * The field, mounted, plays the parent too: `modelValue` and `open` are wired
 * back through `setProps`, as Views/Search.vue does (docs/BUSINESS-LOGIC.md §36).
 *
 * @param {Array<object>} destinations `GET /api/destinations`
 * @param {{world?: Array<object>}} props `world` aside, the component's own
 */
async function field(destinations = ALL, { world = [], ...props } = {}) {
    get.mockImplementation((url) => Promise.resolve(url === '/api/destinations'
        ? { data: { data: destinations, meta: { count: destinations.length } } }
        : { data: { data: world, meta: { count: world.length } } }))

    // A holder, so the handlers below can name the wrapper they are mounted in.
    const held = {}

    held.wrapper = mount(AirportField, {
        props: {
            id: ID,
            label: 'To',
            listLabel: 'Destination suggestions',
            modelValue: '',
            open: false,
            ...props,
            'onUpdate:modelValue': (next) => held.wrapper.setProps({ modelValue: next }),
            onOpen: () => held.wrapper.setProps({ open: true }),
            onClose: () => held.wrapper.setProps({ open: false }),
        },
        global: { plugins: [createPinia()] },
    })

    await flushPromises()

    return held.wrapper
}

// Let the world search's debounce fire. A test that skips this never
// makes the second request — deliberate: the curated list is instant.
async function settle() {
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)
    await flushPromises()
}

const box = (wrapper) => wrapper.get(`#${ID}`)
const options = (wrapper) => wrapper.findAll('[role="option"]')

/** The suggestion rows, as the codes they print — the guess row is not one. */
const suggestionRows = (wrapper) => wrapper.findAll('.option:not(.option--guess):not(.option--empty)')
    .map((row) => ({ iata: row.get('.option__code').text() }))

/** A real event, so `defaultPrevented` can be read back off it. */
async function press(wrapper, key) {
    const event = new KeyboardEvent('keydown', { key, cancelable: true, bubbles: true })

    box(wrapper).element.dispatchEvent(event)
    await flushPromises()

    return event
}

beforeEach(() => {
    vi.useFakeTimers()
    vi.clearAllMocks()
})

afterEach(() => {
    vi.useRealTimers()
})

describe('the airport typeahead', () => {
    it('asks for the list once, however much is typed', async () => {
        const wrapper = await field()

        await box(wrapper).setValue('b')
        await box(wrapper).setValue('bi')
        await box(wrapper).setValue('bil')

        expect(get).toHaveBeenCalledTimes(1)
        expect(get).toHaveBeenCalledWith('/api/destinations')
    })

    it('suggests nothing until something is typed', async () => {
        const wrapper = await field()

        expect(box(wrapper).attributes('aria-expanded')).toBe('false')
    })

    it('offers the match, spelled out', async () => {
        const wrapper = await field()

        await box(wrapper).setValue('bilb')

        expect(options(wrapper)).toHaveLength(1)
        expect(options(wrapper)[0].text()).toContain('Bilbao')
        expect(options(wrapper)[0].text()).toContain('BIO')
        expect(options(wrapper)[0].text()).toContain('Spain')
        expect(box(wrapper).attributes('aria-expanded')).toBe('true')
    })

    it('fills the box with the code when a suggestion is taken', async () => {
        const wrapper = await field()

        await box(wrapper).setValue('bilb')
        await options(wrapper)[0].trigger('click')

        expect(box(wrapper).element.value).toBe('BIO')
        expect(box(wrapper).attributes('aria-expanded')).toBe('false')
    })

    it('says so when there is nothing to suggest', async () => {
        const wrapper = await field()

        await box(wrapper).setValue('zzz')

        // Not a spinner: a verdict said before the evidence is in.
        expect(wrapper.get('.option--empty').text()).toBe('Searching…')

        await settle()

        expect(options(wrapper)).toHaveLength(0)
        expect(wrapper.get('.option--empty').text()).toBe('No matching airport.')
    })

    it('offers what was probably meant when one letter is wrong', async () => {
        const wrapper = await field(WIDER)

        await box(wrapper).setValue('barcelna')
        // The guess waits for the world search too.
        await settle()

        const guess = wrapper.get('.option--guess')

        expect(guess.text()).toContain('Did you mean')
        expect(guess.text()).toContain('Barcelona')
        // It stands in FOR the dead end rather than sitting under it.
        expect(wrapper.find('.option--empty').exists()).toBe(false)

        // And taking it behaves like taking any other suggestion.
        await guess.trigger('click')

        expect(box(wrapper).element.value).toBe('BCN')
        expect(box(wrapper).attributes('aria-expanded')).toBe('false')
    })

    it('takes the guess on Enter, which is the only key that reaches it', async () => {
        const wrapper = await field(WIDER)

        await box(wrapper).setValue('barcelna')
        await settle()

        const enter = await press(wrapper, 'Enter')

        expect(enter.defaultPrevented).toBe(true)
        expect(box(wrapper).element.value).toBe('BCN')
    })

    it('does not guess beside real results', async () => {
        const wrapper = await field(WIDER)

        await box(wrapper).setValue('bilb')

        expect(options(wrapper)).toHaveLength(1)
        expect(wrapper.find('.option--guess').exists()).toBe(false)
    })

    it('closes on Escape and leaves what was typed alone', async () => {
        const wrapper = await field()

        await box(wrapper).setValue('bilb')
        await press(wrapper, 'Escape')

        expect(box(wrapper).attributes('aria-expanded')).toBe('false')
        // "What was typed", exactly — no middle setting between code and place.
        expect(box(wrapper).element.value).toBe('bilb')
    })

    it('walks the list with the arrow keys and takes one with Enter', async () => {
        const wrapper = await field()

        await box(wrapper).setValue('por')
        expect(options(wrapper)).toHaveLength(2)

        // Nothing is highlighted until the keyboard says so.
        expect(box(wrapper).attributes('aria-activedescendant')).toBeUndefined()

        await press(wrapper, 'ArrowDown')
        await press(wrapper, 'ArrowDown')

        expect(box(wrapper).attributes('aria-activedescendant')).toBe(`${ID}-option-1`)
        expect(options(wrapper)[1].attributes('aria-selected')).toBe('true')

        const enter = await press(wrapper, 'Enter')

        expect(enter.defaultPrevented).toBe(true)
        // The second of Porto and Lisbon.
        expect(box(wrapper).element.value).toBe('LIS')
    })

    it('takes the first suggestion when Enter comes before any arrow key', async () => {
        const wrapper = await field()

        await box(wrapper).setValue('bilb')
        const enter = await press(wrapper, 'Enter')

        expect(enter.defaultPrevented).toBe(true)
        expect(box(wrapper).element.value).toBe('BIO')
    })

    // Enter is left ALONE so the form around this box submits, rather than
    // making a known code accept a suggestion that says the same thing back.
    it('leaves Enter alone when the box already holds a code', async () => {
        const wrapper = await field()

        await box(wrapper).setValue('lis')
        const enter = await press(wrapper, 'Enter')

        expect(enter.defaultPrevented).toBe(false)
        expect(box(wrapper).element.value).toBe('lis')
    })

    it('keeps letters, spaces and accents, and still drops digits', async () => {
        const wrapper = await field()

        await box(wrapper).setValue('las palmas')
        expect(box(wrapper).element.value).toBe('las palmas')

        await box(wrapper).setValue('Málaga')
        expect(box(wrapper).element.value).toBe('Málaga')

        await box(wrapper).setValue('l1s')
        expect(box(wrapper).element.value).toBe('ls')
    })

    // A list that never arrives is not an outage: the box is exactly the box
    // it was before the typeahead existed.
    it('still takes a code when the suggestions could not be loaded', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {})
        get.mockRejectedValue(new Error('offline'))

        const wrapper = mount(AirportField, {
            props: {
                id: ID,
                label: 'To',
                listLabel: 'Destination suggestions',
                modelValue: '',
                open: true,
            },
            global: { plugins: [createPinia()] },
        })

        await flushPromises()
        await box(wrapper).setValue('lis')
        await settle()

        expect(wrapper.get('.option--empty').text()).toContain('a three-letter code still works')
    })

    // `clearLabel` both names the ✕ and turns it on (docs/BUSINESS-LOGIC.md §36).
    it('has no ✕ at all unless it was given a name for one', async () => {
        const wrapper = await field()

        await box(wrapper).setValue('bilb')

        expect(wrapper.find('.field__clear').exists()).toBe(false)
    })

    it('shows the ✕ only while there is something to clear', async () => {
        const wrapper = await field(ALL, { clearLabel: 'Clear the origin' })

        expect(wrapper.find('.field__clear').exists()).toBe(false)

        // A space is not "something" here either.
        await box(wrapper).setValue(' ')
        expect(wrapper.find('.field__clear').exists()).toBe(false)

        await box(wrapper).setValue('bilb')

        const clear = wrapper.get('.field__clear')

        expect(clear.attributes('aria-label')).toBe('Clear the origin')
        expect(clear.attributes('type')).toBe('button')
    })

    it('empties the box and shuts the panel when the ✕ is pressed', async () => {
        const wrapper = await field(ALL, { clearLabel: 'Clear the origin' })

        await box(wrapper).setValue('bilb')
        expect(box(wrapper).attributes('aria-expanded')).toBe('true')

        await wrapper.get('.field__clear').trigger('click')

        expect(box(wrapper).element.value).toBe('')
        expect(box(wrapper).attributes('aria-expanded')).toBe('false')
    })

    // The screen reader's name for the box, when the label above it isn't
    // the whole story (e.g. the From box, sitting under three home pills).
    it('answers to a name of its own when it was given one', async () => {
        const plain = await field()
        expect(plain.get(`#${ID}`).attributes('aria-label')).toBeUndefined()

        const named = await field(ALL, { ariaLabel: 'Origin — any airport' })
        expect(named.get(`#${ID}`).attributes('aria-label')).toBe('Origin — any airport')
    })

    // A route from a place to itself isn't a route (docs/BUSINESS-LOGIC.md §36).
    it('never suggests what the other box already holds', async () => {
        const wrapper = await field(WIDER, { exclude: 'OPO' })

        await box(wrapper).setValue('por')

        // Porto is dropped; Portugal's other airport is not.
        expect(codes(suggestionRows(wrapper))).toEqual(['LIS'])
    })

    it('does not guess the other end either', async () => {
        const wrapper = await field(WIDER, { exclude: 'BCN' })

        await box(wrapper).setValue('barcelna')
        await settle()

        expect(wrapper.find('.option--guess').exists()).toBe(false)
        expect(wrapper.get('.option--empty').text()).toBe('No matching airport.')
    })
})

// The JOIN of curated (instant) and world (debounced) results — the debounce,
// abort and sequence guard belong to stores/airports.test.js.

const PDX = { iata: 'PDX', city: 'Portland', country: 'United States', countryCode: 'US' }
const JFK = { iata: 'JFK', city: 'New York', country: 'United States', countryCode: 'US' }

const split = (wrapper) => wrapper.find('.options__split')

describe('everywhere else', () => {
    it('paints the curated matches before the request is even made', async () => {
        const wrapper = await field(ALL, { world: [PDX] })

        await box(wrapper).setValue('por')

        // Instantly: Porto, and Portugal's other airport. No request yet.
        expect(codes(suggestionRows(wrapper))).toEqual(['OPO', 'LIS'])
        expect(get).toHaveBeenCalledTimes(1)
        expect(split(wrapper).exists()).toBe(false)
    })

    it('adds the world matches underneath, behind one divider', async () => {
        const wrapper = await field(ALL, { world: [PDX] })

        await box(wrapper).setValue('por')
        await settle()

        // As typed: the server upper/lower-cases for its own matches.
        expect(get).toHaveBeenCalledWith('/api/airports', expect.objectContaining({ params: { q: 'por' } }))
        expect(codes(suggestionRows(wrapper))).toEqual(['OPO', 'LIS', 'PDX'])

        expect(split(wrapper).text()).toBe('Everywhere else Orbit can price')
        // One divider, however many rows are under it.
        expect(wrapper.findAll('.options__split')).toHaveLength(1)
    })

    it('draws no divider when the panel is only one list', async () => {
        const wrapper = await field(ALL, { world: [PDX] })

        await box(wrapper).setValue('portland')
        await settle()

        expect(codes(suggestionRows(wrapper))).toEqual(['PDX'])
        expect(split(wrapper).exists()).toBe(false)
    })

    // The world endpoint searches the WHOLE table, curated rows included.
    it('never offers the same airport twice', async () => {
        const wrapper = await field(ALL, { world: [{ ...LIS }, PDX] })

        await box(wrapper).setValue('lis')
        await settle()

        expect(codes(suggestionRows(wrapper))).toEqual(['LIS', 'PDX'])
    })

    it('highlights a world row the way the curated ones are highlighted', async () => {
        const wrapper = await field(ALL, { world: [PDX] })

        await box(wrapper).setValue('portl')
        await settle()

        const row = options(wrapper)[0]

        expect(row.text()).toContain('Portland')
        // The ORIGINAL spelling, with the matched run bold.
        expect(row.get('b').text()).toBe('Portl')
    })

    // Without counting world suggestions, Enter would "take" the suggestion
    // the box already held and need a second press (docs/BUSINESS-LOGIC.md §36).
    it('sends a world code on the first Enter', async () => {
        const wrapper = await field(ALL, { world: [JFK] })

        await box(wrapper).setValue('jfk')
        await settle()

        const enter = await press(wrapper, 'Enter')

        expect(enter.defaultPrevented).toBe(false)
        expect(box(wrapper).element.value).toBe('jfk')
    })

    it('stops searching once a suggestion has been taken', async () => {
        const wrapper = await field(ALL, { world: [PDX] })

        await box(wrapper).setValue('portl')
        await settle()

        const asked = get.mock.calls.length

        await options(wrapper)[0].trigger('click')
        await settle()

        expect(box(wrapper).element.value).toBe('PDX')
        expect(get).toHaveBeenCalledTimes(asked)
    })

    // `clear()` is exposed so the search screen's home pills can empty this
    // box without a `world.clear()` of their own (docs/BUSINESS-LOGIC.md §36).
    it('is emptied from the form without asking anybody anything', async () => {
        const wrapper = await field(ALL, { world: [PDX] })

        await box(wrapper).setValue('portl')

        const asked = get.mock.calls.length

        wrapper.vm.clear()
        await flushPromises()
        await settle()

        expect(box(wrapper).element.value).toBe('')
        expect(get).toHaveBeenCalledTimes(asked)
    })

    it('asks nothing about a single letter', async () => {
        const wrapper = await field(ALL, { world: [PDX] })

        await box(wrapper).setValue('p')
        await settle()

        expect(get).toHaveBeenCalledTimes(1)
        expect(get).toHaveBeenCalledWith('/api/destinations')
    })
})
