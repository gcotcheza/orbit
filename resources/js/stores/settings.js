// Alert settings: the seven switches on the alerts screen (design/README.md §6). A store, not
// component state, and optimistic updates are honestly reverted (docs/BUSINESS-LOGIC.md §36).
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { http } from '@/lib/http'

export const useSettingsStore = defineStore('settings', () => {
    /** The writable object, exactly as `GET /api/settings` sends it. */
    const settings = ref(null)

    /** `meta.sensitivities` — level, name, minimumScore and the sentence. */
    const sensitivities = ref([])

    /** `meta.googleChecks` — { left, reserve, checkedAt }, null until the first response lands. */
    const googleChecks = ref(null)

    /** idle | loading | ready | failed */
    const status = ref('idle')

    const error = ref('')

    /*
     * Which request's answer to believe: two PUTs can land out of order, so only the most recent
     * request is allowed to write to the store.
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
        googleChecks.value = body.meta?.googleChecks ?? null
    }

    /**
     * Load once per visit to the screen. Safe to call again — it will not stack requests, and a
     * failure can be retried by calling it.
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
     * Change one or more settings. The whole object goes back (PUT, not PATCH) — see docs/API.md
     * for why an optional boolean can't be turned off.
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

    return { settings, sensitivities, googleChecks, status, error, isReady, chosenSensitivity, load, change }
})

/**
 * One sentence somebody can act on. The 422 branch reads the server's own message
 * (UpdateSettingsRequest) rather than duplicating it here.
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
