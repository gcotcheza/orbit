// The rest of the world (3,270 airports) via `?q=` against `GET /api/airports`. A composable, not a
// Pinia store: each search field needs its own query (docs/BUSINESS-LOGIC.md §36).
import { onScopeDispose, ref } from 'vue'
import { http } from '@/lib/http'
import { markRow, MAX_SUGGESTIONS } from '@/stores/destinations'

/**
 * A finished airport code. Upper-cased at the request boundary (here), not per keystroke in the
 * field — see AirportField.vue (docs/BUSINESS-LOGIC.md §36).
 */
export const IATA = /^[A-Z]{3}$/

/**
 * @param {string} value what a box holds
 * @returns {string}
 */
export function toCode(value) {
    return value.trim().toUpperCase()
}

/**
 * Below this, don't ask — a single letter matches ~1/3 of the table for ten arbitrary rows.
 * SearchAirportsRequest enforces the same floor server-side (docs/BUSINESS-LOGIC.md §36).
 */
export const MIN_QUERY = 2

/**
 * Fast enough to feel responsive, slow enough that a fast typist produces one request per word.
 * (The rule parser uses 500ms because it costs money.)
 */
export const DEBOUNCE_MS = 250

/**
 * One box's search of the airport table.
 *
 * @returns {{results: import('vue').Ref<Array<object>>, status: import('vue').Ref<string>, search: (term: string) => void, clear: () => void}}
 */
export function useAirportSearch() {
    /** `GET /api/airports`'s `data` for the CURRENT query: { iata, city, country, countryCode }. */
    const results = ref([])

    /** idle | searching | ready | failed */
    const status = ref('idle')

    let timer = null
    let controller = null

    // Which request is the current one — incremented on every call (including cancels) so a stale
    // reply is discarded rather than rendered.
    let sequence = 0

    /**
     * Look for what is in the box, in a moment, unless it changes again first.
     *
     * @param {string} term
     */
    function search(term) {
        const query = term.trim()

        cancel()

        if (query.length < MIN_QUERY) {
            // Not `failed`, and not the previous query's rows either — an emptied box has no
            // answer; showing stale results would argue with the field.
            results.value = []
            status.value = 'idle'

            return
        }

        const mine = ++sequence

        status.value = 'searching'

        timer = setTimeout(() => {
            timer = null
            controller = new AbortController()

            http.get('/api/airports', { params: { q: query }, signal: controller.signal })
                .then(({ data }) => {
                    if (mine !== sequence) {
                        return
                    }

                    results.value = data.data
                    status.value = 'ready'
                })
                .catch((failure) => {
                    // An abort rejects here just like a 500 does, and isn't a failure — it's this
                    // store's own doing; the sequence guard tells them apart.
                    if (mine !== sequence) {
                        return
                    }

                    results.value = []
                    status.value = 'failed'

                    console.error('Could not search the world airport list.', failure)
                })
        }, DEBOUNCE_MS)
    }

    /**
     * Forget the query and whatever it found — called once a suggestion is taken, when a stray
     * in-flight request would answer nobody is watching.
     */
    function clear() {
        cancel()

        results.value = []
        status.value = 'idle'
    }

    /** Stop the pending debounce and the in-flight request, if there are any. */
    function cancel() {
        sequence += 1

        if (timer !== null) {
            clearTimeout(timer)
            timer = null
        }

        if (controller !== null) {
            controller.abort()
            controller = null
        }
    }

    // A disposed box is not waiting for an answer. `failSilently`: also called from a unit test
    // outside any component scope.
    onScopeDispose(cancel, true)

    return { results, status, search, clear }
}

/**
 * The two lists shown as one: curated always first, deduped by code, `world: true` the only thing
 * added, and `exclude` filtered BEFORE the limit cut (docs/BUSINESS-LOGIC.md §36).
 *
 * @param {Array<object>} curated already ranked and marked by searchDestinations
 * @param {Array<object>} world `GET /api/airports`'s rows, in the server's order
 * @param {string} query what is in the box, for the highlight
 * @param {number} limit
 * @param {string} exclude one IATA code never to suggest, or ''
 * @returns {Array<object>}
 */
export function mergeSuggestions(curated, world, query, limit = MAX_SUGGESTIONS, exclude = '') {
    const seen = new Set(curated.map((row) => row.iata))

    const rest = world
        .filter((row) => !seen.has(row.iata))
        .map((row) => ({ ...markRow(row, query), world: true }))

    return [...curated, ...rest].filter((row) => row.iata !== exclude).slice(0, limit)
}
