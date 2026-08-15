<script setup>
/*
 * The watch list (design/README.md §5): a stack of boarding passes, one per
 * route, and an expander for adding another.
 *
 * IT READS THE SHARED LIST. This screen kept its own copy while the globe home
 * was being built against `/api/watchlist` in a parallel branch; both now read
 * stores/watchlist.js, which is where the optimistic writes live too. Nothing
 * here ever assumed it was the only reader — every write adopts the row the
 * server sends back — so the store inherited a screen that already trusted the
 * response over its own optimism.
 *
 * PAUSED ROUTES ARE IN THE LIST, dimmed, with the switch off. docs/API.md is
 * explicit that they arrive with `active: false` and must not be filtered out
 * — hiding a paused route makes turning it back on impossible from the only
 * screen that can.
 *
 * OPTIMISM AND ITS PRICE. The toggle and the remove both apply immediately and
 * both put the row back exactly where it was if the request fails, with a
 * banner saying so — see the store. A revert nobody can see is how an app
 * quietly stops meaning what it shows.
 */
import { computed, onMounted, ref, useTemplateRef } from 'vue'
import { storeToRefs } from 'pinia'
import AddRouteForm from '@/Components/watch/AddRouteForm.vue'
import RuleRow from '@/Components/rules/RuleRow.vue'
import WatchRow from '@/Components/watch/WatchRow.vue'
import { useRulesStore } from '@/stores/rules'
import { useWatchlistStore } from '@/stores/watchlist'

const watchlist = useWatchlistStore()
const { routes, status, error: notice } = storeToRefs(watchlist)

/*
 * THE RULES SECTION (design/README.md §4's rules, listed on §5's screen).
 *
 * A second store, because rules and routes are separate concepts (docs/PLAN.md)
 * that happen to share this screen: the create tab and this one are two views
 * of the rules list, and a rule written there has to appear here.
 */
const rules = useRulesStore()
const { rules: dealRules, status: rulesStatus, error: rulesError } = storeToRefs(rules)

/** Rule ids with a write in flight, so their switch can go inert. */
const busyRules = ref(new Set())

/** The code of the match currently being promoted to the watchlist. */
const watchingCode = ref('')

const addOpen = ref(false)
const addError = ref('')
const adding = ref(false)

/** Route codes with a write in flight, so their switch can go inert. */
const busyCodes = ref(new Set())

const addForm = useTemplateRef('addForm')

const countLine = computed(() => {
  const total = routes.value.length

  if (total === 0) {
    return 'Nothing on the list yet.'
  }

  const active = routes.value.filter((route) => route.active).length
  const routeWord = total === 1 ? 'route' : 'routes'

  return active === total
    ? `${total} ${routeWord} we're keeping an eye on.`
    : `${total} ${routeWord}, ${total - active} paused.`
})

onMounted(() => {
  watchlist.refresh()
  rules.load()
})

/** Pause or resume. The store moves the switch and answers for the write. */
async function toggle(route, active) {
  markBusy(route.code, true)

  try {
    await watchlist.toggle(route, active)
  } finally {
    markBusy(route.code, false)
  }
}

/**
 * Add a route.
 *
 * THE MESSAGE IS THIS SCREEN'S, not the store's: a refused add is answered
 * inside the form, beside the field that was refused, rather than in the banner
 * at the top — which is why the store's `add` throws instead of writing a
 * sentence of its own.
 */
async function add({ origin, destination }) {
  adding.value = true
  addError.value = ''

  try {
    await watchlist.add(origin, destination)

    // Cleared before the expander closes, so the field is empty when it is
    // opened again rather than still holding the code that just worked.
    addForm.value?.reset()
    addOpen.value = false
  } catch (failure) {
    addError.value = messageFor(failure)
    console.error('Could not add a route.', failure)
  } finally {
    adding.value = false
  }
}

function toggleAddForm() {
  addOpen.value = !addOpen.value
  addError.value = ''
}

/*
 * A NEW Set EACH TIME, not a mutation. Vue 3's reactivity does track Set
 * methods, but replacing the value keeps this readable next to the two ref
 * assignments around it, and the set never holds more than a handful of codes.
 */
function markBusy(code, busy) {
  const next = new Set(busyCodes.value)

  if (busy) {
    next.add(code)
  } else {
    next.delete(code)
  }

  busyCodes.value = next
}

/*
 * Pause or resume a rule. The store is optimistic and puts the switch back if
 * the write fails, exactly like the route toggle above.
 */
async function toggleRule(rule, active) {
  markRuleBusy(rule.id, true)

  try {
    await rules.toggle(rule, active)
  } finally {
    markRuleBusy(rule.id, false)
  }
}

/**
 * Promote one of a rule's matches to the watchlist.
 *
 * THE SAME WRITE THE ADD FORM MAKES, and the new row is dropped straight into
 * the list above rather than triggering a re-fetch — the response is in the
 * shape `GET /api/watchlist` sends, which is exactly why the API answers every
 * write with the row.
 */
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

