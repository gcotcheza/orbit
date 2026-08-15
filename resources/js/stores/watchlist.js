// =============================================================================
// The watch list
// =============================================================================
// The routes the owner asked Orbit to price, and the writes that change them.
//
// THREE SCREENS READ THIS ONE LIST. The globe home tours it (design/README.md
// §1), the watch screen edits it (§5) and the calendar picks a route out of it
// for its chips (§3). Each of them fetched `/api/watchlist` for itself while
// they were being built in parallel branches, which meant three copies that
// could disagree the moment one of them wrote — pausing a route on the watch
// screen left the globe still touring it until the next reload. This store is
// the fold-together those files each flagged for the DRY pass.
//
// STATE AND ACTIONS, NOTHING ELSE. There are no getters here on purpose: the
// three screens want different slices — active routes only, the first route's
// code, a count line — and every one of those is one line of `computed` in the
// screen that needs it. A getter here would be a fourth opinion about a list
// that already has three legitimate ones.
//
// OPTIMISM AND ITS PRICE. `toggle` and `remove` apply immediately and put the
// row back exactly where it was if the request fails, leaving a sentence in
// `error` for the screen to show. A revert nobody can see is how an app quietly
// stops meaning what it shows.
// =============================================================================
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { http } from '@/lib/http'

export const useWatchlistStore = defineStore('watchlist', () => {
    /** Every watched route, exactly as `GET /api/watchlist` sends them. */
    const routes = ref([])

    /*
     * loading | ready | failed
     *
     * IT STARTS AT 'loading' RATHER THAN 'idle'. Every screen that reads this
     * list asks for it in onMounted, which runs after the first render — so an
     * 'idle' state would exist for exactly one frame, during which the globe
     * would draw its "nothing orbiting yet" notice over a list that is on its
     * way. There is no moment where a screen is showing this store and nobody
     * has asked for it.
     */
    const status = ref('loading')

    /** One sentence about the last failed WRITE, for the screen to show. */
    const error = ref('')

    /**
     * Fetch the list.
     *
     * DELIBERATELY NOT DEDUPED and deliberately not guarded by a sequence
     * token: every caller awaits this and then reads `routes` — the calendar
     * picks its first chip that way — so a call that quietly declined to fetch,
     * or that fetched and then declined to write, would hand its caller an
     * empty list and a screen saying the watchlist is empty. It is one GET of
     * one collection; running it twice costs a request and nothing else.
     *
     * THE ROWS ARE NOT CLEARED FIRST. A screen showing this list is showing a
     * status too, so the previous rows are never visible during a refresh —
     * and dropping them would make the globe rebuild its arcs for nothing.
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
     * Pause or resume a route. The switch moves now; the server's answer
     * replaces the row when it arrives, and puts the old value back if it
     * never does.
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
     * Stop watching. The row leaves the list immediately and comes back into
     * the same position if the request fails — order is the owner's, and a
     * route that reappeared at the bottom would be a second thing to explain.
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
     * IT THROWS RATHER THAN SETTING `error`, which is the one place this store
     * differs from stores/rules.js. A failed add is answered inside the add
     * form, next to the field that was refused — a 422 is usually about the
     * code that was typed — so the screen phrases it and this action stays out
     * of the way. Everything that succeeds lands in `routes` regardless.
     *
     * A brand-new route arrives with no prices at all; that is the correct
     * state and WatchRow draws it, rather than the screen waiting for a poll
     * that happens on the queue.
     */
    async function add(origin, destination) {
        error.value = ''

        const { data } = await http.post('/api/watchlist', { origin, destination })

        routes.value.push(data.data)

        return data.data
    }

    /**
     * Take in a row that some OTHER endpoint just created.
     *
     * Promoting a rule's match to the watchlist is the same write `add` makes,
     * but it is made by stores/rules.js — that store owns the match whose
     * button was tapped, and it answers with a row in exactly the shape
     * `GET /api/watchlist` sends (docs/API.md). This is how that row joins the
     * list without a second round trip.
     */
    function adopt(route) {
        routes.value.push(route)
    }

    return { routes, status, error, refresh, toggle, remove, add, adopt }
})
