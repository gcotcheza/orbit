// @vitest-environment jsdom
// =============================================================================
// The world half of the typeahead
// =============================================================================
// stores/destinations.js is a list in memory and a pure ranking function, and
// its tests live beside the form. This one is a NETWORK store, and everything
// worth testing about it is a race:
//
//   - the debounce, so a word is one request rather than five;
//   - the abort, so the request it replaces is not left running;
//   - the sequence guard, so an answer to a query somebody has typed past can
//     never repaint the panel — which is the bug that survives a debounce and
//     an abort, because aborting is a request to the network stack rather than
//     a promise that nothing else lands.
//
// FAKE TIMERS THROUGHOUT, because the debounce is 250 ms and a suite that waits
// for it is a suite that takes a second per test and is flaky on a loaded box.
// =============================================================================
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()

vi.mock('@/lib/http', () => ({ http: { get: (...args) => get(...args) } }))

import { DEBOUNCE_MS, mergeSuggestions, MIN_QUERY, useAirportsStore } from './airports'

const JFK = { iata: 'JFK', city: 'New York', country: 'United States', countryCode: 'US' }
const LGA = { iata: 'LGA', city: 'New York', country: 'United States', countryCode: 'US' }
const EWR = { iata: 'EWR', city: 'Newark', country: 'United States', countryCode: 'US' }

/** One answer, in the endpoint's envelope. */
const answer = (rows) => ({ data: { data: rows, meta: { count: rows.length } } })

/** A promise this test resolves by hand, for the two-in-flight cases. */
function deferred() {
    let settle
    const promise = new Promise((resolve) => {
        settle = resolve
    })

    return { promise, settle }
}

beforeEach(() => {
    vi.useFakeTimers()
    setActivePinia(createPinia())
    get.mockReset()
})

afterEach(() => {
    vi.useRealTimers()
})

describe('the airport search store', () => {
    it('asks for nothing until the debounce has passed', async () => {
        get.mockResolvedValue(answer([JFK]))

        const store = useAirportsStore()

        store.search('new')

        expect(get).not.toHaveBeenCalled()
        expect(store.status).toBe('searching')

        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        expect(get).toHaveBeenCalledTimes(1)
        expect(get).toHaveBeenCalledWith('/api/airports', expect.objectContaining({ params: { q: 'new' } }))
        expect(store.results).toEqual([JFK])
        expect(store.status).toBe('ready')
    })

    it('turns a typed word into one request', async () => {
        get.mockResolvedValue(answer([JFK]))

        const store = useAirportsStore()

        for (const typed of ['ne', 'new', 'new ', 'new y', 'new yo']) {
            store.search(typed)
            await vi.advanceTimersByTimeAsync(DEBOUNCE_MS / 5)
        }

        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        expect(get).toHaveBeenCalledTimes(1)
        expect(get).toHaveBeenCalledWith('/api/airports', expect.objectContaining({ params: { q: 'new yo' } }))
    })

    it('does not ask about one character, and forgets what it found', async () => {
        get.mockResolvedValue(answer([JFK]))

        const store = useAirportsStore()

        store.search('new')
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)
        expect(store.results).toEqual([JFK])

        /* Backspaced down to a single letter: no request, and no stale panel. */
        store.search('n')
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        expect(get).toHaveBeenCalledTimes(1)
        expect(store.results).toEqual([])
        expect(store.status).toBe('idle')
        expect(MIN_QUERY).toBe(2)
    })

    it('sends what was typed with the whitespace taken off', async () => {
        get.mockResolvedValue(answer([]))

        const store = useAirportsStore()

        store.search('  new york  ')
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        expect(get).toHaveBeenCalledWith('/api/airports', expect.objectContaining({ params: { q: 'new york' } }))
    })

    it('aborts the request it replaces', async () => {
        const first = deferred()

        get.mockReturnValueOnce(first.promise).mockResolvedValue(answer([EWR]))

        const store = useAirportsStore()

        store.search('new')
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        const { signal } = get.mock.calls[0][1]

        expect(signal.aborted).toBe(false)

        store.search('newark')
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        expect(signal.aborted).toBe(true)
        expect(store.results).toEqual([EWR])
    })

    /**
     * THE ONE THE DEBOUNCE AND THE ABORT BOTH MISS. A request that is already
     * on the wire when it is replaced can still resolve — aborting asks the
     * browser to stop, it does not undo a response that has arrived — and
     * without the sequence guard "new" would repaint the panel over "newark"'s
     * answer, which is the classic typeahead flicker.
     */
    it('ignores an answer to a query that has been typed past', async () => {
        const slow = deferred()

        get.mockReturnValueOnce(slow.promise).mockResolvedValue(answer([EWR]))

        const store = useAirportsStore()

        store.search('new')
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        store.search('newark')
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        expect(store.results).toEqual([EWR])

        /* The overtaken request lands now, and has nothing to say. */
        slow.settle(answer([JFK, LGA]))
        await vi.advanceTimersByTimeAsync(0)

        expect(store.results).toEqual([EWR])
        expect(store.status).toBe('ready')
    })

    it('treats a failure as no suggestions rather than as a broken form', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {})
        get.mockRejectedValue(new Error('gateway'))

        const store = useAirportsStore()

        store.search('new')
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        expect(store.results).toEqual([])
        expect(store.status).toBe('failed')
    })

    /**
     * An abort rejects exactly like a 500 does. Told apart by the sequence
     * guard rather than by what axios calls a cancellation this year — so the
     * store must not go to `failed` when it is the one that did the cancelling.
     */
    it('does not report its own cancellation as a failure', async () => {
        const cancelled = deferred()

        get.mockReturnValueOnce(cancelled.promise).mockResolvedValue(answer([EWR]))

        const store = useAirportsStore()

        store.search('new')
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        store.clear()

        cancelled.settle(Promise.reject(new Error('canceled')))
        await vi.advanceTimersByTimeAsync(0)

        expect(store.status).toBe('idle')
        expect(store.results).toEqual([])
    })

    it('drops a pending search when it is cleared', async () => {
        get.mockResolvedValue(answer([JFK]))

        const store = useAirportsStore()

        store.search('new')
        store.clear()

        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS * 4)

        expect(get).not.toHaveBeenCalled()
        expect(store.status).toBe('idle')
    })
})

