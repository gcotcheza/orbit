// @vitest-environment jsdom
// The create screen's conversation: a removal must survive a parse it did
// not ask for, since every keystroke re-parses (docs/BUSINESS-LOGIC.md §11).
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'

const post = vi.fn()

vi.mock('@/lib/http', () => ({ http: { post: (...args) => post(...args) } }))

import Create from './Create.vue'

/** The design's own sentence, and the six chips it reads as. */
const CHIPS = [
    { id: 'origin:AMS', category: 'From', label: 'AMS' },
    { id: 'origin:EIN', category: 'From', label: 'EIN' },
    { id: 'origin:DUS', category: 'From', label: 'DUS' },
    { id: 'max_price', category: 'Max price', label: '€80' },
    { id: 'trip_length', category: 'Trip length', label: '2–3 nights' },
    { id: 'depart', category: 'Depart', label: 'Fridays' },
    { id: 'date_window', category: 'Date window', label: 'Mar – May' },
    { id: 'vibe:sunny', category: 'Vibe', label: '☀ Sunny' },
]

function reading(chips = CHIPS, count = 6, cheapest = 34) {
    return {
        data: {
            data: {
                chips,
                criteria: { origins: ['AMS', 'EIN', 'DUS'], maxPriceCents: 8000, vibes: ['sunny'] },
                matches: { count, cheapest, sample: [] },
            },
        },
    }
}

/** The last body sent to `/api/rules/parse`. */
function lastParse() {
    const calls = post.mock.calls.filter(([url]) => url === '/api/rules/parse')

    return calls[calls.length - 1]?.[1]
}

/** How many chips' × are inert — the answer this screen owes is always zero. */
function inertRemoves(wrapper) {
    return wrapper.findAll('.chip__remove').filter((x) => x.attributes('disabled') !== undefined).length
}

/** A parse that is sent and never answered, so the test can hold it in flight. */
function heldParse() {
    let land
    post.mockImplementationOnce(() => new Promise((resolve) => { land = resolve }))

    return (value = reading()) => land(value)
}

async function screen() {
    const wrapper = mount(Create, {
        global: {
            plugins: [createPinia()],
            stubs: { RouterLink: RouterLinkStub },
        },
    })

    await flushPromises()

    return wrapper
}

