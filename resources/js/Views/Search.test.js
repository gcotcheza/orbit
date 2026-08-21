// @vitest-environment jsdom
// The search screen — everything that only exists with TWO boxes and two
// buttons. The box itself is AirportField.test.js (docs/BUSINESS-LOGIC.md §36).
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

// The look-up is a navigation, and `push` IS the feature here.
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

// The world half never arrives unless a test advances the clock.
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
