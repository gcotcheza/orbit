// @vitest-environment jsdom
// =============================================================================
// The airport box, and the typeahead it is
// =============================================================================
// This file and the component it tests were Components/watch/AddRouteForm — one
// box on the watch screen — until the search screen needed the same box twice.
// The tests came with the code; what left them is everything about a PAIR (two
// codes, the two buttons, the origin chips), which is now Views/Search.test.js.
//
// Three kinds of thing are checked here:
//
//   - THE RANKING IS A PURE FUNCTION and is tested as one. "por" meaning Porto
//     rather than Portugal is an opinion, and an opinion is worth a test that
//     does not mount anything to state it.
//   - THE TYPO FALLBACK, likewise: one wrong letter fails all six ranks at
//     once, and edit distance is what stands between that and a dead end.
//   - THE BOX IS TESTED FOR ITS BEHAVIOUR — what a click does, what Enter does,
//     and the two things that must NOT have changed in the move: a raw
//     three-letter code still goes straight through, and the curated list is
//     fetched once rather than per keystroke.
//
// WHAT jsdom CANNOT HOLD, and so is left to the browser gate: whether a tap on
// a suggestion beats the focusout that would close the list (the classic focus
// race — `@mousedown.prevent` is the fix and jsdom dispatches neither in
// anger), and whether the panel is actually on top of the buttons it covers.
// Both are in e2e/specs/search.spec.js.
// =============================================================================
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
// The typo fallback
// -----------------------------------------------------------------------------
// Every rank above is a prefix or substring test, so ONE WRONG LETTER fails all
// six at once and the panel says "No matching airport." about a place three
// rows down the list it is refusing to show. These are the tests for the way
// out of that.

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

    /*
     * THE SHORT-QUERY GUARD, and it is not fussiness. Three characters are
     * within two edits of half this list — "bar" reaches Bari, Basel and
     * Barcelona — so a guess made from one is a coin toss dressed up as help.
     * The ranked search answers short queries well, and this only ever runs
     * when the ranked search found nothing.
     */
    it('refuses to guess from a query too short to mean anything', () => {
        expect(nearestDestination(WIDER, 'bar')).toBeNull()
        expect(nearestDestination(WIDER, 'zzz')).toBeNull()
    })
})

// -----------------------------------------------------------------------------
// The box
// -----------------------------------------------------------------------------

const ID = 'search-to'

/**
 * The field, mounted, with both halves of the typeahead answered.
 *
 * IT PLAYS THE PARENT AS WELL AS THE TEST, because two of this component's
 * props are ones a form owns: `modelValue` and `open`. Wiring them back through
 * `setProps` is what makes the box behave in a test the way it behaves inside
 * Views/Search.vue — in particular, a panel that opens on typing and shuts when
 * a suggestion is taken.
 *
 * `world` is what `GET /api/airports?q=` comes back with. It is EMPTY BY
 * DEFAULT and, more to the point, never arrives at all unless a test advances
 * the clock — see `settle()` — which is what keeps every assertion about the
 * instant, curated half free of the slow one.
 *
 * EVERYTHING ELSE IN THE OPTIONS IS A PROP, so a test that wants the box the
 * FROM end mounts (`clearLabel`, `ariaLabel`) says so in one word rather than
 * through a second mechanism.
 *
 * @param {Array<object>} destinations `GET /api/destinations`
 * @param {{world?: Array<object>}} props `world` aside, the component's own
 */
