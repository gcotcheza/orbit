// =============================================================================
// Everywhere else — the other 3,086 airports
// =============================================================================
// stores/destinations.js holds the 184 places Orbit has an OPINION about: they
// arrive in one request when the add form opens, they are searched in the
// browser, and every keystroke answers instantly out of memory. That design is
// unchanged and this file does not replace it.
//
// WHAT THIS ADDS is the rest of the world. Since the world import, `airports`
// holds every scheduled airport on Earth and `POST /api/routes/lookup` will
// price any pair of them — so a box that could only suggest 184 places was a
// box that hid the feature. 3,270 rows is ~200 KB, which is not a payload to
// send before somebody has typed anything, so this half is a `?q=` query
// against `GET /api/airports`.
//
// THREE THINGS MAKE A PER-KEYSTROKE ENDPOINT BEHAVE:
//
//   - A DEBOUNCE, so "tokyo" is one request rather than five.
//   - AN ABORT, so the one it replaces stops occupying a connection.
//   - A SEQUENCE GUARD, because aborting is a request to the network stack and
//     not a promise that nothing lands afterwards. "tok" answering after
//     "tokyo" would repaint the panel with the older, wider answer — the classic
//     typeahead flicker, and the reason the guard is a number rather than a
//     boolean.
//
// A FAILURE IS NOT FATAL, for the same reason it is not in the curated store:
// the suggestions are an assistance. The box still takes a three-letter code,
// the server still validates it, and the curated list is still there and still
// instant.
// =============================================================================
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { http } from '@/lib/http'
import { markRow, MAX_SUGGESTIONS } from '@/stores/destinations'

/**
 * Below this, don't ask.
 *
 * One letter matches something like a third of the table, and the ten rows
 * that come back are ten arbitrary ones — a worse answer than the curated list
 * gives for free, bought with a round trip. App\Http\Requests\SearchAirportsRequest
 * refuses it server-side too, so this is the cheap half of one rule.
 */
export const MIN_QUERY = 2

/**
 * Long enough that a fast typist produces one request per word, short enough
 * that the panel does not feel like it is thinking. The rule parser uses 500 ms
 * for a call that costs money; this one is free and can afford to be quicker.
 */
export const DEBOUNCE_MS = 250

export const useAirportsStore = defineStore('airports', () => {
    /** `GET /api/airports`'s `data` for the CURRENT query: { iata, city, country, countryCode }. */
    const results = ref([])

    /** idle | searching | ready | failed */
    const status = ref('idle')

    let timer = null
    let controller = null

    /*
     * WHICH REQUEST IS THE CURRENT ONE. Incremented on every call — including
     * the ones that only cancel — so a reply from a query somebody has already
     * typed past is discarded rather than rendered.
     */
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
            /*
             * NOT `failed`, and not the previous query's rows either. A box
             * being emptied has no answer, and showing the last one while
             * somebody deletes their way back to a single letter is the panel
             * arguing with the field.
             */
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
                    /*
                     * An abort rejects here exactly like a 500 does, and it is
                     * not a failure — it is this store's own doing. The
                     * sequence guard tells them apart without having to know
                     * what axios calls a cancellation this year: anything that
                     * is not the current request has nothing to say about the
                     * current state.
                     */
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
     * Forget the query and whatever it found.
     *
     * Called when a suggestion is taken and when the form is reset — at that
     * point the box holds a three-letter code, the panel is closed, and a
     * request for "BIO" would be one nobody is going to look at.
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

    return { results, status, search, clear }
})

// -----------------------------------------------------------------------------
// The join
// -----------------------------------------------------------------------------

/**
 * The two lists, shown as one.
 *
 * CURATED FIRST, ALWAYS, and it is not favouritism. Those 184 rows are the ones
 * a person wrote down: the city is the city somebody would say ("Sydney", not
 * "Sydney (Mascot)"), the airport has the name it is called by, and they are
 * the only rows the rule engine can ever match — so somebody typing "lisb" who
 * meant Lisbon must get Lisbon, not one of the four other airports with those
 * letters in them. Within each half the order is the one that half already
 * decided: the client's ranking for the curated rows, the server's for the
 * rest.
 *
 * DEDUPED BY CODE, because the world endpoint searches the WHOLE airports
 * table and the curated rows are in it. Amsterdam typed into the box would
 * otherwise offer AMS twice, from two tiers, with two spellings of the same
 * airport's name.
 *
 * `world: true` IS THE ONLY THING ADDED, and the form uses it to draw one
 * quiet divider rather than a badge per row. What it means is "Orbit will
 * price this and has no opinion about it" — see docs/BUSINESS-LOGIC.md §1.
 *
 * @param {Array<object>} curated already ranked and marked by searchDestinations
 * @param {Array<object>} world `GET /api/airports`'s rows, in the server's order
 * @param {string} query what is in the box, for the highlight
 * @param {number} limit
 * @returns {Array<object>}
 */
export function mergeSuggestions(curated, world, query, limit = MAX_SUGGESTIONS) {
    const seen = new Set(curated.map((row) => row.iata))

    const rest = world
        .filter((row) => !seen.has(row.iata))
        .map((row) => ({ ...markRow(row, query), world: true }))

    return [...curated, ...rest].slice(0, limit)
}
