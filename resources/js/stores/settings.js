// =============================================================================
// Alert settings
// =============================================================================
// The seven switches on the alerts screen (design/README.md §6), and the three
// sensitivity levels the server describes.
//
// A STORE RATHER THAN COMPONENT STATE, for one concrete reason: PR11's alert
// screens and PR12's push-permission flow both need to know whether push is
// switched on, and a second component fetching /api/settings for itself would
// be a second copy of these seven booleans that can disagree with this one.
//
// OPTIMISTIC, AND HONEST ABOUT IT. Flipping a switch applies immediately —
// waiting on a round trip makes a toggle feel broken — and a failed PUT puts
// the old value back AND says why. A silent revert is worse than no optimism
// at all: the switch appears to work, then appears to have been forgotten.
// =============================================================================
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { http } from '@/lib/http'

export const useSettingsStore = defineStore('settings', () => {
    /** The writable object, exactly as `GET /api/settings` sends it. */
    const settings = ref(null)

    /** `meta.sensitivities` — level, name, minimumScore and the sentence. */
    const sensitivities = ref([])

    /** idle | loading | ready | failed */
    const status = ref('idle')

    const error = ref('')

    /*
     * WHICH REQUEST'S ANSWER TO BELIEVE. Two taps in quick succession put two
     * PUTs in flight, and they can come back in either order — adopting the
     * slower one would leave the screen showing the older of the two states
     * while the database holds the newer. Only the most recent request is
     * allowed to write to the store.
     */
    let sequence = 0

    const isReady = computed(() => settings.value !== null)

    /** The description of the level currently chosen, for the blurb. */
    const chosenSensitivity = computed(
        () => sensitivities.value.find((level) => level.level === settings.value?.sensitivity) ?? null,
    )

    function adopt(body) {
        settings.value = body.data
        sensitivities.value = body.meta?.sensitivities ?? []
    }

    /**
     * Load once per visit to the screen. Safe to call again — it will not
     * stack requests, and a failure can be retried by calling it.
     */
    async function load() {
        if (status.value === 'loading') {
            return
        }

        status.value = 'loading'
        error.value = ''

        const token = ++sequence

        try {
            const { data } = await http.get('/api/settings')

            if (token === sequence) {
                adopt(data)
                status.value = 'ready'
            }
        } catch (failure) {
            if (token === sequence) {
                status.value = 'failed'
                error.value = messageFor(failure)
            }

            console.error('Could not load the alert settings.', failure)
        }
    }

    /**
     * Change one or more settings.
     *
     * THE WHOLE OBJECT GOES BACK, because the endpoint is a PUT — see
     * docs/API.md for why an optional boolean is a switch that can be turned
     * on and never off. The patch is merged into what is on screen, which is
     * also what makes the optimistic value and the request agree.
     */
    async function change(patch) {
        if (settings.value === null) {
            return
        }

        const previous = { ...settings.value }
        const next = { ...settings.value, ...patch }

        settings.value = next
        error.value = ''

        const token = ++sequence

        try {
            const { data } = await http.put('/api/settings', next)

            if (token === sequence) {
                adopt(data)
            }
        } catch (failure) {
            if (token === sequence) {
                settings.value = previous
                error.value = messageFor(failure)
            }

            console.error('Could not save the alert settings.', failure)
        }
    }

    return { settings, sensitivities, status, error, isReady, chosenSensitivity, load, change }
})

/**
 * One sentence somebody can act on, out of whatever went wrong.
 *
 * The 422 branch reads the server's own message rather than writing one here:
 * App\Http\Requests\UpdateSettingsRequest phrases each rule for a person, and
 * restating them in the client would be two copies to keep in step.
 */
function messageFor(failure) {
    const response = failure.response

    if (!response) {
        return 'Could not reach Orbit. Your settings were not saved.'
    }

    switch (response.status) {
        case 422:
            return Object.values(response.data?.errors ?? {})[0]?.[0] ?? 'Orbit would not accept that.'
        case 419:
            return 'This page went stale. Reload it and try again.'
        default:
            return 'Something went wrong. Your settings were not saved.'
    }
}
