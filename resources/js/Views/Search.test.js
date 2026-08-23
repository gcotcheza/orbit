// @vitest-environment jsdom
// The search screen — everything that only exists with TWO boxes and two
// buttons. The box itself is AirportField.test.js (docs/BUSINESS-LOGIC.md §36).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { computed, ref } from 'vue'

const get = vi.fn()
const post = vi.fn()
const push = vi.fn()

/* jsdom has no matchMedia, so the real composable would answer 'phone' and nothing else; this is
   the switch the wide branch is behind. Deferred inside the arrow, as vi.mock is hoisted. */
const desktop = ref(false)

vi.mock('@/lib/layout', () => ({
    useLayout: () => ({
        layout: computed(() => (desktop.value ? 'desktop' : 'phone')),
        isPhone: computed(() => !desktop.value),
        isDesktop: desktop,
        stop: () => {},
    }),
}))

vi.mock('@/lib/http', () => ({
    http: {
        get: (...args) => get(...args),
        post: (...args) => post(...args),
    },
}))

// The look-up is a navigation, and `push` IS the feature here.
vi.mock('vue-router', () => ({
    useRouter: () => ({ push }),
    RouterLink: { props: ['to'], template: '<a><slot /></a>' },
}))

import Search from './Search.vue'

/** The route detail is RouteDetailPanel.test.js's subject; here only the pair it was handed is. */
const PanelStub = {
    props: { code: String, embedded: Boolean, autofocus: Boolean },
    template: '<div class="panel-stub" :data-code="code" :data-embedded="embedded"></div>',
}

/** One `GET /api/discoveries` row, trimmed to what the card reads (docs/API.md). */
const find = (code, city) => ({
    code,
    origin: { iata: code.slice(0, 3), city: 'Amsterdam', country: 'Netherlands' },
    destination: { iata: code.slice(4), city, country: 'Spain' },
    lane: 'absolute',
    price: 29,
    departureDate: '2026-10-24',
    milliEurosPerKm: 15.6,
    percentile: 0,
    savings: 49,
    foundAt: '2026-08-15T08:00:00+02:00',
    verdict: { verified: false, label: 'Unverified', level: 'typical', googleLowest: 70, typicalLow: 55, typicalHigh: 175 },
})

const FINDS = [find('AMS-AGP', 'Málaga'), find('AMS-BCN', 'Barcelona')]

const LIS = { iata: 'LIS', city: 'Lisbon', country: 'Portugal', countryCode: 'PT' }
const AGP = { iata: 'AGP', city: 'Málaga', country: 'Spain', countryCode: 'ES' }
const BCN = { iata: 'BCN', city: 'Barcelona', country: 'Spain', countryCode: 'ES' }

const CURATED = [LIS, AGP, BCN]

/** `POST /api/watchlist`'s row, in the shape docs/API.md sends. */
const added = (code) => ({ data: { data: { code, active: true, score: 0, confident: false } } })

