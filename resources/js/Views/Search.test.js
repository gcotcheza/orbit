// @vitest-environment jsdom
// =============================================================================
// The search screen — the pair, and the two things to do with it
// =============================================================================
// The BOX is Components/search/AirportField.test.js: the ranking, the two-tier
// panel, the did-you-mean, the combobox keyboard. None of that is repeated
// here. What this file holds is everything that only exists once there are TWO
// boxes and two buttons:
//
//   - what each button sends, and that it never quietly does both;
//   - the three refusals that are about a PAIR rather than about a code;
//   - the home pills and the box under them, which are TWO controls answering
//     one question — so most of what is below them is about which one wins;
//   - the exclusion, which is the one thing each box knows about the other.
//
// WHAT IS LEFT TO THE BROWSER GATE, and why. The panel is in the flow, so
// closing it moves the buttons under it — and focusout fires on mousedown. A
// "Look up" that cannot be clicked while suggestions are showing passes every
// assertion below and fails a person holding a phone. e2e/specs/search.spec.js
// presses the button with the panel open, deliberately.
// =============================================================================
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()
const push = vi.fn()

vi.mock('@/lib/http', () => ({
    http: {
        get: (...args) => get(...args),
        post: (...args) => post(...args),
    },
}))

/*
 * The look-up is a NAVIGATION and nothing else — the detail screen is what
 * prices the pair — so `push` is not a stand-in for the feature here, it IS the
 * feature. RouterLink is stubbed to an anchor so the added notice can be read.
 */
vi.mock('vue-router', () => ({
    useRouter: () => ({ push }),
    RouterLink: { props: ['to'], template: '<a><slot /></a>' },
}))

import Search from './Search.vue'

const LIS = { iata: 'LIS', city: 'Lisbon', country: 'Portugal', countryCode: 'PT' }
const AGP = { iata: 'AGP', city: 'Málaga', country: 'Spain', countryCode: 'ES' }
const BCN = { iata: 'BCN', city: 'Barcelona', country: 'Spain', countryCode: 'ES' }

const CURATED = [LIS, AGP, BCN]

/** `POST /api/watchlist`'s row, in the shape docs/API.md sends. */
const added = (code) => ({ data: { data: { code, active: true, score: 0, confident: false } } })

/**
 * The screen, mounted, with the curated list answered and the world silent.
 *
 * The world half never arrives unless a test advances the clock, which is what
 * keeps these assertions about a form rather than about a debounce.
 */
async function screen() {
    get.mockImplementation((url) => Promise.resolve(url === '/api/destinations'
        ? { data: { data: CURATED, meta: { count: CURATED.length } } }
        : { data: { data: [], meta: { count: 0 } } }))

    const wrapper = mount(Search, { global: { plugins: [createPinia()] } })

    await flushPromises()

    return wrapper
}

const from = (wrapper) => wrapper.get('#search-from')
const to = (wrapper) => wrapper.get('#search-to')

/** Fill both boxes the way somebody who knows the codes would. */
async function pair(wrapper, origin, destination) {
    await from(wrapper).setValue(origin)
    await to(wrapper).setValue(destination)
}

const lookUp = (wrapper) => wrapper.get('.search__submit')
const watch = (wrapper) => wrapper.get('.search__watch')

const chips = (wrapper) => wrapper.findAll('.quick__chip')

/** Which of AMS / EIN / DUS is lit, or null while the box is speaking. */
const litChip = (wrapper) => chips(wrapper).find((chip) => chip.attributes('aria-pressed') === 'true')?.text() ?? null

beforeEach(() => {
    vi.useFakeTimers()
    vi.clearAllMocks()
    setActivePinia(createPinia())
})

afterEach(() => {
    vi.useRealTimers()
})

