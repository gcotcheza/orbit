<script setup>
/*
 * The watch list (design/README.md §5): a stack of boarding passes, one per
 * route, and the rules underneath them.
 *
 * =============================================================================
 * THE ADD FORM LEFT, AND WHAT IS HERE INSTEAD IS ONE LINE
 * =============================================================================
 * This screen used to carry the whole route-finding apparatus behind the + in
 * its header: three origin buttons, a typeahead over 3,270 airports, a
 * did-you-mean, "Look up" and "Add route". On 2026-08-16 that became its own
 * screen on the centre tab (Views/Search.vue), with an origin box instead of
 * three buttons — and keeping BOTH would have left this app with two flight
 * searches, one of them a strictly worse version of the other, forty pixels
 * apart in the same tab bar.
 *
 * SO THE MACHINERY MOVED WHOLE, into Components/search/AirportField.vue, and
 * what is left here is a link. This screen's job is the LIST: what is on it,
 * what is paused, what was just removed. Finding a route was never that job —
 * it was folded into this screen because there was nowhere else to put it.
 *
 * WHAT THAT ALSO FIXED, for free. There were two blue + buttons on this screen
 * doing different writes: this header's added a ROUTE, the tab bar's centre
 * wrote a RULE. Yesterday's fix was to label the tab one "Rule" so they could be
 * told apart. There is now exactly one + on the screen and it says "New rule"
 * next to the rules, so there is nothing left to tell apart.
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

/*
 * ===========================================================================
 * UNDO, BECAUSE REMOVE WAS SILENT AND FINAL
 * ===========================================================================
 * The row simply vanished. Nothing said it had gone, nothing named what had
 * gone, and the only way back was to remember the pair and type it in again —
 * on the screen where a mis-tap on a 26 px bin is the likeliest mistake there
 * is. The confirmation catches the mis-tap; it does nothing for the person who
 * meant to remove AMS-FAO and removed AMS-AGP.
 *
 * IT IS A REAL UNDO AND NOT A HELD REQUEST. The delete goes immediately —
 * deferring it would mean a list that disagrees with the server for six
 * seconds — and undo is the ordinary add write, which works because REMOVING A
 * ROUTE DOES NOT DELETE ITS HISTORY: the route, its observations and its fares
 * are Orbit's, and only the watchlist ROW is the owner's (docs/API.md). So a
 * route that comes back comes back with its sixty days of prices, its score
 * and its verdict, exactly as it left. That is a property of the schema rather
 * than of this button, and it is why an undo can be honest here.
 *
 * SIX SECONDS, then the offer goes quietly. A notice that stays forever
 * becomes furniture, and this one sits above the list it is about.
 */
const UNDO_MS = 6000

/** The route just removed: `{ label, origin, destination }`, or null. */
const undo = ref(null)
const undoError = ref('')

let undoTimer = null

/** Route codes with a write in flight, so their switch can go inert. */
const busyCodes = ref(new Set())

const rulesSection = useTemplateRef('rulesSection')

/*
 * =============================================================================
 * A WAY TO FIND THE RULES, WHICH ARE AT THE BOTTOM OF A SCREEN THAT SCROLLS
 * =============================================================================
 * Deal rules are the app's second feature and they live under the boarding
 * passes, which is the right order — the routes are what the owner chose, a rule
 * is a standing question. With seven routes on a phone that puts them roughly
 * two and a half screens down, behind seven near-identical cards, and the UX
 * pass simply never found them: the + tab writes a rule and then the rule
 * appears somewhere the person who wrote it has no reason to scroll to.
 *
 * A COUNT AND NOT A LINK, because the section is on this screen and not on
 * another one. "Rules · 2" says the feature exists, says how much of it is
 * yours, and gets you there; a tab or a route would be a second home for a list
 * that already has one.
 *
 * ONLY WHEN THERE ARE RULES — the count is the whole content of the chip, and
 * "Rules · 0" is a scroll to nothing. The SECTION, unlike this, is now always
 * drawn: since the centre tab became Search it is the only door to /create, and
 * a door that appears once you are already through it is not a door.
 */
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

