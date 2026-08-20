<script setup>
// The watch list (design/README.md §5): boarding passes plus rules. This screen only lists —
// route-finding moved to Search.vue — and keeps paused routes visible.
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue'
import { storeToRefs } from 'pinia'
import { RouterLink } from 'vue-router'
import RuleRow from '@/Components/rules/RuleRow.vue'
import WatchRow from '@/Components/watch/WatchRow.vue'
import { scrollIntoView } from '@/lib/motion'
import { useRulesStore } from '@/stores/rules'
import { useWatchlistStore } from '@/stores/watchlist'

const watchlist = useWatchlistStore()
const { routes, status, error: notice } = storeToRefs(watchlist)

// Rules use a second store: rules and routes are separate concepts that happen to share this
// screen.
const rules = useRulesStore()
const { rules: dealRules, status: rulesStatus, error: rulesError } = storeToRefs(rules)

/** Rule ids with a write in flight, so their switch can go inert. */
const busyRules = ref(new Set())

/** The code of the match currently being promoted to the watchlist. */
const watchingCode = ref('')

// Undo is a real add write: removing a route deletes only the watchlist ROW, never its history, so
// a restored route comes back intact.
const UNDO_MS = 6000

/** The route just removed: `{ label, origin, destination }`, or null. */
const undo = ref(null)
const undoError = ref('')

let undoTimer = null

/** Route codes with a write in flight, so their switch can go inert. */
const busyCodes = ref(new Set())

const rulesSection = useTemplateRef('rulesSection')

// The "Rules · N" chip jumps to the rules section below; a count, not a link, and it renders only
// when there are rules.
const jumpToRules = () => scrollIntoView(rulesSection.value, { block: 'start' })

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

onBeforeUnmount(() => clearTimeout(undoTimer))

// Stop watching a route and offer undo; the store already reverts failed deletes and sets `notice`,
// so the undo offer only appears when the list actually changed (docs/BUSINESS-LOGIC.md §36).
async function remove(route) {
  const label = `${route.origin.iata}→${route.destination.iata}`

  undo.value = null
  undoError.value = ''

  await watchlist.remove(route)

  if (notice.value) {
    return
  }

  undo.value = { label, origin: route.origin.iata, destination: route.destination.iata }

  clearTimeout(undoTimer)
  undoTimer = setTimeout(() => {
    undo.value = null
  }, UNDO_MS)
}

/**
 * Put it back. The same write the add form makes — see the note on UNDO_MS for why that is enough
 * to restore the route rather than merely re-create it.
 */
async function undoRemove() {
  const removed = undo.value

  if (removed === null) {
    return
  }

  clearTimeout(undoTimer)
  undo.value = null

  try {
    await watchlist.add(removed.origin, removed.destination)
  } catch (failure) {
    undoError.value = `Could not put ${removed.label} back. Search for it and add it again.`

    console.error('Could not undo a removal.', failure)
  }
}

/** Pause or resume. The store moves the switch and answers for the write. */
async function toggle(route, active) {
  markBusy(route.code, true)

  try {
    await watchlist.toggle(route, active)
  } finally {
    markBusy(route.code, false)
  }
}

