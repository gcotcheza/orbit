// Discoveries — the routes nobody asked about. A precomputed table (DiscoverDeals, 05:20), not
// a live search; status and rows only, and no writes at all (docs/BUSINESS-LOGIC.md §36).
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { http } from '@/lib/http'

export const useDiscoveriesStore = defineStore('discoveries', () => {
    /** Every live discovery, exactly as the API sends them: cheapest per km first. */
    const discoveries = ref([])

    /**
     * When this set was found — an ISO timestamp in the owner's timezone, or null when there is
     * nothing to have found. Without it the heading implies "checked when you opened".
     */
    const discoveredAt = ref(null)

    /*
     * idle | loading | ready | failed Starts at 'idle', unlike stores/watchlist.js — this strip is
     * optional, so nothing renders until asked (docs/BUSINESS-LOGIC.md §36).
     */
    const status = ref('idle')

    /**
     * Fetch the current set. Not deduped, rows are not cleared first so the section does not jump,
     * and a failure is quiet: nobody caused it and nobody can fix it.
     */
    async function refresh() {
        status.value = 'loading'

        try {
            const { data } = await http.get('/api/discoveries')

            discoveries.value = data.data
            discoveredAt.value = data.meta?.discoveredAt ?? null
            status.value = 'ready'
        } catch (failure) {
            // A 401 is nobody's problem here — lib/http.js sends the whole app to the login screen.
            status.value = 'failed'
            console.error('Could not load discoveries.', failure)
        }
    }

    return { discoveries, discoveredAt, status, refresh }
})
