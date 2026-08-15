// =============================================================================
// Everywhere Orbit can fly to
// =============================================================================
// The add-route form's suggestion list, and the search that filters it.
//
// FETCHED ONCE, FILTERED IN THE BROWSER. `GET /api/destinations` is a hundred
// and eighty-four rows of four short strings — a few kilobytes — and the list
// changes when somebody edits a file in the repository, not while an app is
// open. So it is loaded the first time the add form is opened and kept for the
// life of the page, and every keystroke after that is an array filter rather
// than a request. A `?q=` endpoint here would put a network round trip between
// a letter and the suggestion it should produce, on a phone, for no freshness
// anybody could observe.
//
// THE OTHER 3,086 AIRPORTS ARE stores/airports.js, and they ARE a `?q=`
// endpoint — 3,270 rows is 200 KB, which is a different argument with a
// different answer. This file is still the instant half, and still first.
//
// THE SEARCH IS EXPORTED AS A PURE FUNCTION rather than living in the
// component, because the ranking is the part with opinions in it — a person
// typing "bil" means Bilbao before Bilbao's country, and "por" means Porto
// before Portugal — and opinions are worth testing without mounting anything.
// =============================================================================
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { http } from '@/lib/http'

export const useDestinationsStore = defineStore('destinations', () => {
    /** `GET /api/destinations`'s `data`: { iata, city, country, countryCode }. */
    const destinations = ref([])

    /** idle | loading | ready | failed */
    const status = ref('idle')

    /*
     * THE IN-FLIGHT PROMISE, not a boolean. Two callers on the same tick —
     * the form mounting while something else asks — must produce one request
     * and both must be able to await it. A `if (loading) return` guard gives
     * the second caller a resolved promise and an empty list.
     */
    let request = null

    /**
     * Load the list, once.
     *
     * A FAILURE IS NOT FATAL AND MUST NOT BE. The suggestions are an
     * assistance; the box still takes a three-letter code and the server still
     * validates it, so a load that never lands leaves the form exactly as
     * useful as it was before this feature existed.
     */
    async function load() {
        if (status.value === 'ready') {
            return
        }

        if (request) {
            return request
        }

        status.value = 'loading'

        request = http
            .get('/api/destinations')
            .then(({ data }) => {
                destinations.value = data.data
                status.value = 'ready'
            })
            .catch((failure) => {
                status.value = 'failed'

                console.error('Could not load the destination list.', failure)
            })
            .finally(() => {
                request = null
            })

        return request
    }

    return { destinations, status, load }
})

// -----------------------------------------------------------------------------
// The search
// -----------------------------------------------------------------------------

/** How many suggestions a phone can show without becoming a page. */
export const MAX_SUGGESTIONS = 8

/**
 * Lower-cased and unaccented, ONE OUTPUT CHARACTER PER INPUT CHARACTER.
 *
 * The length invariant is not decoration: the highlight below finds the match
 * in the folded string and then slices the ORIGINAL with those indices, so
 * "Málaga" has to fold to something exactly six characters long or the bold
 * run lands one letter to the left. Folding per code point and keeping any
 * character whose folded form is not the same length is what guarantees it.
 *
 * @param {string} value
 * @returns {string}
 */
export function fold(value) {
    return Array.from(value, (character) => {
        const bare = character.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase()

        return bare.length === character.length ? bare : character
    }).join('')
}

/**
 * The three fields a suggestion is searched on, best match first.
 *
 * PREFIXES BEAT SUBSTRINGS, AND THE CODE BEATS THE PLACE. Somebody who types
 * three letters that are an airport code almost always means that airport, and
 * somebody typing the start of a city means the city rather than the four
 * countries with those letters in the middle of their name. Within a rank the
 * server's alphabetical order survives, because `Array.prototype.sort` is
 * stable — so the ties read as a list rather than as a shuffle.
 */
const RANKS = [
    (folded, query) => folded.iata.startsWith(query),
    (folded, query) => folded.city.startsWith(query),
    // "palmas" should find Las Palmas: a word inside the name, not just the
    // first one.
    (folded, query) => folded.city.split(' ').some((word) => word.startsWith(query)),
    (folded, query) => folded.country.startsWith(query),
    (folded, query) => folded.city.includes(query),
    (folded, query) => folded.country.includes(query),
]

/**
 * The suggestions for what somebody has typed.
 *
 * @param {Array<object>} destinations `GET /api/destinations`'s rows
 * @param {string} query what is in the box
 * @param {number} limit
 * @returns {Array<object>} the rows, each with a `marks` field for the highlight
 */
export function searchDestinations(destinations, query, limit = MAX_SUGGESTIONS) {
    const needle = fold(query.trim())

    if (needle === '') {
        return []
    }

    const ranked = []

    for (const destination of destinations) {
        const folded = {
            iata: fold(destination.iata),
            city: fold(destination.city),
            country: fold(destination.country),
        }

        const rank = RANKS.findIndex((matches) => matches(folded, needle))

        if (rank !== -1) {
            ranked.push({ rank, destination, folded })
        }
    }

    return ranked
        .sort((one, other) => one.rank - other.rank)
        .slice(0, limit)
        .map(({ destination, folded }) => ({
            ...destination,
            marks: {
                city: mark(destination.city, folded.city, needle),
                iata: mark(destination.iata, folded.iata, needle),
                country: mark(destination.country, folded.country, needle),
            },
        }))
}

