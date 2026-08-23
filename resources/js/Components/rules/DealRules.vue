<script setup>
/*
 * The deal rules section — the watch list's own, and the create screen's master pane, so one
 * rendering serves both rather than two copies of it (docs/DESKTOP-LAYOUT-PLAN.md phase 3).
 */
import { onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { RouterLink } from 'vue-router'
import RuleRow from '@/Components/rules/RuleRow.vue'
import { useRulesStore } from '@/stores/rules'
import { useWatchlistStore } from '@/stores/watchlist'

defineProps({
  /* The store's `error` is the create screen's parse failure too, and that screen answers for it
     under the box that was refused — so it is not repeated here. */
  notice: { type: Boolean, default: true },
})

const rules = useRulesStore()
const { rules: dealRules, status, error } = storeToRefs(rules)

const watchlist = useWatchlistStore()

/** Rule ids with a write in flight, so their switch can go inert. */
const busyRules = ref(new Set())

/** The code of the match currently being promoted to the watchlist. */
const watchingCode = ref('')

onMounted(rules.load)

/*
 * Pause or resume a rule. The store is optimistic and puts the switch back if the write fails,
 * exactly like the watch list's route toggle.
 */
async function toggleRule(rule, active) {
  markRuleBusy(rule.id, true)

  try {
    await rules.toggle(rule, active)
  } finally {
    markRuleBusy(rule.id, false)
  }
}

// Promoting a match uses the same write the add form makes; the response already matches GET
// /api/watchlist's shape.
async function watchMatch(match) {
  watchingCode.value = match.code

  try {
    const added = await rules.watch(match)

    if (added) {
      watchlist.adopt(added)
    }
  } finally {
    watchingCode.value = ''
  }
}

// A new Set each time, not a mutation: replacing keeps this consistent with the ref assignments
// around it (the set is always tiny).
function markRuleBusy(id, busy) {
  const next = new Set(busyRules.value)

  if (busy) {
    next.add(id)
  } else {
    next.delete(id)
  }

  busyRules.value = next
}
</script>

<template>
  <!-- Always drawn: it is the only door to /create, so hiding it when empty would hide the way in. -->
  <section class="rules">
    <div class="rules__head">
      <h2 class="rules__title">Deal rules</h2>

      <!-- The one + on the watch list names what it makes ("New rule"): there used to be two
           identical accent squares there doing different writes. -->
      <RouterLink class="rules__new" :to="{ name: 'create' }">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <path d="M8 3v10M3 8h10" stroke-width="1.8" stroke-linecap="round" />
        </svg>
        New rule
      </RouterLink>
    </div>

    <p v-if="notice && error" class="screen__notice" role="alert">{{ error }}</p>

    <p v-if="status === 'failed' && dealRules.length === 0" class="screen__state">
      Could not load your rules.
      <button type="button" class="screen__retry" @click="rules.load()">Try again</button>
    </p>

    <p v-else-if="dealRules.length === 0 && status !== 'loading'" class="rules__empty">
      Rules watch for trips in plain English — “cheap weekend somewhere sunny, under €80”. Orbit reads one, then
      tells you when a trip like it turns up.
    </p>

    <div v-else class="rules__list">
      <RuleRow
        v-for="rule in dealRules"
        :key="rule.id"
        :rule="rule"
        :busy="busyRules.has(rule.id)"
        :watching="watchingCode"
        @toggle="toggleRule(rule, $event)"
        @remove="rules.remove(rule)"
        @watch="watchMatch"
      />
    </div>
  </section>
</template>

<style scoped>
/* The root's own spacing belongs to whichever screen mounted it — the watch list sets the hairline
   that separates it from the routes above; the create screen's master pane needs none. */

.rules__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;

  margin: 0 2px 11px;
}

.rules__title {
  font-family: var(--font-display);
  font-size: 14px;
  font-weight: 600;
  color: var(--ink);
}

/*
 * Accent as text, not a filled square: a solid button would make the rules section louder than the
 * routes it sits under. The negative margin keeps the 44px tap target.
 */
.rules__new {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;

  padding: 8px 6px;
  margin: -8px -6px;

  font-size: var(--text-md);
  font-weight: 700;
  color: var(--accent-ink);
  text-decoration: none;
}

.rules__new path {
  stroke: var(--accent-ink);
}

/* Body copy, not a card: there is nothing here yet, and drawing a box round an
   absence is how an empty state becomes an advert. */
.rules__empty {
  padding: 0 2px;

  font-size: var(--text-lg);
  line-height: 1.5;
  color: var(--muted);
}

.rules__list {
  display: flex;
  flex-direction: column;
  gap: 9px;
}

/* The two screen-level treatments this section uses, copied rather than inherited: scoped styles
   do not cross a component boundary, and the class names are what keep the watch list pixel-identical. */

.screen__notice {
  margin-top: 14px;
  padding: 10px 12px;
  border-radius: var(--radius-chip);

  font-size: var(--text-lg);
  color: var(--warn-ink);
  background: var(--warn-bg);
}

.screen__state {
  margin-top: 28px;
  padding: 0 4px;
  text-align: center;

  font-size: var(--text-lg);
  color: var(--muted);
}

.screen__retry {
  margin-top: 12px;
  padding: 9px 16px;
  border-radius: var(--radius-chip);
  border: 1px solid var(--line);

  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--ink2);
}
</style>
