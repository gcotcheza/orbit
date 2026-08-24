<script setup>
// The watch list (design/README.md §5): boarding passes plus rules. This screen only lists —
// route-finding moved to Search.vue — and keeps paused routes visible.
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue'
import { storeToRefs } from 'pinia'
import { RouterLink } from 'vue-router'
import DealRules from '@/Components/rules/DealRules.vue'
import RouteRows from '@/Components/RouteRows.vue'
import WatchRow from '@/Components/watch/WatchRow.vue'
import { scrollIntoView } from '@/lib/motion'
import { useLayout } from '@/lib/layout'
import { useRulesStore } from '@/stores/rules'
import { useWatchlistStore } from '@/stores/watchlist'

// 1024px and up the passes get a pane of their own beside the master list; below it this screen
// keeps its phone layout (docs/DESKTOP-LAYOUT-PLAN.md phase 2).
const { isDesktop } = useLayout()

const watchlist = useWatchlistStore()
const { routes, status, error: notice } = storeToRefs(watchlist)

// Rules use a second store: rules and routes are separate concepts that happen to share this
// screen. `DealRules` owns the section itself — only its count is read here.
const { rules: dealRules } = storeToRefs(useRulesStore())

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

/** The row the master pane points at. Falls back to the first, so a removal cannot empty the pane. */
const selectedCode = ref('')

const selected = computed(
  () => routes.value.find((one) => one.code === selectedCode.value)?.code ?? routes.value[0]?.code ?? null,
)

/* The chosen pass leads the pane, and it leads in the DOM: a CSS `order` would hand a keyboard the
   passes in one sequence and the eye another. The phone gets the list untouched. */
const passes = computed(() => {
  const lead = isDesktop.value ? routes.value.find((one) => one.code === selected.value) : undefined

  return lead === undefined ? routes.value : [lead, ...routes.value.filter((one) => one !== lead)]
})

// The "Rules · N" chip jumps to the rules section below; a count, not a link, and it renders only
// when there are rules. `$el` because the ref now names a component, not the <section> itself.
const jumpToRules = () => scrollIntoView(rulesSection.value?.$el, { block: 'start' })

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

onMounted(watchlist.refresh)

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

</script>

<template>
  <div class="screen" :class="{ 'screen--wide': isDesktop }">
    <div class="screen__master">
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
             finds out it is there. See `jumpToRules`. In the frame it is a column, in view. -->
        <button
          v-if="!isDesktop && dealRules.length > 0"
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

      <!-- `group`, not `tabs`: nothing swaps for the row you press — the pass it names moves to the
           head of the list that is already on screen. -->
      <RouteRows
        v-if="isDesktop && routes.length"
        :routes="routes"
        :active="selected"
        kind="group"
        label="Watched routes"
        @select="selectedCode = $event"
      />
    </div>

    <div class="screen__pane">
      <p v-if="notice" class="screen__notice" role="alert">{{ notice }}</p>
      <p v-if="undoError" class="screen__notice" role="alert">{{ undoError }}</p>

      <!-- `role="status"`, not `alert`: nothing went wrong, and an assertive announcement over a
           deliberate action would be the reader shouting about what the user just did. -->
      <p v-if="undo" class="screen__notice screen__notice--undo" role="status">
        Stopped watching {{ undo.label }}
        <button type="button" class="screen__undo" @click="undoRemove">Undo</button>
      </p>

      <div class="screen__body">
        <div class="screen__passes">
          <p v-if="status === 'loading'" class="screen__state">Loading your routes…</p>

          <div v-else-if="status === 'failed'" class="screen__state">
            <p>Could not load your watch list.</p>
            <button type="button" class="screen__retry" @click="watchlist.refresh()">Try again</button>
          </div>

          <!-- Empty-state copy names the Search tab and says "look one up", not "watch one" —
               matching the search screen's price-without-commitment first step. -->
          <p v-else-if="routes.length === 0" class="screen__state">
            No routes yet. <RouterLink class="screen__link" :to="{ name: 'search' }">Search</RouterLink> for one to look up
            its price — you can start watching it from there, and Orbit prices it every morning after that.
          </p>

          <div v-else class="screen__list">
            <WatchRow
              v-for="route in passes"
              :key="route.code"
              class="rise-in"
              :class="{ 'is-paused': !route.active, 'is-selected': isDesktop && route.code === selected }"
              :route="route"
              :busy="busyCodes.has(route.code)"
              @toggle="toggle(route, $event)"
              @remove="remove(route)"
            />
          </div>
        </div>

        <!-- Deliberately quieter than the routes above, and set apart by the hairline `.rules`
             carries here and nowhere else. -->
        <DealRules ref="rulesSection" />
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Same reason as the shell's own wrappers in app.css. */
.screen__body,
.screen__passes {
  display: contents;
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

/* Underlined, because it is a link inside a sentence and the accent alone is
   not a strong enough signal on a line of muted body copy. */
.screen__link {
  font-weight: 600;
  color: var(--accent-ink);
  text-decoration: underline;
  text-underline-offset: 2px;
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
  opacity: var(--dim-paused);
}

/*
 * Deal rules: set apart by space and a hairline, not another card — two competing card treatments
 * would leave the phone with no focal point. `DealRules` draws everything inside it; a child's
 * root carries its parent's scope, which is what lets this rule reach it.
 */
.rules {
  margin-top: 26px;
  padding-top: 18px;
  border-top: 1px solid var(--line2);
}

/* --- 1024px and up: what only this screen does with the frame -----
   The frame is app.css's; the query matches lib/layout.js (docs/BUSINESS-LOGIC.md §36). */

@media (min-width: 1024px) and (min-height: 600px) {
  .screen--wide .screen__chips {
    margin: 0;
  }

  /* This pane's own padding — the one place a screen overrides the shared frame. */
  .screen--wide .screen__pane {
    padding: 22px 24px 24px;
  }

  /* WRAPS, and it has to: two shrinking columns squeeze a pass to ~170px, and a pass clips its
     own IATA codes rather than scrolling — which no overflow guard would catch. */
  .screen--wide .screen__body {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 24px;
  }

  .screen--wide .screen__passes {
    flex: 0 1 540px;
    min-width: 0;
    display: block;
  }

  /* The chosen pass at the full width of the column, the rest as many abreast as fit. */
  .screen--wide .screen__list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 14px;
    margin-top: 0;
  }

  .screen--wide .is-selected {
    grid-column: 1 / -1;
  }

  /* Its own column, so the hairline that set it apart from the routes above has nothing to do. */
  .screen--wide .rules {
    flex: 1 1 220px;
    min-width: 0;
    margin-top: 0;
    padding-top: 0;
    border-top: 0;
  }
}
</style>
