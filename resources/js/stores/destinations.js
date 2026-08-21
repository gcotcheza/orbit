// Everywhere Orbit can fly to — fetched once and filtered in the browser; the other 3,086
// airports are stores/airports.js and ARE a `?q=` endpoint (docs/BUSINESS-LOGIC.md §36).
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { http } from '@/lib/http'

export const useDestinationsStore = defineStore('destinations', () => {
    /** `GET /api/destinations`'s `data`: { iata, city, country, countryCode }. */
    const destinations = ref([])

    /** idle | loading | ready | failed */
    const status = ref('idle')

    /*
     * THE IN-FLIGHT PROMISE, not a boolean: two callers on the same tick must produce one
     * request and both must be able to await it.
     */
    let request = null

    /**
     * Load the list, once. A FAILURE IS NOT FATAL: the box still takes a three-letter code
     * and the server still validates it.
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

// ----------------------------------------------------------------------------- The search
// -----------------------------------------------------------------------------

/** How many suggestions a phone can show without becoming a page. */
export const MAX_SUGGESTIONS = 8

/**
 * Lower-cased and unaccented, ONE OUTPUT CHARACTER PER INPUT CHARACTER — the highlight slices
 * the ORIGINAL with indices found in the folded string, so the lengths must agree.
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
 * The three fields a suggestion is searched on, best match first: PREFIXES BEAT SUBSTRINGS,
 * AND THE CODE BEATS THE PLACE. Within a rank the server's alphabetical order survives.
 */
const RANKS = [
    (folded, query) => folded.iata.startsWith(query),
    (folded, query) => folded.city.startsWith(query),
    // "palmas" should find Las Palmas: a word inside the name, not just the first one.
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
 * One row from somewhere else, given the same highlight. Separate from the search above: the
 * server ranks its own ten rows, and re-ranking here would drop its name matches.
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

// --- The typo fallback ------------------------------------------------------
// Edit distance ≤ 2 against city names only, and only when the ordinary search found nothing.

/** One transposition plus one slip. Three is a different word. */
export const MAX_TYPO_DISTANCE = 2

/**
 * Shorter than this and a "did you mean" is a coin toss: "bar" is within two edits of Bari, Basel,
 * Barcelona and a dozen more, and the ranked search already answers short queries well.
 */
const MIN_TYPO_LENGTH = 4

/**
 * Levenshtein distance, abandoned as soon as it passes `max`. TWO ROWS, NOT A MATRIX, and the
 * early exits are what make it free; it returns `max + 1` rather than the true distance.
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
 * The one destination somebody probably meant, or null. Nearest wins; ties keep the server's
 * alphabetical order, because `<` rather than `<=` leaves the first one in place.
 *
 * @param {Array<object>} destinations `GET /api/destinations`'s rows
 * @param {string} query what is in the box
 * @returns {object|null} one row, in a suggestion's shape minus the marks
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
 * One field split into what comes before the match, the match, and the rest. Three strings
 * rather than HTML: the row renders three text nodes, so no `v-html` near a typed box.
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