// The world half never arrives unless a test advances the clock.
async function screen({ finds = [] } = {}) {
    get.mockImplementation((url) => Promise.resolve(url === '/api/destinations'
        ? { data: { data: CURATED, meta: { count: CURATED.length } } }
        : { data: { data: url === '/api/discoveries' ? finds : [], meta: { count: 0 } } }))

    const wrapper = mount(Search, {
        global: { plugins: [createPinia()], stubs: { RouteDetailPanel: PanelStub } },
    })

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
    desktop.value = false
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
        expect(post).not.toHaveBeenCalled()
    })

    // The whole point of the screen, in one assertion (docs/BUSINESS-LOGIC.md §36).
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

        // And it stays put — To clears for the next question, From does not.
        expect(wrapper.get('.search__added').text()).toContain('AMS→LIS is on your watch list')
        expect(to(wrapper).element.value).toBe('')
        expect(from(wrapper).element.value).toBe('AMS')
    })

    // The server's own sentence, shown where the pair was typed.
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

        // The origin half is already answered, by the lit pill, not the box.
        expect(lookUp(wrapper).attributes('disabled')).toBeDefined()
        expect(watch(wrapper).attributes('disabled')).toBeDefined()

        await to(wrapper).setValue('LIS')
        expect(lookUp(wrapper).attributes('disabled')).toBeUndefined()
        expect(watch(wrapper).attributes('disabled')).toBeUndefined()

        // Text wins while it is there, even half-typed.
        await from(wrapper).setValue('barcel')
        expect(lookUp(wrapper).attributes('disabled')).toBeDefined()
        expect(watch(wrapper).attributes('disabled')).toBeDefined()
    })

    // The pills and the box: two controls, one origin (docs/BUSINESS-LOGIC.md §36).
    it('starts at Amsterdam with an empty box that says it takes anywhere', async () => {
        const wrapper = await screen()

        expect(chips(wrapper).map((chip) => chip.text())).toEqual(['AMS', 'EIN', 'DUS'])
        expect(litChip(wrapper)).toBe('AMS')

        // Empty and prompting: the value stopped being a read-out.
        expect(from(wrapper).element.value).toBe('')
        expect(from(wrapper).attributes('placeholder')).toBe('Somewhere else? City or code…')
        expect(from(wrapper).attributes('aria-label')).toBe('Origin — any airport')

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

        expect(post).toHaveBeenCalledWith('/api/watchlist', { origin: 'DUS', destination: 'LIS' })
    })

    // A pill is not a closed list — the three are presentation only.
    it('lets the box name an airport no pill offers, and sends that', async () => {
        const wrapper = await screen()

        await from(wrapper).setValue('BCN')

        expect(litChip(wrapper)).toBeNull()
        expect(from(wrapper).element.value).toBe('BCN')

        await to(wrapper).setValue('AGP')
        await wrapper.get('form').trigger('submit')

        expect(push).toHaveBeenCalledWith({ name: 'route-detail', params: { id: 'BCN-AGP' } })
    })

    // The ✕ is the way back out of "somewhere else".
    it('offers a clear only once there is something to clear, and only on the origin', async () => {
        const wrapper = await screen()

        expect(wrapper.find('.field__clear').exists()).toBe(false)

        await from(wrapper).setValue('BCN')
        expect(wrapper.findAll('.field__clear')).toHaveLength(1)

        // The To box is untouched by any of this.
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

    // Back to the pill that was tapped, not to Amsterdam.
    it('remembers which pill was tapped while the box is speaking over it', async () => {
        const wrapper = await screen()

        await chips(wrapper)[1].trigger('click')
        await from(wrapper).setValue('BCN')

        expect(litChip(wrapper)).toBeNull()

        await wrapper.get('.field__clear').trigger('click')

        expect(litChip(wrapper)).toBe('EIN')
    })

    // Pills win on tap.
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

        expect(get).toHaveBeenCalledTimes(asked)
    })

    it('does not offer the origin as a destination', async () => {
        const wrapper = await screen()

        await from(wrapper).setValue('BCN')
        await to(wrapper).setValue('barcel')

        const list = wrapper.get('#search-to-list')

        expect(list.findAll('[role="option"]')).toHaveLength(0)
        expect(list.get('.option--empty').text()).toBe('Searching…')
    })

    // One panel at a time — why the flag lives on the form. Asserted on
    // `aria-expanded`, not `isVisible()`: jsdom guesses at v-show visibility.
    it('shows one suggestion list at a time', async () => {
        const wrapper = await screen()

        await to(wrapper).setValue('lisb')
        expect(to(wrapper).attributes('aria-expanded')).toBe('true')
        expect(from(wrapper).attributes('aria-expanded')).toBe('false')

        await from(wrapper).setValue('barcel')
        expect(from(wrapper).attributes('aria-expanded')).toBe('true')
        expect(to(wrapper).attributes('aria-expanded')).toBe('false')
    })

    // The defect the browser gate found, held here as the rule that
    // prevents it (docs/BUSINESS-LOGIC.md §36).
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

