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
//
// =============================================================================
// A COMPOSABLE, NOT A PINIA STORE — which it was, for exactly as long as there
// was one box
// =============================================================================
// stores/destinations.js next door IS a store and should be: 184 rows fetched
// once and read by everything, which is the definition of shared state. This
// file held the same shape by analogy and it was wrong the moment the search
// screen grew a SECOND field. `results` and `status` are the answer to the
// query in ONE box — a singleton would have the From field repainting itself
// with what somebody typed into To, and the debounce, the abort and the
// sequence guard below are all per-box timers pretending to be global ones.
//
// SO EACH FIELD CALLS THIS AND KEEPS WHAT IT GETS. Two callers, two independent
// searches, and nothing to reset between screens because the state dies with
// the component that asked for it (`onScopeDispose`).
//
// THE FILE STAYS IN stores/ because it is still where "everywhere Orbit can
// price" is fetched from, and because App\Http\Controllers\AirportController
// and docs/API.md both name this path.
// =============================================================================
import { onScopeDispose, ref } from 'vue'
import { http } from '@/lib/http'
import { markRow, MAX_SUGGESTIONS } from '@/stores/destinations'

/**
 * What a finished airport code looks like, and how a box's contents become one.
 *
 * THE UPPER-CASING IS A BOUNDARY AND NOT A KEYSTROKE. A field that shouts
 * "LISBON" back at somebody typing "Lisbon" reads as a complaint about what was
 * just typed — see AirportField.vue — so the capitals are applied here, once,
 * where the value stops being what is on screen and starts being what goes in a
 * request. Route codes are `AMS-LIS` (App\Models\Route::codeFor) and always
 * have been.
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
     * Called when a suggestion is taken — at that point the box holds a
     * three-letter code, the panel is closed, and a request for "BIO" would be
     * one nobody is going to look at.
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

    /*
     * A BOX THAT HAS GONE AWAY IS NOT WAITING FOR AN ANSWER. Leaving the search
     * screen mid-debounce would otherwise fire the request 250 ms later and
     * resolve it into refs nothing renders — harmless, and still a request made
     * on behalf of a screen that no longer exists.
     *
     * `failSilently` because this is legitimately called outside a component in
     * its own unit test, where there is no scope to dispose and nothing to warn
     * about.
     */
    onScopeDispose(cancel, true)

    return { results, status, search, clear }
}

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
 * `exclude` IS THE OTHER END OF THE PAIR, and it is filtered here rather than in
 * the component because it has to happen BEFORE the cut to `limit`. Dropping a
 * row from eight afterwards leaves seven suggestions on a panel that had room
 * for eight — the excluded airport silently costs somebody a result. What it
 * means is the precise version of "never suggest a route from a place to
 * itself": the From box will not offer what To holds, and vice versa.
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
