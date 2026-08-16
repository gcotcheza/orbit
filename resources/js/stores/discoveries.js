// =============================================================================
// Discoveries — the routes nobody asked about
// =============================================================================
// `GET /api/discoveries`, which is a precomputed table and not a search: the
// three origin sweeps, the five window fetches and the Google checks all
// happened at 05:20 in App\Jobs\DiscoverDeals. By the time this store asks, it
// is one indexed query over about ten rows.
//
// A STORE RATHER THAN A `fetch` IN Search.vue, for the reason stores/watchlist
// .js exists at all: this list is going to be read by a second screen. The home
// globe's teaser is the obvious next one, and two screens each fetching the
// same ten rows is how they come to show different sets on the same morning.
// It is cheap to put it here now and expensive to unpick later.
//
// STATUS AND ROWS, NOTHING ELSE. There are no getters: the search screen wants
// "the first few", a teaser would want "the best one", and both are one line of
// `computed` in the screen that needs it. A getter here would be a third
// opinion about a list with two legitimate ones.
//
// NO WRITES AT ALL, WHICH MAKES THIS THE SIMPLEST STORE IN THE APP. Nothing a
// person can do changes a discovery — tapping one navigates into the ordinary
// lookup flow (`/route/AMS-AGP`), and watching it from there is a write to the
// WATCHLIST, which stores/watchlist.js already owns. That separation is the
// whole reason this feature needed no new write endpoint.
// =============================================================================
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { http } from '@/lib/http'

export const useDiscoveriesStore = defineStore('discoveries', () => {
    /** Every live discovery, exactly as the API sends them: cheapest per km first. */
    const discoveries = ref([])

    /**
     * When this set was found — an ISO timestamp in the owner's timezone, or
     * null when there is nothing to have found.
     *
     * IT IS THE ONE THING ON THE STRIP THAT IS ABOUT THE RUN RATHER THAN ABOUT
     * A FARE, and it is here because without it the section makes an implicit
     * claim it cannot support: a heading with no date on it reads as "these
     * were checked when you opened the screen", and they were checked at 05:20.
     */
    const discoveredAt = ref(null)

    /*
     * idle | loading | ready | failed
     *
     * IT STARTS AT 'idle', WHICH IS THE OPPOSITE OF stores/watchlist.js AND IS
     * DELIBERATE. That list is the reason its screens exist, so 'loading' is
     * true from the first frame. This one is a SECTION BELOW A FORM: the search
     * screen is completely usable before it arrives and completely usable if it
     * never does, so there is a real state where nobody has asked yet and the
     * strip should render nothing at all rather than a skeleton.
     */
    const status = ref('idle')

    /**
     * Fetch the current set.
     *
     * NOT DEDUPED, for the reason the watchlist's `refresh` is not: it is one
     * GET of one small collection, and a call that quietly declined to fetch
     * would hand its caller a stale list with no way to know.
     *
     * THE ROWS ARE NOT CLEARED FIRST. A screen showing this strip is showing a
     * previous set, and blanking it during a refresh would make the section
     * jump on every visit for no information gained.
     *
     * A FAILURE IS QUIET. This is the one fetch in the app whose failure a
     * person should probably not be told about: nothing they did caused it,
     * nothing they can do fixes it, and the honest presentation of "we could
     * not load the deals nobody asked for" is no section at all. It is logged,
     * because a silent catch is how a broken endpoint survives a month.
     */
    async function refresh() {
        status.value = 'loading'

        try {
            const { data } = await http.get('/api/discoveries')

            discoveries.value = data.data
            discoveredAt.value = data.meta?.discoveredAt ?? null
            status.value = 'ready'
        } catch (failure) {
            // A 401 is nobody's problem here — lib/http.js sends the whole app
            // to the login screen.
            status.value = 'failed'
            console.error('Could not load discoveries.', failure)
        }
    }

    return { discoveries, discoveredAt, status, refresh }
})