// 1024px and up: the form is the master pane and the pane beside it holds the finds — or, after a
// look-up, the route itself (docs/DESKTOP-LAYOUT-PLAN.md phase 3).
describe('inside the frame', () => {
    beforeEach(() => {
        desktop.value = true
    })

    it('splits the form and the finds into the two panes', async () => {
        const wrapper = await screen({ finds: FINDS })

        expect(wrapper.get('.screen').classes()).toContain('screen--wide')
        expect(wrapper.get('.screen__master').find('form.search').exists()).toBe(true)
        expect(wrapper.get('.screen__pane').find('.finds__list').exists()).toBe(true)
        expect(wrapper.findAll('.finds__list .find')).toHaveLength(2)
    })

    it('puts the looked-up route in the pane instead of navigating away', async () => {
        const wrapper = await screen({ finds: FINDS })

        await pair(wrapper, 'ams', 'lis')
        await wrapper.get('form').trigger('submit')

        const panel = wrapper.get('.screen__pane .panel-stub')

        expect(panel.attributes('data-code')).toBe('AMS-LIS')
        expect(panel.attributes('data-embedded')).toBe('true')

        // Nothing navigated, and nothing was written to get there.
        expect(push).not.toHaveBeenCalled()
        expect(post).not.toHaveBeenCalled()
        expect(wrapper.find('.finds').exists()).toBe(false)
    })

    // The way back is named after where it goes, which is the heading under it.
    it('goes back to the finds from the pane, and from an edited destination', async () => {
        const wrapper = await screen({ finds: FINDS })

        await pair(wrapper, 'ams', 'lis')
        await wrapper.get('form').trigger('submit')
        expect(wrapper.find('.panel-stub').exists()).toBe(true)

        expect(wrapper.get('.looked__back').text()).toBe('Deals from your airports')
        await wrapper.get('.looked__back').trigger('click')

        expect(wrapper.find('.panel-stub').exists()).toBe(false)
        expect(wrapper.get('.screen__pane').find('.finds__list').exists()).toBe(true)

        // And a pair the form has moved past cannot be left standing in the pane.
        await wrapper.get('form').trigger('submit')
        expect(wrapper.find('.panel-stub').exists()).toBe(true)

        await to(wrapper).setValue('')

        expect(wrapper.find('.panel-stub').exists()).toBe(false)
    })

    it('still adds to the watch list from the master, and says so there', async () => {
        post.mockResolvedValue(added('AMS-LIS'))

        const wrapper = await screen({ finds: FINDS })

        await pair(wrapper, 'AMS', 'LIS')
        await watch(wrapper).trigger('click')
        await flushPromises()

        expect(post).toHaveBeenCalledWith('/api/watchlist', { origin: 'AMS', destination: 'LIS' })
        expect(wrapper.get('.screen__master .search__added').text()).toContain('AMS→LIS is on your watch list')
    })

    it('is one phone column again below the frame', async () => {
        desktop.value = false

        const wrapper = await screen({ finds: FINDS })

        await pair(wrapper, 'ams', 'lis')
        await wrapper.get('form').trigger('submit')

        expect(wrapper.get('.screen').classes()).not.toContain('screen--wide')
        expect(wrapper.find('.panel-stub').exists()).toBe(false)
        expect(push).toHaveBeenCalledWith({ name: 'route-detail', params: { id: 'AMS-LIS' } })
    })
})

// Phase 3 left both of these navigating to the bare route screen, which drops out of the frame the
// pane is in (docs/DESKTOP-LAYOUT-PLAN.md phase 4).
describe('a find opens in the pane', () => {
    beforeEach(() => {
        desktop.value = true
    })

    it('answers a card in the pane it was drawn in', async () => {
        const wrapper = await screen({ finds: FINDS })

        await wrapper.findAll('.finds__list .find')[1].trigger('click')

        expect(wrapper.get('.screen__pane .panel-stub').attributes('data-code')).toBe('AMS-BCN')
        expect(wrapper.find('.finds').exists()).toBe(false)
        expect(push).not.toHaveBeenCalled()
    })

    it('answers "Open it" there too, after a route is added', async () => {
        post.mockResolvedValue(added('AMS-LIS'))

        const wrapper = await screen({ finds: FINDS })

        await pair(wrapper, 'AMS', 'LIS')
        await watch(wrapper).trigger('click')
        await flushPromises()

        await wrapper.get('.search__added button.search__added-link').trigger('click')

        expect(wrapper.get('.screen__pane .panel-stub').attributes('data-code')).toBe('AMS-LIS')
        expect(push).not.toHaveBeenCalled()
    })

    // Focus moves to the panel's own heading; this is for a reader whose does not.
    it('says what the pane is of, and starts on the finds so nothing is announced on arrival', async () => {
        const wrapper = await screen({ finds: FINDS })
        const live = wrapper.get('.pane-live')

        expect(live.attributes('role')).toBe('status')
        expect(live.text()).toBe('Deals from your airports')

        await wrapper.findAll('.finds__list .find')[0].trigger('click')

        expect(wrapper.get('.pane-live').text()).toBe('Showing AMS → AGP')
    })

    it('leaves the phone navigating, card and link alike', async () => {
        post.mockResolvedValue(added('AMS-LIS'))
        desktop.value = false

        const wrapper = await screen({ finds: FINDS })

        expect(wrapper.find('.pane-live').exists()).toBe(false)
        expect(wrapper.findAll('.finds__list .find')[0].element.tagName).toBe('A')

        await pair(wrapper, 'AMS', 'LIS')
        await watch(wrapper).trigger('click')
        await flushPromises()

        expect(wrapper.get('.search__added').find('button.search__added-link').exists()).toBe(false)
        expect(wrapper.get('.search__added').find('a.search__added-link').exists()).toBe(true)
    })
})