// A new Set each time, not a mutation: Vue 3 does track Set methods, but replacing keeps this
// consistent with the ref assignments around it (the set is always tiny).
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
 * Pause or resume a rule. The store is optimistic and puts the switch back if the write fails,
 * exactly like the route toggle above.
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
  <div class="screen">
    <header class="screen__head">
      <h1 class="screen__title">Watch list</h1>
      <p class="screen__note">{{ countLine }}</p>
    </header>

    <div class="screen__chips">
      <!-- A link, not a button, so browser navigation affordances survive: middle click,
           long press, status bar. -->
      <RouterLink class="screen__chip" :to="{ name: 'search' }">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <circle cx="7" cy="7" r="4.2" stroke-width="1.6" />
          <path d="m10 10 3 3" stroke-width="1.8" stroke-linecap="round" />
        </svg>
        Search for a route
      </RouterLink>

      <!-- The rules section is two and a half screens down; this is how anybody
           finds out it is there. See `jumpToRules`. -->
      <button
        v-if="dealRules.length > 0"
        type="button"
        class="screen__chip"
        :aria-label="`Go to your ${dealRules.length} deal ${dealRules.length === 1 ? 'rule' : 'rules'}`"
        @click="jumpToRules"
      >
        Rules · {{ dealRules.length }}
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <path d="M8 3.5v9M4.5 9l3.5 3.5L11.5 9" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </button>
    </div>

    <p v-if="notice" class="screen__notice" role="alert">{{ notice }}</p>
    <p v-if="undoError" class="screen__notice" role="alert">{{ undoError }}</p>

    <!-- `role="status"`, not `alert`: nothing went wrong, and an assertive announcement over a
         deliberate action would be the reader shouting about what the user just did. -->
    <p v-if="undo" class="screen__notice screen__notice--undo" role="status">
      Stopped watching {{ undo.label }}
      <button type="button" class="screen__undo" @click="undoRemove">Undo</button>
    </p>

    <p v-if="status === 'loading'" class="screen__state">Loading your routes…</p>

    <div v-else-if="status === 'failed'" class="screen__state">
      <p>Could not load your watch list.</p>
      <button type="button" class="screen__retry" @click="watchlist.refresh()">Try again</button>
    </div>

    <!-- Empty-state copy names the Search tab and says "look one up", not "watch one" —
         matching the search screen's price-without-commitment first step. -->
    <p v-else-if="routes.length === 0" class="screen__state">
      No routes yet. <RouterLink class="screen__link" :to="{ name: 'search' }">Search</RouterLink> for one to look up its
      price — you can start watching it from there, and Orbit prices it every morning after that.
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
        @remove="remove(route)"
      />
    </div>

    <!-- The deal rules section is deliberately quieter than the routes above, and is always
         drawn: it is the only door to /create, so hiding it when empty would hide the way in. -->
    <section ref="rulesSection" class="rules">
      <div class="rules__head">
        <h2 class="rules__title">Deal rules</h2>

        <!-- The one + left on this screen names what it makes ("New rule"): there used to be
             two identical accent squares here doing different writes. -->
        <RouterLink class="rules__new" :to="{ name: 'create' }">
          <svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M8 3v10M3 8h10" stroke-width="1.8" stroke-linecap="round" />
          </svg>
          New rule
        </RouterLink>
      </div>

      <p v-if="rulesError" class="screen__notice" role="alert">{{ rulesError }}</p>

      <p v-if="rulesStatus === 'failed' && dealRules.length === 0" class="screen__state">
        Could not load your rules.
        <button type="button" class="screen__retry" @click="rules.load()">Try again</button>
      </p>

      <p v-else-if="dealRules.length === 0 && rulesStatus !== 'loading'" class="rules__empty">
        Rules watch for trips in plain English — “cheap weekend somewhere sunny, under €80”. Orbit reads one, then tells
        you when a trip like it turns up.
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
  </div>
</template>

<style scoped>
.screen {
  padding: 4px var(--gutter) 0;
}

/* One column since the + left it: the header is a title and a count line, and
   the two chips under it are their own row. */
.screen__head {
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

/*
 * The app's inactive-chip vocabulary for "a second thing you may tap"; chips wrap rather than
 * shrink, since 44px tap targets are not negotiable.
 */
.screen__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;

  margin: 10px 2px 0;
}

.screen__chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;

  padding: 7px 12px;
  border: 1px solid var(--line);
  border-radius: var(--radius-pill);

  font-size: var(--text-md);
  font-weight: 600;
  color: var(--ink2);
  text-decoration: none;

  background: var(--card);
  box-shadow: var(--shadow);
}

.screen__chip circle,
.screen__chip path {
  stroke: var(--muted);
}

.screen__notice {
  margin-top: 14px;
  padding: 10px 12px;
  border-radius: var(--radius-chip);

  font-size: var(--text-lg);
  color: var(--warn-ink);
  background: var(--warn-bg);
}

/*
 * Same box as the failure notice but in the app's quiet colours: a deliberate removal is not a
 * warning, and the warn tint is reserved for something going wrong.
 */
.screen__notice--undo {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;

  color: var(--ink2);
  background: var(--card);
  border: 1px solid var(--line);
}

.screen__undo {
  flex-shrink: 0;
  padding: 4px 6px;
  margin: -4px -6px;

  font-size: var(--text-lg);
  font-weight: 700;
  color: var(--accent-ink);
}

.screen__state {
  margin-top: 28px;
  padding: 0 4px;
  text-align: center;

  font-size: var(--text-lg);
  color: var(--muted);
}

/* Underlined, because it is a link inside a sentence and the accent alone is
   not a strong enough signal on a line of muted body copy. */
.screen__link {
  font-weight: 600;
  color: var(--accent-ink);
  text-decoration: underline;
  text-underline-offset: 2px;
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

/*
 * Deal rules: set apart by space and a hairline, not another card — two competing card treatments
 * would leave the phone with no focal point.
 */
.rules {
  margin-top: 26px;
  padding-top: 18px;
  border-top: 1px solid var(--line2);
}

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
 * routes. The negative margin keeps the 44px tap target.
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
</style>