describe('Create', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
        vi.useFakeTimers({ shouldAdvanceTime: true })
        post.mockReset()
        post.mockResolvedValue(reading())
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    it('reads the seeded sentence the moment the screen opens', async () => {
        const wrapper = await screen()

        expect(post).toHaveBeenCalledWith('/api/rules/parse', {
            text: 'cheap weekend somewhere sunny in spring, leaving Friday from any NL airport, under €80',
            removed: [],
        })

        expect(wrapper.findAll('.chip')).toHaveLength(8)
        expect(wrapper.text()).toContain("Here's what we understood")
    })

    it('draws each chip as its category over its value', async () => {
        const wrapper = await screen()
        const chip = wrapper.findAll('.chip')[3]

        expect(chip.find('.chip__category').text()).toBe('Max price')
        expect(chip.find('.chip__label').text()).toBe('€80')
    })

    it('says how many trips match and what the cheapest costs', async () => {
        const wrapper = await screen()

        expect(wrapper.find('.banner').text()).toContain('6 trips')
        expect(wrapper.find('.banner').text()).toContain('cheapest €34')
    })

    it('says something useful rather than "0 trips" when nothing matches yet', async () => {
        post.mockResolvedValue(reading(CHIPS, 0, null))

        const wrapper = await screen()

        expect(wrapper.find('.banner').text()).toContain('Nothing matches yet')
        expect(wrapper.find('.banner').text()).not.toContain('0 trips')
    })

    // The sentence is not edited — the id is sent to be left out.
    it('re-parses the same sentence without a removed chip', async () => {
        const wrapper = await screen()

        await wrapper.findAll('.chip__remove')[1].trigger('click')
        await vi.advanceTimersByTimeAsync(500)
        await flushPromises()

        expect(lastParse()).toEqual({
            text: 'cheap weekend somewhere sunny in spring, leaving Friday from any NL airport, under €80',
            removed: ['origin:EIN'],
        })
    })

    // The load-bearing one: an index-based id would silently remove the
    // wrong chip here (docs/BUSINESS-LOGIC.md §11).
    it('keeps a removal when the sentence is edited afterwards', async () => {
        const wrapper = await screen()

        await wrapper.findAll('.chip__remove')[3].trigger('click')
        await vi.advanceTimersByTimeAsync(500)
        await flushPromises()

        await wrapper.find('textarea').setValue('cheap weekend somewhere sunny in spring under €90')
        await vi.advanceTimersByTimeAsync(500)
        await flushPromises()

        expect(lastParse()).toEqual({
            text: 'cheap weekend somewhere sunny in spring under €90',
            removed: ['max_price'],
        })
    })

    it('waits for the typing to stop before asking', async () => {
        const wrapper = await screen()

        post.mockClear()

        await wrapper.find('textarea').setValue('a')
        await wrapper.find('textarea').setValue('a beach')
        await wrapper.find('textarea').setValue('a beach week')

        expect(post).not.toHaveBeenCalled()

        await vi.advanceTimersByTimeAsync(500)
        await flushPromises()

        expect(post).toHaveBeenCalledTimes(1)
        expect(lastParse().text).toBe('a beach week')
    })

    it('reset puts every chip back without touching the text', async () => {
        const wrapper = await screen()

        expect(wrapper.find('.understood__reset').exists()).toBe(false)

        await wrapper.findAll('.chip__remove')[0].trigger('click')
        await vi.advanceTimersByTimeAsync(500)
        await flushPromises()

        await wrapper.find('.understood__reset').trigger('click')
        await vi.advanceTimersByTimeAsync(500)
        await flushPromises()

        expect(lastParse().removed).toEqual([])
        expect(wrapper.find('textarea').element.value).toContain('cheap weekend')
    })

    it('will not offer to create a rule it could not read', async () => {
        post.mockResolvedValue(reading([], 0, null))

        const wrapper = await screen()

        expect(wrapper.find('.cta').attributes('disabled')).toBeDefined()
        expect(wrapper.text()).toContain('Orbit could not read a trip out of that yet')
    })

    it('creates the rule the chips currently describe', async () => {
        const wrapper = await screen()

        await wrapper.findAll('.chip__remove')[5].trigger('click')
        await vi.advanceTimersByTimeAsync(500)
        await flushPromises()

        post.mockResolvedValueOnce({
            data: {
                data: {
                    id: 1,
                    text: 'cheap weekend somewhere sunny',
                    active: true,
                    chips: CHIPS.slice(3, 5),
                    criteria: {},
                    matches: { count: 6, cheapest: 34, sample: [] },
                },
            },
        })

        await wrapper.find('.cta').trigger('click')
        await flushPromises()

        const create = post.mock.calls.filter(([url]) => url === '/api/rules')[0]

        expect(create[1]).toEqual({
            text: 'cheap weekend somewhere sunny in spring, leaving Friday from any NL airport, under €80',
            removed: ['depart'],
        })

        expect(wrapper.text()).toContain('Rule created')
        expect(wrapper.text()).toContain("We'll tell you when a trip like this turns up")
        expect(wrapper.findComponent(RouterLinkStub).props().to).toEqual({ name: 'watch' })
    })

    // Live down, inert up = no `click` at all (docs/BUSINESS-LOGIC.md §11).
    it('leaves every × live through the debounce and through the parse', async () => {
        const wrapper = await screen()

        const land = heldParse()

        await wrapper.find('textarea').setValue('a beach week in June under €150')
        await flushPromises()

        expect(post).toHaveBeenCalledTimes(1)
        expect(inertRemoves(wrapper)).toBe(0)

        await vi.advanceTimersByTimeAsync(500)
        await flushPromises()

        expect(wrapper.find('.banner').classes()).toContain('banner--loading')
        expect(inertRemoves(wrapper)).toBe(0)

        land()
        await flushPromises()

        expect(inertRemoves(wrapper)).toBe(0)
    })

    it('asks the moment a chip is removed instead of waiting the debounce out', async () => {
        const wrapper = await screen()

        post.mockClear()

        await wrapper.find('textarea').setValue('a beach week in June under €150')

        expect(post).not.toHaveBeenCalled()

        await wrapper.findAll('.chip__remove')[3].trigger('click')
        await flushPromises()

        expect(post).toHaveBeenCalledTimes(1)
        expect(lastParse()).toEqual({ text: 'a beach week in June under €150', removed: ['max_price'] })

        // And the wait it cancelled does not fire a second parse behind it.
        await vi.advanceTimersByTimeAsync(500)
        await flushPromises()

        expect(post).toHaveBeenCalledTimes(1)
    })

    it('takes a second removal immediately too, rather than eating it', async () => {
        const wrapper = await screen()

        post.mockClear()

        await wrapper.findAll('.chip__remove')[3].trigger('click')
        await wrapper.findAll('.chip__remove')[5].trigger('click')
        await flushPromises()

        expect(post).toHaveBeenCalledTimes(2)
        expect(lastParse().removed).toEqual(['max_price', 'depart'])
    })

    // The CTA is the one thing that does wait for a fresh reading.
    it('will not create against a reading the text has moved past', async () => {
        const wrapper = await screen()

        expect(wrapper.find('.cta').attributes('disabled')).toBeUndefined()

        const land = heldParse()

        await wrapper.find('textarea').setValue('a beach week in June under €150')
        await flushPromises()

        expect(wrapper.find('.cta').attributes('disabled')).toBeDefined()

        await vi.advanceTimersByTimeAsync(500)
        await flushPromises()

        expect(wrapper.find('.cta').attributes('disabled')).toBeDefined()

        land()
        await flushPromises()

        expect(wrapper.find('.cta').attributes('disabled')).toBeUndefined()
    })

    // A failed parse read nothing, so the same string retyped is a new question.
    it('re-asks after a failed parse when the text is retyped', async () => {
        const wrapper = await screen()

        post.mockRejectedValueOnce({ response: { status: 429 } })

        await wrapper.find('textarea').setValue('a beach week in June')
        await vi.advanceTimersByTimeAsync(500)
        await flushPromises()

        expect(wrapper.find('.error').text()).toContain('Slow down a moment')

        post.mockClear()

        await wrapper.find('textarea').setValue('a beach week in Jun')
        await wrapper.find('textarea').setValue('a beach week in June')
        await vi.advanceTimersByTimeAsync(500)
        await flushPromises()

        expect(post).toHaveBeenCalledTimes(1)
        expect(lastParse().text).toBe('a beach week in June')
    })

    it('leaves the form alone and says why when the rule is refused', async () => {
        const wrapper = await screen()

        post.mockRejectedValueOnce({
            response: { status: 422, data: { errors: { text: ['Orbit could not read a trip out of that.'] } } },
        })

        await wrapper.find('.cta').trigger('click')
        await flushPromises()

        expect(wrapper.find('.error').text()).toBe('Orbit could not read a trip out of that.')
        expect(wrapper.text()).not.toContain('Rule created')
        expect(wrapper.find('textarea').element.value).toContain('cheap weekend')
    })
})
