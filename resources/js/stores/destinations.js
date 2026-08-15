// =============================================================================
// Everywhere Orbit can fly to
// =============================================================================
// The add-route form's suggestion list, and the search that filters it.
//
// FETCHED ONCE, FILTERED IN THE BROWSER. `GET /api/destinations` is
// seventy-seven rows of four short strings — a few kilobytes — and the list
// changes when somebody edits a file in the repository, not while an app is
// open. So it is loaded the first time the add form is opened and kept for the
// life of the page, and every keystroke after that is an array filter rather
// than a request. A `?q=` endpoint here would put a network round trip between
// a letter and the suggestion it should produce, on a phone, for no freshness
// anybody could observe.
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
