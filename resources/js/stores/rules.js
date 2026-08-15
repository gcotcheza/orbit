// =============================================================================
// Deal rules
// =============================================================================
// The trips the owner described in English (design/README.md §4), and the
// reading of the one they are typing right now.
//
// TWO HALVES THAT LOOK UNRELATED AND ARE NOT. `reading` is the create screen's
// live parse; `rules` is the watch screen's list. They share a store because
// they share a shape — every rule in the list carries the same `chips`,
// `criteria` and `matches` a parse does (docs/API.md) — and because creating a
// rule has to put the new row into a list the other screen may already be
// holding. Two stores would mean the watch tab showing a stale count the
// moment a rule was written.
//
// STALENESS IS THE HARD PART. The create screen parses on a 500 ms debounce
// while somebody types, so two parses are routinely in flight and they can
// come back in either order — adopting the slower one would leave the chips
// describing a sentence that is no longer on screen. Only the most recent
// request may write, which is the same `sequence` guard stores/settings.js
// uses and for the same reason.
// =============================================================================
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
     * Whether the current parse found anything at all.
     *
     * On `chips`, not on the text: a sentence Orbit could not read and an empty
     * box are the same answer from the server, and the screen says different
     * things about them using the text it already has.
     */
    const understood = computed(() => chips.value.length > 0)

    /**
     * Read a sentence back.
     *
     * The DEBOUNCE IS THE CALLER'S — Create.vue owns the 500 ms timer because
     * that is a fact about a textarea rather than about rules. What lives here
     * is the part a component cannot get right on its own: refusing to adopt
     * an answer that a newer request has already superseded.
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
     * Save the rule currently on the create screen.
     *
     * IT RETURNS THE ROW rather than only storing it, because the screen has
     * something to say afterwards — the design's created state names the rule
     * it just made. It throws on failure so the caller can leave the form as
     * it was; `error` carries the sentence to show.
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
     * Start watching one of a rule's matches.
     *
     * THE EXISTING WATCHLIST WRITE, not a new one — docs/PLAN.md is explicit
     * that a rule never adds a route on the owner's behalf, so this is the
     * same endpoint the add-route form uses and the tap is the owner's.
     *
     * IT RETURNS THE NEW WATCHLIST ROW, so the screen that already holds that
     * list can put it straight in rather than re-fetching — the response is in
     * exactly the shape `GET /api/watchlist` sends (docs/API.md). NULL when
     * the write failed; `error` carries the sentence to show.
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
 * One sentence somebody can act on, out of whatever went wrong.
 *
 * The 422 branch reads the server's own message rather than writing one here:
 * App\Http\Controllers\RuleController phrases the "could not read a trip out
 * of that" case for a person, and restating it in the client would be two
 * copies to keep in step.
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
