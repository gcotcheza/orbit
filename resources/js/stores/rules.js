// The trips the owner described in English (design/README.md §4), and the reading of the one they are typing right now
// (docs/BUSINESS-LOGIC.md §11).
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { http } from '@/lib/http'

export const useRulesStore = defineStore('rules', () => {
    /** The saved rules, newest first, exactly as `GET /api/rules` sends them. */
    const rules = ref([])

    /** idle | loading | ready | failed */
    const status = ref('idle')

    /** The live parse of the create screen's textarea: { chips, criteria, matches }. */
    const reading = ref(null)

    /** idle | parsing | ready | failed */
    const parseStatus = ref('idle')

    const error = ref('')

    let listSequence = 0
    let parseSequence = 0

    const isReady = computed(() => status.value === 'ready')

    const chips = computed(() => reading.value?.chips ?? [])
    const matches = computed(() => reading.value?.matches ?? { count: 0, cheapest: null, sample: [] })

    /**
     * Whether the current parse found anything at all. Checked via `chips`, not the raw text — server treats "unreadable"
     * and "empty" the same (docs/BUSINESS-LOGIC.md §11).
     */
    const understood = computed(() => chips.value.length > 0)

    /**
     * Read a sentence back. Debounce lives in Create.vue (a textarea concern); this only refuses stale responses
     * (docs/BUSINESS-LOGIC.md §11).
     */
    async function parse(text, removed = []) {
        parseStatus.value = 'parsing'
        error.value = ''

        const token = ++parseSequence

        try {
            const { data } = await http.post('/api/rules/parse', { text, removed })

            if (token === parseSequence) {
                reading.value = data.data
                parseStatus.value = 'ready'
            }
        } catch (failure) {
            if (token === parseSequence) {
                parseStatus.value = 'failed'
                error.value = messageFor(failure, 'Could not read that just now.')
            }

            console.error('Could not parse a rule.', failure)
        }
    }

    /** Throw the current reading away — leaving the create screen is leaving it. */
    function clearReading() {
        // Bumped so an in-flight parse cannot land after the screen has moved on.
        parseSequence++
        reading.value = null
        parseStatus.value = 'idle'
    }

    async function load() {
        if (status.value === 'loading') {
            return
        }

        status.value = 'loading'
        error.value = ''

        const token = ++listSequence

        try {
            const { data } = await http.get('/api/rules')

            if (token === listSequence) {
                rules.value = data.data
                status.value = 'ready'
            }
        } catch (failure) {
            if (token === listSequence) {
                status.value = 'failed'
                error.value = messageFor(failure, 'Could not load your rules.')
            }

            console.error('Could not load the deal rules.', failure)
        }
    }

    /**
     * Save the rule currently on the create screen. Returns the row (the created state names it) and throws on failure so
     * the form is left as-is (docs/BUSINESS-LOGIC.md §11).
     */
    async function create(text, removed = []) {
        error.value = ''

        try {
            const { data } = await http.post('/api/rules', { text, removed })

            // Newest first, matching the order the server lists them in.
            rules.value = [data.data, ...rules.value]

            return data.data
        } catch (failure) {
            error.value = messageFor(failure, 'Orbit could not save that rule.')

            console.error('Could not create a rule.', failure)

            throw failure
        }
    }

    /**
     * Pause a rule or start it again — optimistic, and honest when it fails.
     *
     * A silent revert is worse than no optimism at all: the switch appears to
     * work, then appears to have been forgotten.
     */
    async function toggle(rule, active) {
        const previous = rule.active

        rule.active = active
        error.value = ''

        try {
            const { data } = await http.patch(`/api/rules/${rule.id}`, { active })

            Object.assign(rule, data.data)
        } catch (failure) {
            rule.active = previous
            error.value = `Could not ${active ? 'resume' : 'pause'} that rule. Nothing changed.`

            console.error('Could not toggle a rule.', failure)
        }
    }

    /** Drop a rule. It goes back where it was if the request never lands. */
    async function remove(rule) {
        const index = rules.value.indexOf(rule)

        rules.value.splice(index, 1)
        error.value = ''

        try {
            await http.delete(`/api/rules/${rule.id}`)
        } catch (failure) {
            rules.value.splice(index, 0, rule)
            error.value = 'Could not remove that rule. It is still on the list.'

            console.error('Could not remove a rule.', failure)
        }
    }

    /**
     * Start watching one of a rule's matches. Reuses the add-route watchlist endpoint (a rule never adds on its own) and
     * returns the new row for the list to splice in (docs/BUSINESS-LOGIC.md §11).
     */
    async function watch(match) {
        error.value = ''

        try {
            const { data } = await http.post('/api/watchlist', {
                origin: match.origin.iata,
                destination: match.destination.iata,
            })

            // Marked locally so the button stops offering to add it again.
            match.watched = true

            return data.data
        } catch (failure) {
            error.value = messageFor(failure, `Could not start watching ${match.code}.`)

            console.error('Could not watch a rule match.', failure)

            return null
        }
    }

    return {
        rules,
        status,
        reading,
        parseStatus,
        error,
        isReady,
        chips,
        matches,
        understood,
        parse,
        clearReading,
        load,
        create,
        toggle,
        remove,
        watch,
    }
})

/**
 * One sentence somebody can act on, out of whatever went wrong. 422 uses the server's own message (RuleController)
 * rather than a client copy of it (docs/BUSINESS-LOGIC.md §11).
 */
function messageFor(failure, fallback) {
    const response = failure.response

    if (!response) {
        return 'Could not reach Orbit.'
    }

    switch (response.status) {
        case 422:
            return Object.values(response.data?.errors ?? {})[0]?.[0] ?? fallback
        case 429:
            return 'Slow down a moment — Orbit is still catching up.'
        case 419:
            return 'This page went stale. Reload it and try again.'
        default:
            return fallback
    }
}