// -----------------------------------------------------------------------------
// The join
// -----------------------------------------------------------------------------

/** What searchDestinations produces: a row with a `marks` field. */
const curatedRow = (row) => ({ ...row, marks: { city: {}, iata: {}, country: {} } })

describe('mergeSuggestions', () => {
    it('puts the curated rows first, whatever the server sent', () => {
        const merged = mergeSuggestions([curatedRow(JFK)], [EWR, LGA], 'new')

        expect(merged.map((row) => row.iata)).toEqual(['JFK', 'EWR', 'LGA'])
    })

    it('drops a world row the curated list already offered', () => {
        // The world endpoint searches the WHOLE table, curated rows included.
        const merged = mergeSuggestions([curatedRow(JFK)], [JFK, LGA], 'new york')

        expect(merged.map((row) => row.iata)).toEqual(['JFK', 'LGA'])
    })

    it('marks which side of the join a row came from', () => {
        const merged = mergeSuggestions([curatedRow(JFK)], [LGA], 'new')

        expect(merged[0].world).toBeUndefined()
        expect(merged[1].world).toBe(true)
    })

    it('highlights a world row the same way the curated ones are', () => {
        const [row] = mergeSuggestions([], [EWR], 'newa')

        expect(row.marks.city).toEqual({ before: '', match: 'Newa', after: 'rk' })
        expect(row.marks.country).toEqual({ before: 'United States', match: '', after: '' })
    })

    it('never returns more than the panel can show', () => {
        const world = Array.from({ length: 10 }, (_, index) => ({ ...EWR, iata: `X${index}0` }))

        expect(mergeSuggestions([curatedRow(JFK)], world, 'new', 4)).toHaveLength(4)
    })

    it('is the curated list on its own when nothing has come back yet', () => {
        expect(mergeSuggestions([curatedRow(JFK)], [], 'new').map((row) => row.iata)).toEqual(['JFK'])
    })
})