describe('the search screen', () => {
    it('opens the route the two boxes name, and writes nothing to get there', async () => {
        const wrapper = await screen()

        await pair(wrapper, 'ams', 'lis')
        await wrapper.get('form').trigger('submit')

        expect(push).toHaveBeenCalledWith({ name: 'route-detail', params: { id: 'AMS-LIS' } })
        // A look-up is a question. The only thing that may write is the other
        // button.
        expect(post).not.toHaveBeenCalled()
    })

    /*
     * THE WHOLE POINT OF THE SCREEN, in one assertion. The origin used to be one
     * of three; App\Http\Requests\RoutePairRequest widened it to any airport on
     * the same day this screen was drawn, and a From box that still only
     * accepted AMS, EIN or DUS would have been the old form with a new title.
     */
    it('looks up a pair that starts nowhere near home', async () => {
        const wrapper = await screen()

        await pair(wrapper, 'bcn', 'agp')
        await wrapper.get('form').trigger('submit')

        expect(push).toHaveBeenCalledWith({ name: 'route-detail', params: { id: 'BCN-AGP' } })
    })

    it('watches the same pair on the second button, and does not navigate', async () => {
        post.mockResolvedValue(added('AMS-LIS'))

        const wrapper = await screen()

        await pair(wrapper, 'AMS', 'LIS')
        await watch(wrapper).trigger('click')
        await flushPromises()

        expect(post).toHaveBeenCalledWith('/api/watchlist', { origin: 'AMS', destination: 'LIS' })
        expect(push).not.toHaveBeenCalled()

        /*
         * AND IT STAYS PUT. A route added a second ago has no polls, no history
         * and no opinion, so pushing somebody at its detail screen would be
         * showing them the emptiest version of the thing they just asked for.
         * The To box is cleared for the next question; the From box is not,
         * because somebody adding two routes is usually leaving from the same
         * place twice.
         */
        expect(wrapper.get('.search__added').text()).toContain('AMS→LIS is on your watch list')
        expect(to(wrapper).element.value).toBe('')
        expect(from(wrapper).element.value).toBe('AMS')
    })

    /*
     * THE SERVER'S OWN SENTENCE, shown where the pair was typed. A refusal is
     * per field and says what would fix it (docs/API.md's 422 table), so the
     * screen must print it rather than replacing it with something of its own.
     */
    it('shows what the server said when the add was refused', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {})

        post.mockRejectedValue({
            response: { status: 422, data: { errors: { destination: ['You are already watching AMS-LIS.'] } } },
        })

        const wrapper = await screen()

        await pair(wrapper, 'AMS', 'LIS')
        await watch(wrapper).trigger('click')
        await flushPromises()

        expect(wrapper.get('[role="alert"]').text()).toBe('You are already watching AMS-LIS.')
        expect(wrapper.find('.search__added').exists()).toBe(false)
    })

    it('says so when Orbit could not be reached at all', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {})
        post.mockRejectedValue(new Error('offline'))

        const wrapper = await screen()

        await pair(wrapper, 'AMS', 'LIS')
        await watch(wrapper).trigger('click')
        await flushPromises()

        expect(wrapper.get('[role="alert"]').text()).toContain('Could not reach Orbit')
    })

    // -- The three refusals that are about a pair -----------------------------

    it('refuses a half-typed place name, whichever button asks', async () => {
        const wrapper = await screen()

        await pair(wrapper, 'AMS', 'barcel')
        await wrapper.get('form').trigger('submit')

        expect(push).not.toHaveBeenCalled()
        expect(wrapper.get('[role="alert"]').text()).toContain('Pick a destination from the list')

        await watch(wrapper).trigger('click')
        expect(post).not.toHaveBeenCalled()
    })

    it('refuses a half-typed origin, and says which box it means', async () => {
        const wrapper = await screen()

        await pair(wrapper, 'barcel', 'LIS')
        await wrapper.get('form').trigger('submit')

        expect(push).not.toHaveBeenCalled()
        expect(wrapper.get('[role="alert"]').text()).toContain('Pick where you are leaving from')
    })

    it('refuses a route from a place to itself', async () => {
        const wrapper = await screen()

        await pair(wrapper, 'AMS', 'ams')
        await wrapper.get('form').trigger('submit')

        expect(push).not.toHaveBeenCalled()
        expect(wrapper.get('[role="alert"]').text()).toBe('A route needs two different airports.')
    })

    it('keeps both buttons shut until there are two codes', async () => {
        const wrapper = await screen()

        // The origin half is already answered, by the LIT PILL rather than by
        // the box — which is empty. What is missing is a destination.
        expect(lookUp(wrapper).attributes('disabled')).toBeDefined()
        expect(watch(wrapper).attributes('disabled')).toBeDefined()

        await to(wrapper).setValue('LIS')
        expect(lookUp(wrapper).attributes('disabled')).toBeUndefined()
        expect(watch(wrapper).attributes('disabled')).toBeUndefined()

        // And a half-typed somewhere-else shuts them again: text wins while it
        // is there, even over a pill that would have been perfectly good.
        await from(wrapper).setValue('barcel')
        expect(lookUp(wrapper).attributes('disabled')).toBeDefined()
        expect(watch(wrapper).attributes('disabled')).toBeDefined()
    })

    // -- The pills and the box: two controls, one origin ----------------------
    //
    // They used to be ONE VALUE — the lit pill's code sat in the box — and the
    // box paid for it: a field arriving prefilled with three capitals and no
    // placeholder is a read-out, not somewhere to type. So the box is empty now
    // and never mirrors a pill, which makes "which of the two is the origin" a
    // real question. It has one answer, in `origin`: text while there is text,
    // the lit pill otherwise. Everything below is that sentence, tested.

    /*
     * NINE FLIGHTS IN TEN LEAVE FROM ONE OF THREE AIRPORTS, and a screen that
     * made the common case cost eight keystrokes in order to buy the rare one
     * would be a worse screen than the form it replaced. One tap is still
     * taken: AMS is lit before anybody has touched anything.
     */
    it('starts at Amsterdam with an empty box that says it takes anywhere', async () => {
        const wrapper = await screen()

        expect(chips(wrapper).map((chip) => chip.text())).toEqual(['AMS', 'EIN', 'DUS'])
        expect(litChip(wrapper)).toBe('AMS')

        // THE BOX IS EMPTY AND PROMPTING. Both halves matter: the value is what
        // stopped being a read-out, the placeholder is what says so.
        expect(from(wrapper).element.value).toBe('')
        expect(from(wrapper).attributes('placeholder')).toBe('Somewhere else? City or code…')
        expect(from(wrapper).attributes('aria-label')).toBe('Origin — any airport')

        // And the lit pill IS the origin, without anything being typed.
        await to(wrapper).setValue('LIS')
        await wrapper.get('form').trigger('submit')

        expect(push).toHaveBeenCalledWith({ name: 'route-detail', params: { id: 'AMS-LIS' } })
    })

    it('moves the origin with one tap, and still writes nothing into the box', async () => {
        post.mockResolvedValue(added('DUS-LIS'))

        const wrapper = await screen()

        await chips(wrapper)[2].trigger('click')

        expect(litChip(wrapper)).toBe('DUS')
        expect(from(wrapper).element.value).toBe('')

        await to(wrapper).setValue('LIS')
        await watch(wrapper).trigger('click')
        await flushPromises()

        // The tap is what the WRITE sends, not just what the pill looks like.
        expect(post).toHaveBeenCalledWith('/api/watchlist', { origin: 'DUS', destination: 'LIS' })
    })

    /*
     * AND A PILL IS NOT A CLOSED LIST. The three are presentation — the server
     * takes any airport at either end — so typing has to leave the box holding
     * what was typed, every pill dark, and the typed place in the request.
     */
    it('lets the box name an airport no pill offers, and sends that', async () => {
        const wrapper = await screen()

        await from(wrapper).setValue('BCN')

        expect(litChip(wrapper)).toBeNull()
        expect(from(wrapper).element.value).toBe('BCN')

        await to(wrapper).setValue('AGP')
        await wrapper.get('form').trigger('submit')

        expect(push).toHaveBeenCalledWith({ name: 'route-detail', params: { id: 'BCN-AGP' } })
    })

    /*
     * THE ✕, WHICH IS THE WAY BACK. Without it the only way out of "somewhere
     * else" is to select and delete what is in the box — the same chore the
     * prefilled origin used to impose, moved one step later.
     */
    it('offers a clear only once there is something to clear, and only on the origin', async () => {
        const wrapper = await screen()

        expect(wrapper.find('.field__clear').exists()).toBe(false)

        await from(wrapper).setValue('BCN')
        expect(wrapper.findAll('.field__clear')).toHaveLength(1)

        // The To box is untouched by any of this: an empty To box means
        // "nothing chosen yet", and clearing it buys nobody anything.
        await to(wrapper).setValue('LIS')
        expect(wrapper.findAll('.field__clear')).toHaveLength(1)
    })

    it('hands the origin back to the pills when the box is cleared', async () => {
        const wrapper = await screen()

        await from(wrapper).setValue('BCN')
        await wrapper.get('.field__clear').trigger('click')

        expect(from(wrapper).element.value).toBe('')
        expect(litChip(wrapper)).toBe('AMS')
    })

    /*
     * AND BACK TO THE PILL THAT WAS TAPPED, not to Amsterdam. Nothing is
     * forgotten while the pills are dark, so somebody who chose EIN, tried
     * somewhere else and changed their mind gets EIN — an explicit choice
     * survives a cleared box.
     */
    it('remembers which pill was tapped while the box is speaking over it', async () => {
        const wrapper = await screen()

        await chips(wrapper)[1].trigger('click')
        await from(wrapper).setValue('BCN')

        expect(litChip(wrapper)).toBeNull()

        await wrapper.get('.field__clear').trigger('click')

        expect(litChip(wrapper)).toBe('EIN')
    })

    /*
     * PILLS WIN ON TAP. Somebody who taps DUS over a half-typed "barcel" has
     * changed their mind, and a screen that lit DUS while still showing
     * "barcel" would be wrong in one of the two places.
     */
    it('empties the box when a pill is tapped over typed text', async () => {
        const wrapper = await screen()

        await from(wrapper).setValue('barcel')
        await chips(wrapper)[2].trigger('click')

        expect(from(wrapper).element.value).toBe('')
        expect(litChip(wrapper)).toBe('DUS')
    })

    it('asks nobody about a query a pill has just cancelled', async () => {
        const wrapper = await screen()

        await from(wrapper).setValue('barcel')

        const asked = get.mock.calls.length

        await chips(wrapper)[1].trigger('click')
        await vi.advanceTimersByTimeAsync(1000)
        await flushPromises()

        // The box is empty, the panel is shut, and the debounced
        // `GET /api/airports?q=barcel` was for a panel nobody is going to look
        // at — see `takeHome`, and `clear()` in AirportField.vue.
        expect(get).toHaveBeenCalledTimes(asked)
    })

    // -- The one thing each box knows about the other -------------------------

    it('does not offer the origin as a destination', async () => {
        const wrapper = await screen()

        await from(wrapper).setValue('BCN')
        await to(wrapper).setValue('barcel')

        const list = wrapper.get('#search-to-list')

        expect(list.findAll('[role="option"]')).toHaveLength(0)
        expect(list.get('.option--empty').text()).toBe('Searching…')
    })

    /*
     * ONE PANEL AT A TIME, which is why the flag lives on the form. Two open
     * listboxes stacked down a 430 px column is a screen with no buttons on it.
     *
     * ASSERTED ON `aria-expanded` AND NOT ON `isVisible()`, deliberately. The
     * panel is hidden with `v-show`, and VTU resolves visibility through
     * `getComputedStyle` up a tree that `mount()` leaves detached from the
     * document — so "is it visible" is a question jsdom answers by guessing,
     * and it guessed differently in this file depending on which tests ran
     * before it. `aria-expanded` is the same `showing` computed, written down as
     * the contract the combobox actually makes to a screen reader.
     */
    it('shows one suggestion list at a time', async () => {
        const wrapper = await screen()

        await to(wrapper).setValue('lisb')
        expect(to(wrapper).attributes('aria-expanded')).toBe('true')
        expect(from(wrapper).attributes('aria-expanded')).toBe('false')

        await from(wrapper).setValue('barcel')
        expect(from(wrapper).attributes('aria-expanded')).toBe('true')
        expect(to(wrapper).attributes('aria-expanded')).toBe('false')
    })

    /*
     * THE DEFECT THE BROWSER GATE FOUND, held here as the rule that prevents it.
     * Focus moving INSIDE the form leaves the panel alone — so nothing reflows
     * under a pointer that is on its way from mousedown to mouseup — and focus
     * leaving the form for good closes it. jsdom cannot show the missed click;
     * it can hold the rule.
     */
    it('keeps the panel open while focus moves to the button that sends it', async () => {
        const wrapper = mount(Search, { attachTo: document.body, global: { plugins: [createPinia()] } })

        get.mockImplementation(() => Promise.resolve({ data: { data: CURATED, meta: { count: 3 } } }))
        await flushPromises()

        await to(wrapper).setValue('lisb')

        const leave = (relatedTarget) => {
            to(wrapper).element.dispatchEvent(new FocusEvent('focusout', { bubbles: true, relatedTarget }))

            return flushPromises()
        }

        await leave(lookUp(wrapper).element)
        expect(to(wrapper).attributes('aria-expanded')).toBe('true')

        await leave(null)
        expect(to(wrapper).attributes('aria-expanded')).toBe('false')

        wrapper.unmount()
    })
})