async function field(destinations = ALL, { world = [], ...props } = {}) {
    get.mockImplementation((url) => Promise.resolve(url === '/api/destinations'
        ? { data: { data: destinations, meta: { count: destinations.length } } }
        : { data: { data: world, meta: { count: world.length } } }))

    /* A holder, so the handlers below can name the wrapper they are mounted in. */
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

/**
 * Let the world search's debounce fire and its answer land.
 *
 * FAKE TIMERS ARE ON FOR THE WHOLE FILE (see `beforeEach`), so a test that does
 * not call this never makes the second request at all — which is deliberate.
 * The curated list is instant and the panel it paints is the one a person sees
 * first; every assertion about that state is an assertion about the first 250
 * milliseconds, and it should not have to opt out of a timer to make it.
 */
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

        /*
         * "SEARCHING…" FIRST, AND IT IS NOT A SPINNER. The curated list has
         * been searched and found nothing; the other 3,086 airports are being
         * asked about, and a panel that said "No matching airport." in the
         * meantime would be a verdict delivered before the evidence.
         */
        expect(wrapper.get('.option--empty').text()).toBe('Searching…')

        await settle()

        expect(options(wrapper)).toHaveLength(0)
        expect(wrapper.get('.option--empty').text()).toBe('No matching airport.')
    })

    /*
     * THE DEAD END, and the way out of it. "barcelna" is one letter away from a
     * place this app knows, and every rank in the search is a prefix or
     * substring test — so one slip fails all six and the panel answers "No
     * matching airport." about a city it holds.
     */
    it('offers what was probably meant when one letter is wrong', async () => {
        const wrapper = await field(WIDER)

        await box(wrapper).setValue('barcelna')
        // The guess waits for the world search too: a suggestion that appeared
        // and vanished under the thumb would be worse than a slower one.
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
        // "What was typed", exactly: the box stopped upper-casing when it
        // stopped being three letters wide. See the note on `code` in the
        // component for why there is no middle setting.
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

    /*
     * THE PATH THAT EXISTED BEFORE ANY OF THIS. Somebody who knows the code
     * types it and presses Enter, and the keypress is LEFT ALONE so the form
     * around this box submits — rather than making them accept a suggestion
     * that says the same thing back to them.
     */
    it('leaves Enter alone when the box already holds a code', async () => {
        const wrapper = await field()

        await box(wrapper).setValue('lis')
        const enter = await press(wrapper, 'Enter')

        expect(enter.defaultPrevented).toBe(false)
        // Lower case IS a code here — `isKnownCode` reads the normalised value,
        // not the field — which is the whole point of the split: what the box
        // shows and what the form sends are two different strings, and only one
        // of them has to be shouted.
        expect(box(wrapper).element.value).toBe('lis')
    })

    /*
     * The strip that keeps a code field a code field had to widen when the box
     * started taking place names — and the half that mattered, digits, has a
     * defect behind it (see the component, and the browser test that reads the
     * ELEMENT back rather than the model).
     */
    it('keeps letters, spaces and accents, and still drops digits', async () => {
        const wrapper = await field()

        // AND KEEPS THE CASE THEY WERE TYPED IN. The strip is the only thing
        // this watcher does now: a box that answered "Lisbon" with "LISBON"
        // read as a complaint about what had just been typed.
        await box(wrapper).setValue('las palmas')
        expect(box(wrapper).element.value).toBe('las palmas')

        await box(wrapper).setValue('Málaga')
        expect(box(wrapper).element.value).toBe('Málaga')

        await box(wrapper).setValue('l1s')
        expect(box(wrapper).element.value).toBe('ls')
    })

    /*
     * A list that never arrives is not an outage: the box is exactly the box it
     * was before the typeahead existed, and it says so rather than claiming
     * there is no such place.
     */
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

    /*
     * =========================================================================
     * THE ✕, WHICH ONLY ONE OF THE TWO BOXES HAS
     * =========================================================================
     * `clearLabel` is the whole switch: it names the button AND is what turns it
     * on, because a clear nobody can name is one a screen reader announces as
     * "button". The To box does not pass it — an empty To box means "nothing
     * chosen yet" and clearing it buys nobody anything — and the From box does,
     * because there emptying is a real answer: it hands the origin back to the
     * home pills above (Views/Search.vue).
     */
    it('has no ✕ at all unless it was given a name for one', async () => {
        const wrapper = await field()

        await box(wrapper).setValue('bilb')

        expect(wrapper.find('.field__clear').exists()).toBe(false)
    })

    it('shows the ✕ only while there is something to clear', async () => {
        const wrapper = await field(ALL, { clearLabel: 'Clear the origin' })

        expect(wrapper.find('.field__clear').exists()).toBe(false)

        // A space is not "something", which is the same question the panel asks
        // — one box must not be able to hold a space that the ✕ counts and the
        // suggestions do not.
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

    /*
     * AND IT IS THE SCREEN READER'S NAME FOR THE BOX, when the word above it is
     * not the whole story. The From box's label is "From" and the thing above
     * THAT is three home-airport pills, so a person arriving at the input hears
     * the name of a control they have already passed.
     */
    it('answers to a name of its own when it was given one', async () => {
        const plain = await field()
        expect(plain.get(`#${ID}`).attributes('aria-label')).toBeUndefined()

        const named = await field(ALL, { ariaLabel: 'Origin — any airport' })
        expect(named.get(`#${ID}`).attributes('aria-label')).toBe('Origin — any airport')
    })

    /*
     * =========================================================================
     * THE OTHER END OF THE PAIR IS NOT A SUGGESTION
     * =========================================================================
     * New with the search screen, because it is only answerable with two boxes:
     * a route from a place to itself is not a route, and offering AMS in the To
     * box while the From box holds AMS is offering somebody a refusal.
     */
    it('never suggests what the other box already holds', async () => {
        const wrapper = await field(WIDER, { exclude: 'OPO' })

        await box(wrapper).setValue('por')

        // Porto is dropped; Portugal's other airport is not.
        expect(codes(suggestionRows(wrapper))).toEqual(['LIS'])
    })

    it('does not guess the other end either', async () => {
        // "barcelna" is one letter from Barcelona and Barcelona is where the
        // other box already is, so the answer is no answer rather than a
        // suggestion this form is about to refuse.
        const wrapper = await field(WIDER, { exclude: 'BCN' })

        await box(wrapper).setValue('barcelna')
        await settle()

        expect(wrapper.find('.option--guess').exists()).toBe(false)
        expect(wrapper.get('.option--empty').text()).toBe('No matching airport.')
    })
})

// -----------------------------------------------------------------------------
// The world half
// -----------------------------------------------------------------------------
// The curated list is 184 places with opinions attached; the box also finds the
// other 3,086 airports Orbit can price, from `GET /api/airports?q=`. What is
// tested here is the JOIN — that the instant half still paints first, that the
// slow half lands under it, and that the panel never shows the same airport
// twice or a divider with nothing above it.
//
// The debounce, the abort and the sequence guard belong to the search itself and
// are tested in stores/airports.test.js.

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

        // As typed. App\Http\Controllers\AirportController upper-cases for the
        // code match and lower-cases for the LIKE, so the case the box holds has
        // never been what made this search work.
        expect(get).toHaveBeenCalledWith('/api/airports', expect.objectContaining({ params: { q: 'por' } }))
        expect(codes(suggestionRows(wrapper))).toEqual(['OPO', 'LIS', 'PDX'])

        expect(split(wrapper).text()).toBe('Everywhere else Orbit can price')
        // One divider, however many rows are under it.
        expect(wrapper.findAll('.options__split')).toHaveLength(1)
    })

    it('draws no divider when the panel is only one list', async () => {
        const wrapper = await field(ALL, { world: [PDX] })

        // "portland" is nowhere in the curated list, so there is nothing above
        // the world rows for a divider to divide them from.
        await box(wrapper).setValue('portland')
        await settle()

        expect(codes(suggestionRows(wrapper))).toEqual(['PDX'])
        expect(split(wrapper).exists()).toBe(false)
    })

    it('never offers the same airport twice', async () => {
        // The world endpoint searches the WHOLE airports table, curated rows
        // included, so LIS comes back from both halves.
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
        // The ORIGINAL spelling, with the matched run bold — the same index
        // arithmetic the curated rows get, over a string the server sent.
        expect(row.get('b').text()).toBe('Portl')
    })

    /*
     * THE DOUBLE-PRESS, WHICH THIS FEATURE COULD EASILY HAVE REINTRODUCED. A
     * three-letter code that Orbit knows submits on Enter — that is the whole
     * point of `isKnownCode`, and before world flights "knows" meant the
     * curated list. "JFK" is not in it, so without counting the world
     * suggestions Enter would have "taken" the suggestion the box already held
     * and needed a second press to send.
     */
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

        // The box now holds PDX, the panel is shut, and nobody is going to look
        // at the answer to a query for "PDX".
        expect(box(wrapper).element.value).toBe('PDX')
        expect(get).toHaveBeenCalledTimes(asked)
    })

    /*
     * THE SAME CANCELLATION, REACHED FROM OUTSIDE. The search screen's home
     * pills empty this box when they are tapped, and `clear()` is exposed so
     * that they go through the component rather than assigning to the model.
     *
     * IT NEEDS NO `world.clear()` OF ITS OWN, which `take()` did: the watcher
     * this write fires searches for '', and a query under `MIN_QUERY` cancels
     * the debounce instead of asking anybody anything (stores/airports.js). The
     * request queued for "portl" below is the one that must not be made.
     */
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
