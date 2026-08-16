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
//   - the home chips, which fill the From box without asking the network about
//     a code they just wrote into it;
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

        expect(lookUp(wrapper).attributes('disabled')).toBeDefined()

        await from(wrapper).setValue('AMS')
        expect(watch(wrapper).attributes('disabled')).toBeDefined()

        await to(wrapper).setValue('LIS')
        expect(lookUp(wrapper).attributes('disabled')).toBeUndefined()
        expect(watch(wrapper).attributes('disabled')).toBeUndefined()
    })

    // -- The home chips -------------------------------------------------------

    /*
     * NINE FLIGHTS IN TEN LEAVE FROM ONE OF THREE AIRPORTS, and a screen that
     * made the common case cost eight keystrokes in order to buy the rare one
     * would be a worse screen than the form it replaced.
     */
    it('starts at Amsterdam, and moves with one tap', async () => {
        const wrapper = await screen()

        expect(from(wrapper).element.value).toBe('AMS')

        const chips = wrapper.findAll('.quick__chip')
        expect(chips.map((chip) => chip.text())).toEqual(['AMS', 'EIN', 'DUS'])
        expect(chips[0].attributes('aria-pressed')).toBe('true')

        await chips[2].trigger('click')

        expect(from(wrapper).element.value).toBe('DUS')
        expect(chips[2].attributes('aria-pressed')).toBe('true')
        expect(chips[0].attributes('aria-pressed')).toBe('false')
    })

    /*
     * AND A CHIP IS NOT A CLOSED LIST. The three are presentation now — the
     * server takes any airport at either end — so typing over them has to leave
     * the box holding what was typed and all three chips unpressed.
     */
    it('lets the box hold an airport no chip offers', async () => {
        const wrapper = await screen()

        await from(wrapper).setValue('BCN')

        expect(wrapper.findAll('.quick__chip').every((chip) => chip.attributes('aria-pressed') === 'false')).toBe(true)
        expect(from(wrapper).element.value).toBe('BCN')
    })

    it('asks nobody about a code a chip just wrote', async () => {
        const wrapper = await screen()

        const asked = get.mock.calls.length

        await wrapper.findAll('.quick__chip')[1].trigger('click')
        await vi.advanceTimersByTimeAsync(1000)
        await flushPromises()

        // The panel is shut and the box holds EIN. A `GET /api/airports?q=EIN`
        // here would be a request for an answer nothing is going to render —
        // see `takeHome`, and `take()` in AirportField.vue.
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