/**
 * Stop watching a route, and offer the way back.
 *
 * The store is optimistic and answers for the write itself: a delete that
 * failed puts the row back where it was and leaves a sentence in `notice`.
 * There is nothing to undo in that case — the route never left — so the offer
 * is only made when the list really did change.
 */
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
 * Put it back. The same write the add form makes — see the note on UNDO_MS for
 * why that is enough to restore the route rather than merely re-create it.
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
</script>

<template>
  <div class="screen">
    <header class="screen__head">
      <h1 class="screen__title">Watch list</h1>
      <p class="screen__note">{{ countLine }}</p>
    </header>

    <div class="screen__chips">
      <!--
        WHERE THE ADD FORM WAS, AND A TENTH OF ITS HEIGHT. The route finding
        moved to the centre tab; this is the door from the list to it, on the
        screen somebody is standing on when they think of another place to
        check. A link and not a button: it is a navigation, and the browser's
        own affordances for one — middle click, long press, the status bar —
        are worth more than a matching border.
      -->
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

    <!-- `role="status"` and not `alert`: nothing went wrong, and an assertive
         announcement over a deliberate action is the screen reader shouting
         about a thing the user just did. -->
    <p v-if="undo" class="screen__notice screen__notice--undo" role="status">
      Stopped watching {{ undo.label }}
      <button type="button" class="screen__undo" @click="undoRemove">Undo</button>
    </p>

    <p v-if="status === 'loading'" class="screen__state">Loading your routes…</p>

    <div v-else-if="status === 'failed'" class="screen__state">
      <p>Could not load your watch list.</p>
      <button type="button" class="screen__retry" @click="watchlist.refresh()">Try again</button>
    </div>

    <!-- IT NAMES THE TAB, because that is where the finding happens now. The
         copy this replaced said "tap + at the top right", which was the fix for
         there being two blue + buttons on this screen doing different writes —
         a sentence that only made sense while both existed.

         "LOOK ONE UP" RATHER THAN "WATCH ONE", because that is what the search
         screen's first button does: a price without a commitment, and the
         watching is one tap further on if it turns out to be worth it. -->
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

    <!--
      DEAL RULES, below the routes and deliberately quieter than them
      (design/README.md §4). The boarding passes above are the routes the owner
      chose; a rule is a standing question that may or may not have found
      anything yet, so it gets a hairline row rather than a card.

      IT IS ALWAYS DRAWN NOW, and it used to be hidden when there were no rules.
      That was right while the centre tab wrote a rule — an empty heading would
      have been a promise of a feature that had a button of its own two inches
      below it. Since 2026-08-16 that seat belongs to Search, and this section is
      the only door to /create: a section that appears once you already have a
      rule is a door on the inside of a room.

      SO THE EMPTY STATE IS A SENTENCE AND A BUTTON, quiet enough not to compete
      with the boarding passes it sits under. It says what a rule IS, because
      "deal rule" is this app's own word for something nobody has met before.
    -->
    <section ref="rulesSection" class="rules">
      <div class="rules__head">
        <h2 class="rules__title">Deal rules</h2>

        <!--
          THE ONE + LEFT ON THIS SCREEN, and it says what it makes. There were
          two identical accent squares here a day ago doing entirely different
          writes; naming the noun beside the glyph is what a label is for.
        -->
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

/* The app's inactive-chip vocabulary — card on the page, a hairline, a pill —
   which is what the rest of Orbit uses for "a second thing you may tap". Two of
   them, in a row under the header, and they wrap on a narrow phone rather than
   shrinking: a 44 px target is not negotiable and the second chip is not always
   there anyway. */
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

/* The same box as the failure notice above it, in the app's own quiet colours:
   a route that was removed on purpose is not a warning, and the warn tint is
   how this screen says something went wrong. */
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

/* The accent, in text, not a filled square. This section is deliberately
   quieter than the boarding passes above it, and a solid accent button in its
   header would make the standing question louder than the routes — which is the
   inversion that put the rules two and a half screens down in the first place.
   The negative margin keeps the 44 px tap target without the padding pushing
   the heading off its line. */
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
