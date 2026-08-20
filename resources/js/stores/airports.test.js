// @vitest-environment jsdom
// The world half of the typeahead — a network search, so everything worth testing is a race (debounce, abort, sequence guard).
// Why: docs/BUSINESS-LOGIC.md §36.
//
// Fake timers throughout: the debounce is 250ms, and waiting for it for real
// would make this suite slow and flaky.
//
// A composable, not a singleton store — each test calls useAirportSearch() for its own box.
// Why: docs/BUSINESS-LOGIC.md §36.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()

vi.mock('@/lib/http', () => ({ http: { get: (...args) => get(...args) } }))

import { DEBOUNCE_MS, IATA, mergeSuggestions, MIN_QUERY, toCode, useAirportSearch } from './airports'
import { MAX_SUGGESTIONS } from './destinations'

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
    get.mockReset()
})

afterEach(() => {
    vi.useRealTimers()
})

describe('the airport search', () => {
    it('asks for nothing until the debounce has passed', async () => {
        get.mockResolvedValue(answer([JFK]))

        const box = useAirportSearch()

        box.search('new')

        expect(get).not.toHaveBeenCalled()
        expect(box.status.value).toBe('searching')

        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        expect(get).toHaveBeenCalledTimes(1)
        expect(get).toHaveBeenCalledWith('/api/airports', expect.objectContaining({ params: { q: 'new' } }))
        expect(box.results.value).toEqual([JFK])
        expect(box.status.value).toBe('ready')
    })

    it('turns a typed word into one request', async () => {
        get.mockResolvedValue(answer([JFK]))

        const box = useAirportSearch()

        for (const typed of ['ne', 'new', 'new ', 'new y', 'new yo']) {
            box.search(typed)
            await vi.advanceTimersByTimeAsync(DEBOUNCE_MS / 5)
        }

        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        expect(get).toHaveBeenCalledTimes(1)
        expect(get).toHaveBeenCalledWith('/api/airports', expect.objectContaining({ params: { q: 'new yo' } }))
    })

    it('does not ask about one character, and forgets what it found', async () => {
        get.mockResolvedValue(answer([JFK]))

        const box = useAirportSearch()

        box.search('new')
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)
        expect(box.results.value).toEqual([JFK])

        /* Backspaced down to a single letter: no request, and no stale panel. */
        box.search('n')
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        expect(get).toHaveBeenCalledTimes(1)
        expect(box.results.value).toEqual([])
        expect(box.status.value).toBe('idle')
        expect(MIN_QUERY).toBe(2)
    })

    it('sends what was typed with the whitespace taken off', async () => {
        get.mockResolvedValue(answer([]))

        const box = useAirportSearch()

        box.search('  new york  ')
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        expect(get).toHaveBeenCalledWith('/api/airports', expect.objectContaining({ params: { q: 'new york' } }))
    })

    it('aborts the request it replaces', async () => {
        const first = deferred()

        get.mockReturnValueOnce(first.promise).mockResolvedValue(answer([EWR]))

        const box = useAirportSearch()

        box.search('new')
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        const { signal } = get.mock.calls[0][1]

        expect(signal.aborted).toBe(false)

        box.search('newark')
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        expect(signal.aborted).toBe(true)
        expect(box.results.value).toEqual([EWR])
    })

    /**
     * The one the debounce and the abort both miss — an in-flight request can still resolve after being replaced, so only
     * the sequence guard stops flicker (docs/BUSINESS-LOGIC.md §36).
     */
    it('ignores an answer to a query that has been typed past', async () => {
        const slow = deferred()

        get.mockReturnValueOnce(slow.promise).mockResolvedValue(answer([EWR]))

        const box = useAirportSearch()

        box.search('new')
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        box.search('newark')
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        expect(box.results.value).toEqual([EWR])

        /* The overtaken request lands now, and has nothing to say. */
        slow.settle(answer([JFK, LGA]))
        await vi.advanceTimersByTimeAsync(0)

        expect(box.results.value).toEqual([EWR])
        expect(box.status.value).toBe('ready')
    })

    it('treats a failure as no suggestions rather than as a broken form', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {})
        get.mockRejectedValue(new Error('gateway'))

        const box = useAirportSearch()

        box.search('new')
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        expect(box.results.value).toEqual([])
        expect(box.status.value).toBe('failed')
    })

    /**
     * An abort rejects exactly like a 500 does — told apart by the sequence
     * guard, not by what axios calls a cancellation this year.
     */
    it('does not report its own cancellation as a failure', async () => {
        const cancelled = deferred()

        get.mockReturnValueOnce(cancelled.promise).mockResolvedValue(answer([EWR]))

        const box = useAirportSearch()

        box.search('new')
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS)

        box.clear()

        cancelled.settle(Promise.reject(new Error('canceled')))
        await vi.advanceTimersByTimeAsync(0)

        expect(box.status.value).toBe('idle')
        expect(box.results.value).toEqual([])
    })

    it('drops a pending search when it is cleared', async () => {
        get.mockResolvedValue(answer([JFK]))

        const box = useAirportSearch()

        box.search('new')
        box.clear()

        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS * 4)

        expect(get).not.toHaveBeenCalled()
        expect(box.status.value).toBe('idle')
    })
})

describe('toCode', () => {
    it('is what a box holds, turned into what a request takes', () => {
        expect(toCode('  lis ')).toBe('LIS')
        expect(toCode('Lisbon')).toBe('LISBON')
        expect(toCode('')).toBe('')
    })

    /*
     * Three letters and nothing else — "por" is mid-typing, "LISBON" is a
     * city name; only the middle case is a code.
     */
    it('recognises a finished code and nothing that merely looks like one', () => {
        expect(IATA.test(toCode('lis'))).toBe(true)
        expect(IATA.test(toCode('Lisbon'))).toBe(false)
        expect(IATA.test(toCode('li'))).toBe(false)
        expect(IATA.test('lis')).toBe(false)
    })
})

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

    /*
     * The other end of the pair, dropped from either half — a route from a
     * place to itself isn't a route.
     */
    it('never offers the airport the other box is holding', () => {
        const merged = mergeSuggestions([curatedRow(JFK)], [EWR, LGA], 'new', MAX_SUGGESTIONS, 'EWR')

        expect(merged.map((row) => row.iata)).toEqual(['JFK', 'LGA'])
        expect(mergeSuggestions([curatedRow(JFK)], [], 'new', MAX_SUGGESTIONS, 'JFK')).toEqual([])
    })

    /*
     * Dropped before the cut, not after — filtering the sliced result would
     * silently leave one fewer row than the panel has room for.
     */
    it('still fills the panel when a row has been excluded', () => {
        const world = Array.from({ length: 10 }, (_, index) => ({ ...EWR, iata: `X${index}0` }))

        expect(mergeSuggestions([curatedRow(JFK)], world, 'new', 4, 'X00')).toHaveLength(4)
        expect(mergeSuggestions([curatedRow(JFK)], world, 'new', 4, 'X00').map((row) => row.iata))
            .toEqual(['JFK', 'X10', 'X20', 'X30'])
    })
})
