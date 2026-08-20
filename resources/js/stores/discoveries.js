// Discoveries — the routes nobody asked about.
//
// GET /api/discoveries is a precomputed table (App\Jobs\DiscoverDeals, 05:20), not a live search.
// Why: docs/BUSINESS-LOGIC.md §36.
//
// A store, not an inline fetch in Search.vue, since a second screen (the home teaser) will read this same list.
// Why: docs/BUSINESS-LOGIC.md §36.
//
// Status and rows only — no getters; each screen's "which rows" question is its own.
// Why: docs/BUSINESS-LOGIC.md §36.
//
// No writes at all — watching a discovery is a write to stores/watchlist.js, not here.
// Why: docs/BUSINESS-LOGIC.md §36.
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
     * Without it, the heading implies "checked when you opened the screen" rather than at 05:20.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    const discoveredAt = ref(null)

    /*
     * idle | loading | ready | failed
     *
     * Starts at 'idle', unlike stores/watchlist.js — this strip is optional, so nothing renders until asked.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    const status = ref('idle')

    /**
     * Fetch the current set.
     *
     * Not deduped — one GET of a small collection; declining silently would leave a stale list.
     * Why: docs/BUSINESS-LOGIC.md §36.
     *
     * Rows are not cleared first, so the section doesn't jump on every visit.
     * Why: docs/BUSINESS-LOGIC.md §36.
     *
     * A failure is quiet (logged only) — nobody caused it and nobody can fix it.
     * Why: docs/BUSINESS-LOGIC.md §36.
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