function markRuleBusy(id, busy) {
  const next = new Set(busyRules.value)

  if (busy) {
    next.add(id)
  } else {
    next.delete(id)
  }

  busyRules.value = next
}

function messageFor(failure) {
  const response = failure.response

  if (!response) {
    return 'Could not reach Orbit. The route was not added.'
  }

  switch (response.status) {
    case 422:
      // The server's own sentences, per field — see
      // App\Http\Requests\AddWatchedRouteRequest.
      return Object.values(response.data?.errors ?? {})[0]?.[0] ?? 'Orbit would not accept that route.'
    case 419:
      return 'This page went stale. Reload it and try again.'
    default:
      return 'Something went wrong. The route was not added.'
  }
}
</script>

<template>
  <div class="screen">
    <header class="screen__head">
      <div>
        <h1 class="screen__title">Watch list</h1>
        <p class="screen__note">{{ countLine }}</p>
      </div>

      <button
        type="button"
        class="screen__add"
        :aria-expanded="addOpen"
        :aria-label="addOpen ? 'Close the add-route form' : 'Add a route'"
        @click="toggleAddForm"
      >
        <!-- Stroked from the style block: --on-solid on the accent fill, and a
             var() in a presentation attribute is not portable. -->
        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
          <path d="M10 4v12M4 10h12" stroke-width="2" stroke-linecap="round" />
        </svg>
      </button>
    </header>

    <AddRouteForm v-if="addOpen" ref="addForm" :error="addError" :busy="adding" @submit="add" />

    <p v-if="notice" class="screen__notice" role="alert">{{ notice }}</p>

    <p v-if="status === 'loading'" class="screen__state">Loading your routes…</p>

    <div v-else-if="status === 'failed'" class="screen__state">
      <p>Could not load your watch list.</p>
      <button type="button" class="screen__retry" @click="watchlist.refresh()">Try again</button>
    </div>

    <p v-else-if="routes.length === 0" class="screen__state">
      No routes yet. Tap <span class="screen__plus">+</span> to watch one — Orbit starts pricing it in the morning.
    </p>

    <div v-else class="screen__list">
      <WatchRow
        v-for="route in routes"
        :key="route.code"
        class="rise-in"
        :class="{ 'is-paused': !route.active }"
        :route="route"
        :busy="busyCodes.has(route.code)"
        @toggle="toggle(route, $event)"
        @remove="watchlist.remove(route)"
      />
    </div>

    <!--
      DEAL RULES, below the routes and deliberately quieter than them
      (design/README.md §4). The boarding passes above are the routes the owner
      chose; a rule is a standing question that may or may not have found
      anything yet, so it gets a hairline row rather than a card.

      The section is hidden entirely when there are no rules — an empty heading
      would be a promise of a feature rather than the feature, and the + tab is
      where a rule gets written.
    -->
    <section v-if="dealRules.length > 0 || rulesStatus === 'failed'" class="rules">
      <h2 class="rules__title">Deal rules</h2>

      <p v-if="rulesError" class="screen__notice" role="alert">{{ rulesError }}</p>

      <p v-if="rulesStatus === 'failed' && dealRules.length === 0" class="screen__state">
        Could not load your rules.
        <button type="button" class="screen__retry" @click="rules.load()">Try again</button>
      </p>

      <div class="rules__list">
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
  </div>
</template>

<style scoped>
.screen {
  padding: 4px var(--gutter) 0;
}

.screen__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;

  margin: 8px 2px 4px;
}

.screen__title {
  font-family: var(--font-display);
  font-size: var(--text-2xl);
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--ink);
}

.screen__note {
  margin-top: 2px;
  font-size: var(--text-lg);
  color: var(--muted);
}

.screen__add {
  display: flex;
  align-items: center;
  justify-content: center;

  width: 42px;
  height: 42px;
  flex-shrink: 0;

  border-radius: var(--radius-chip);
  background: var(--accent);
  box-shadow: 0 6px 16px var(--accent-glow);
}

.screen__add path {
  stroke: var(--on-solid);
}

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

.screen__plus {
  font-family: var(--font-display);
  font-weight: 700;
  color: var(--accent-ink);
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

.screen__list {
  display: flex;
  flex-direction: column;
  gap: 13px;

  margin-top: 14px;
}

/* A paused route is still on the list and still readable — dimmed, not
   hidden, because the switch that brings it back is on the card. */
.is-paused {
  opacity: 0.58;
}

/* -- Deal rules -----------------------------------------------------------
   Set apart from the routes by space and a hairline rather than by another
   card: the section is secondary to the boarding passes above it, and two
   competing card treatments on one screen is how a phone stops having a
   focal point. */
.rules {
  margin-top: 26px;
  padding-top: 18px;
  border-top: 1px solid var(--line2);
}

.rules__title {
  margin: 0 2px 11px;

  font-family: var(--font-display);
  font-size: 14px;
  font-weight: 600;
  color: var(--ink);
}

.rules__list {
  display: flex;
  flex-direction: column;
  gap: 9px;
}
</style>