/**
 * One row from somewhere else, given the same highlight these produce.
 *
 * WHY IT IS SEPARATE FROM THE SEARCH ABOVE. `GET /api/airports` ranks and
 * returns its own ten rows (App\Http\Controllers\AirportController) — it has
 * to, because it is searching 3,270 of them and the browser has none of them —
 * and those rows arrive without marks. Running them back through
 * `searchDestinations` to get some would silently DROP the ones the server
 * matched on the airport's own name, which is not one of the fields ranked
 * here: search "kennedy", the server answers JFK, and the client throws it
 * away. So the server's order is kept and only the highlight is added.
 *
 * A field the query is not in keeps its text and marks nothing, which is what
 * `mark` already does with a needle it cannot find.
 *
 * @param {object} row `{ iata, city, country, countryCode }`
 * @param {string} query what is in the box
 * @returns {object} the row with a `marks` field
 */
export function markRow(row, query) {
    const needle = fold(query.trim())

    return {
        ...row,
        marks: {
            city: mark(row.city, fold(row.city), needle),
            iata: mark(row.iata, fold(row.iata), needle),
            country: mark(row.country, fold(row.country), needle),
        },
    }
}

// -----------------------------------------------------------------------------
// The typo fallback
// -----------------------------------------------------------------------------
// "No matching destination." was a DEAD END, and the way people spell is what
// makes it one. Type "barcelna" — one missing letter — and every rank above
// fails, because they are all prefix and substring tests and a typo breaks all
// of them at once. The screen then says nothing matches, which is true and
// useless: the answer is three feet away and the box will not admit it.
//
// EDIT DISTANCE ≤ 2, AGAINST CITY NAMES ONLY. Two is one transposition plus one
// slip, which is what a thumb produces; three starts matching words that are
// genuinely different places. Codes and countries are deliberately not fuzzed —
// a three-letter code is two edits from dozens of others, and "Did you mean
// Spain?" for a mistyped code would be a guess with nothing behind it.
//
// IT ONLY RUNS WHEN THE ORDINARY SEARCH FOUND NOTHING. It is a fallback, not a
// rank: a query with real matches must never have a guess mixed into them.

/** One transposition plus one slip. Three is a different word. */
export const MAX_TYPO_DISTANCE = 2

/**
 * Shorter than this and a "did you mean" is a coin toss: "bar" is within two
 * edits of Bari, Basel, Barcelona and a dozen more, and the ranked search
 * already answers short queries well.
 */
const MIN_TYPO_LENGTH = 4

/**
 * Levenshtein distance, abandoned as soon as it passes `max`.
 *
 * TWO ROWS, NOT A MATRIX: the recurrence only ever reads the previous row, and
 * this runs over a hundred and eighty-four names on a keystroke. The early exits are what
 * make that free — a length difference bigger than `max` cannot be closed by
 * substitutions, and a row whose cheapest cell already exceeds `max` cannot
 * produce a final cell below it, because every step costs at least zero.
 *
 * Returns `max + 1` rather than the true distance once it has given up: every
 * caller is asking "is this within max", and the exact value of a distance that
 * is not is nobody's business.
 *
 * @param {string} one
 * @param {string} other
 * @param {number} max
 * @returns {number}
 */
export function editDistance(one, other, max = MAX_TYPO_DISTANCE) {
    if (Math.abs(one.length - other.length) > max) {
        return max + 1
    }

    let previous = Array.from({ length: other.length + 1 }, (_, index) => index)

    for (let i = 1; i <= one.length; i += 1) {
        const current = [i]
        let best = i

        for (let j = 1; j <= other.length; j += 1) {
            const cost = one[i - 1] === other[j - 1] ? 0 : 1

            current[j] = Math.min(previous[j] + 1, current[j - 1] + 1, previous[j - 1] + cost)
            best = Math.min(best, current[j])
        }

        if (best > max) {
            return max + 1
        }

        previous = current
    }

    return previous[other.length]
}

/**
 * The one destination somebody probably meant, or null.
 *
 * Nearest wins; ties keep the server's alphabetical order, because `<` rather
 * than `<=` leaves the first one in place.
 *
 * @param {Array<object>} destinations `GET /api/destinations`'s rows
 * @param {string} query what is in the box
 * @returns {object|null} one row, in the same shape a suggestion has minus the
 *                        marks — there is nothing to highlight in a word that
 *                        was not typed
 */
export function nearestDestination(destinations, query) {
    const needle = fold(query.trim())

    if (needle.length < MIN_TYPO_LENGTH) {
        return null
    }

    let best = null
    let distance = MAX_TYPO_DISTANCE + 1

    for (const destination of destinations) {
        const found = editDistance(needle, fold(destination.city))

        if (found < distance) {
            best = destination
            distance = found
        }
    }

    return distance <= MAX_TYPO_DISTANCE ? best : null
}

/**
 * One field split into what comes before the match, the match, and the rest.
 *
 * Three strings rather than a string of HTML: the suggestion row renders them
 * as three text nodes, so there is no `v-html` anywhere near a box somebody
 * types into.
 *
 * @param {string} value the original, accents and capitals intact
 * @param {string} folded `fold(value)`, the same length
 * @param {string} needle already folded
 * @returns {{before: string, match: string, after: string}}
 */
function mark(value, folded, needle) {
    const at = folded.indexOf(needle)

    if (at === -1) {
        return { before: value, match: '', after: '' }
    }

    return {
        before: value.slice(0, at),
        match: value.slice(at, at + needle.length),
        after: value.slice(at + needle.length),
    }
}
