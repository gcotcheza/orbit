// The watch list — the routes the owner asked Orbit to price, and the
// writes that change them.
//
// Three screens read this one list (globe, watch screen, calendar); this
// store folds together what were three copies that could disagree.
// Why: docs/BUSINESS-LOGIC.md §36.
//
// State and actions, no getters — each screen's own slice (active-only,
// first code, a count) is one line of `computed` where it's needed.
// Why: docs/BUSINESS-LOGIC.md §36.
//
// Optimistic: `toggle`/`remove` apply immediately and revert on failure
// into `error` — a silent revert is how an app stops meaning what it shows.
// Why: docs/BUSINESS-LOGIC.md §36.
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { http } from '@/lib/http'

export const useWatchlistStore = defineStore('watchlist', () => {
    /** Every watched route, exactly as `GET /api/watchlist` sends them. */
    const routes = ref([])

    // loading | ready | failed
    //
    // Starts at 'loading', not 'idle' — every screen fetches in onMounted
    // (after first render), so 'idle' would flash for one frame.
    // Why: docs/BUSINESS-LOGIC.md §36.
    const status = ref('loading')

    /** One sentence about the last failed WRITE, for the screen to show. */
    const error = ref('')

    /**
     * Fetch the list.
     *
     * Deliberately not deduped or sequence-guarded — every caller awaits this and reads `routes`; it's one GET, running it
     * twice costs one request (docs/BUSINESS-LOGIC.md §36).
     *
     * Rows are not cleared first — a screen showing this list shows a status too, so stale rows stay visible instead of
     * the globe rebuilding for nothing (docs/BUSINESS-LOGIC.md §36).
     */
    async function refresh() {
        status.value = 'loading'
        // A sentence about a write that failed against the previous list has
        // nothing to say about this one.
        error.value = ''

        try {
            const { data } = await http.get('/api/watchlist')

            routes.value = data.data
            status.value = 'ready'
        } catch (failure) {
            // A 401 is nobody's problem here — lib/http.js sends the whole app
            // to the login screen. Anything else is the screen's to say.
            status.value = 'failed'
            console.error('Could not load the watchlist.', failure)
        }
    }

    /**
     * Pause or resume a route — the switch moves now; the server's answer
     * replaces the row, or reverts it if the request fails.
     */
    async function toggle(route, active) {
        const previous = route.active

        route.active = active
        error.value = ''

        try {
            const { data } = await http.patch(`/api/watchlist/${route.code}`, { active })

            Object.assign(route, data.data)
        } catch (failure) {
            route.active = previous
            error.value = `Could not ${active ? 'resume' : 'pause'} ${route.code}. Nothing changed.`

            console.error('Could not toggle a watched route.', failure)
        }
    }

    /**
     * Stop watching — the row leaves immediately and returns to the same
     * position on failure; order is the owner's, not the API's to shuffle.
     */
    async function remove(route) {
        const index = routes.value.indexOf(route)

        routes.value.splice(index, 1)
        error.value = ''

        try {
            await http.delete(`/api/watchlist/${route.code}`)
        } catch (failure) {
            routes.value.splice(index, 0, route)
            error.value = `Could not remove ${route.code}. It is still on the list.`

            console.error('Could not remove a watched route.', failure)
        }
    }

    /**
     * Watch a new pair.
     *
     * Throws rather than setting `error` — the one place this store differs from stores/rules.js; the add form phrases a
     * failed 422 itself (docs/BUSINESS-LOGIC.md §36).
     *
     * A new route arrives with no prices — that's correct, and WatchRow draws it rather than the screen waiting for the
     * queued poll (docs/BUSINESS-LOGIC.md §36).
     */
    async function add(origin, destination) {
        error.value = ''

        const { data } = await http.post('/api/watchlist', { origin, destination })

        routes.value.push(data.data)

        return data.data
    }

    /**
     * Take in a row that some OTHER endpoint just created — promoting a rule match makes the same write `add` does, but
     * from stores/rules.js (docs/BUSINESS-LOGIC.md §36).
     */
    function adopt(route) {
        routes.value.push(route)
    }

    return { routes, status, error, refresh, toggle, remove, add, adopt }
})
