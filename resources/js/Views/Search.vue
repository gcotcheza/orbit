<script setup>
/*
 * Search — the centre tab. Any airport to any airport, the three home pills are presentation
 * only, and TEXT WINS WHILE THERE IS TEXT, PILLS WIN ON TAP (docs/BUSINESS-LOGIC.md §36).
 */
import { computed, onMounted, ref, useTemplateRef } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import DiscoveryCard from '@/Components/discover/DiscoveryCard.vue'
import AirportField from '@/Components/search/AirportField.vue'
import { IATA, toCode } from '@/stores/airports'
import { useDiscoveriesStore } from '@/stores/discoveries'
import { useWatchlistStore } from '@/stores/watchlist'

/**
 * The airports within a sensible drive — the one place in the client that still names them,
 * and presentation only now: the server takes any airport at either end.
 */
const HOME = ['AMS', 'EIN', 'DUS']

const router = useRouter()
const watchlist = useWatchlistStore()
const discoveries = useDiscoveriesStore()

/*
 * Asked for on mount and nothing waits on it; every visit, not once — the set turns over
 * daily at 05:20 and it is the cheapest read in the API.
 */
onMounted(() => discoveries.refresh())

/** The lit pill — the origin whenever the box below it is empty. */
const home = ref(HOME[0])

/** "Somewhere else", as typed, or ''. Never the lit pill's code. */
const from = ref('')

const to = ref('')

/** '' | 'from' | 'to' — at most one suggestion panel is ever showing. */
const openField = ref('')

const error = ref('')
const adding = ref(false)

/** `{ code, label }` of the route just added, or null. */
const added = ref(null)

const fromField = useTemplateRef('fromField')

/** Somebody has named somewhere else, so the pills are not the answer. */
const elsewhere = computed(() => from.value.trim() !== '')

/*
 * The boundary: what the boxes show is a place, what the API takes is a code — and which of
 * the two controls is speaking. There is one of these because there is one rule.
 */
const origin = computed(() => (elsewhere.value ? toCode(from.value) : home.value))
const destination = computed(() => toCode(to.value))

/**
 * Whether a pill is the one the screen will use. Not simply `code === home`: a lit pill beside
 * typed text would be two answers to one question, and `home` is not forgotten while dark.
 *
 * @param {string} code
 */
function lit(code) {
  return !elsewhere.value && code === home.value
}

/**
 * The discoveries this screen shows — six, in the server's order. Twelve rows is a storage
 * rule, not a display one, and the list is already sorted by what a kilometre costs.
 */
const finds = computed(() => discoveries.discoveries.slice(0, 6))

/**
 * "this morning", "yesterday" — how old the SEARCH is, which is not the per-card price age.
 * Date-only comparison in the viewer's zone: it is a claim about the calendar day.
 */
const foundLabel = computed(() => {
  const iso = discoveries.discoveredAt

  if (!iso) {
    return null
  }

  const found = new Date(iso)

  if (Number.isNaN(found.getTime())) {
    return null
  }

  const days = Math.round((startOfDay(new Date()) - startOfDay(found)) / 86_400_000)

  if (days <= 0) {
    return 'this morning'
  }

  return days === 1 ? 'yesterday' : `${days} days ago`
})

function startOfDay(date) {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime()
}

const canSubmit = computed(() => IATA.test(origin.value) && IATA.test(destination.value) && !adding.value)

/**
 * Light a home airport, and take the box out of the argument: a tap beats whatever is typed,
 * and emptying the box is what makes it mean that. Through the field, not at the model.
 */
function takeHome(code) {
  error.value = ''
  added.value = null
  home.value = code
  fromField.value?.clear()
}

/**
 * Focus left the form, so nothing is being chosen from. NOT on the fields and not `@blur`: a
 * panel that closes when focus moves to the button under it takes the button with it.
 */
function onFocusOut(event) {
  if (!event.currentTarget.contains(event.relatedTarget)) {
    openField.value = ''
  }
}

/**
 * Send the pair, whichever button asked. ONE CHECK FOR BOTH, because both mean the same thing
 * by "a route": two three-letter codes, and two different airports.
 *
 * @param {'lookup'|'watch'} intent
 */
function attempt(intent) {
  if (adding.value) {
    return
  }

  openField.value = ''
  added.value = null

  if (!IATA.test(origin.value)) {
    error.value = 'Pick where you are leaving from, or type its three-letter code.'

    return
  }

  if (!IATA.test(destination.value)) {
    error.value = 'Pick a destination from the list, or type its three-letter code.'

    return
  }

  if (origin.value === destination.value) {
    error.value = 'A route needs two different airports.'

    return
  }

  error.value = ''

  if (intent === 'lookup') {
    lookUp()

    return
  }

  add()
}

/**
 * Open the route without writing anything. The code is assembled here rather than asked for,
 * because `ORIGIN-DEST` is what a route code IS and this screen has both halves.
 */
function lookUp() {
  router.push({ name: 'route-detail', params: { id: `${origin.value}-${destination.value}` } })
}

/**
 * Start watching the pair. IT STAYS ON THIS SCREEN: a route added a second ago has no polls,
 * no history and no opinion, so its detail screen is the emptiest version of it.
 */
async function add() {
  adding.value = true

  try {
    const route = await watchlist.add(origin.value, destination.value)

    added.value = { code: route.code, label: `${origin.value}→${destination.value}` }
    to.value = ''
  } catch (failure) {
    error.value = messageFor(failure)
    console.error('Could not add a route.', failure)
  } finally {
    adding.value = false
  }
}

/**
 * The server's sentence, or one about the connection — answered here, under the fields that
 * were refused, which is why stores/watchlist.js `add` throws instead of writing its own.
 */
function messageFor(failure) {
  const response = failure.response

  if (!response) {
    return 'Could not reach Orbit. The route was not added.'
  }

  switch (response.status) {
    case 422:
      // The server's own sentences, per field — see App\Http\Requests\AddWatchedRouteRequest.
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
      <h1 class="screen__title">Search</h1>
      <p class="screen__note">Any airport to any airport. See what it costs before you commit to watching it.</p>
    </header>

    <!-- The form's own submit — Enter, and the primary button — is the LOOK-UP, which is "look
         before you watch" at the keyboard too. -->
    <form class="search rise-in" novalidate @submit.prevent="attempt('lookup')" @focusout="onFocusOut">
      <!-- THE PLACEHOLDER IS THE WHOLE AFFORDANCE: the box used to arrive holding "AMS", which is a
           read-out, not an invitation. -->
      <AirportField
        id="search-from"
        ref="fromField"
        v-model="from"
        label="From"
        aria-label="Origin — any airport"
        list-label="Origin suggestions"
        placeholder="Somewhere else? City or code…"
        clear-label="Clear the origin"
        :open="openField === 'from'"
        :exclude="destination"
        @open="openField = 'from'"
        @close="openField = ''"
      >
        <!-- IN THE FIELD RATHER THAN ABOVE IT, so pills and box read as one control. Buttons, not
             radios: these are three of three thousand. -->
        <template #quick>
          <div class="quick" role="group" aria-label="Home airports">
            <button
              v-for="code in HOME"
              :key="code"
              type="button"
              class="quick__chip"
              :class="{ 'quick__chip--on': lit(code) }"
              :aria-pressed="lit(code)"
              @click="takeHome(code)"
            >
              {{ code }}
            </button>
          </div>
        </template>
      </AirportField>

      <AirportField
        id="search-to"
        v-model="to"
        class="search__to"
        label="To"
        list-label="Destination suggestions"
        placeholder="City or code — e.g. Lisbon"
        :open="openField === 'to'"
        :exclude="origin"
        @open="openField = 'to'"
        @close="openField = ''"
      />

      <p v-if="error" class="search__error" role="alert">{{ error }}</p>

      <!-- `role="status"`: nothing went wrong, and an assertive announcement over a deliberate
           action is shouting about what the user just did. -->
      <p v-if="added" class="search__added" role="status">
        {{ added.label }} is on your watch list.
        <RouterLink class="search__added-link" :to="{ name: 'route-detail', params: { id: added.code } }">
          Open it
        </RouterLink>
      </p>

      <button class="search__submit" type="submit" :disabled="!canSubmit">Look up</button>

      <!-- THE COMMITMENT, KEPT AND MADE QUIETER: the same write it always was, and still one
           tap. -->
      <button class="search__watch" type="button" :disabled="!canSubmit" @click="attempt('watch')">
        {{ adding ? 'Adding…' : 'Add to watch' }}
      </button>
    </form>

    <!-- THE STRIP RENDERS ONLY WHEN THERE IS SOMETHING ON IT: no skeleton, no empty state, three
         deliberate omissions (docs/BUSINESS-LOGIC.md §36). -->
    <section v-if="finds.length" class="finds" aria-labelledby="finds-heading">
      <header class="finds__head">
        <h2 id="finds-heading" class="finds__title">Deals from your airports</h2>
        <p class="finds__note">
          Routes you are not watching. Orbit found these on its own<span v-if="foundLabel">, {{ foundLabel }}</span>.
        </p>
      </header>

      <ul class="finds__list">
        <li v-for="find in finds" :key="`${find.code}-${find.departureDate}`">
          <DiscoveryCard :discovery="find" />
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.screen {
  padding: 4px var(--gutter) 0;
}

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

.search {
  margin-top: 16px;
  padding: 16px;

  border: 1px solid var(--line);
  border-radius: 18px;
  background: var(--card);
  box-shadow: var(--shadow);
}

/* The two fields are the same thing twice, so the only difference between them
   is the air above the second one. */
.search__to {
  display: block;
  margin-top: 16px;
}

.quick {
  display: flex;
  gap: 8px;
  margin-top: 9px;
}

.quick__chip {
  flex: 1;
  height: 38px;
  border-radius: 11px;

  font-family: var(--font-display);
  font-size: 14px;
  font-weight: 700;

  color: var(--ink2);
  background: var(--card2);
  border: 1.5px solid var(--line);

  transition: background 0.18s ease, color 0.18s ease, border-color 0.18s ease;
}

.quick__chip--on {
  color: var(--on-solid);
  background: var(--accent);
  border-color: var(--accent);
}

.search__error {
  margin-top: 12px;
  padding: 9px 11px;
  border-radius: var(--radius-chip);

  font-size: var(--text-lg);
  color: var(--warn-ink);
  background: var(--warn-bg);
}

/* The app's quiet notice, not the warn tint: a route that was added on purpose
   is not a warning. Same treatment as the watch screen's undo line. */
.search__added {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;

  margin-top: 12px;
  padding: 10px 12px;
  border: 1px solid var(--line);
  border-radius: var(--radius-chip);

  font-size: var(--text-lg);
  color: var(--ink2);
}

.search__added-link {
  flex-shrink: 0;

  font-weight: 700;
  color: var(--accent-ink);
  text-decoration: none;
}

.search__submit {
  width: 100%;
  height: 46px;
  margin-top: 16px;
  border-radius: 12px;

  /* The design's own inverted button: ink on the page, page colour on the
     button. It is the only one on this screen. */
  background: var(--ink);
  color: var(--bg);

  font-size: var(--text-xl);
  font-weight: 600;
}

/* The second action, and it looks like one: no fill, no border, and 40px tall so it is still a
   thumb target. */
.search__watch {
  width: 100%;
  height: 40px;
  margin-top: 4px;

  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--accent-ink);
}

.search__submit:disabled,
.search__watch:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

/* =============================================================================
   Deals from your airports
   ============================================================================= */

.finds {
  /* Roomier than the gap between two cards inside the strip: this is a change
     of subject, not the next item. */
  margin-top: 26px;
  /* The tab bar floats over the bottom of every screen. */
  padding-bottom: 12px;
}

.finds__head {
  margin: 0 2px 10px;
}

.finds__title {
  font-family: var(--font-display);
  font-size: var(--text-xl);
  font-weight: 700;
  letter-spacing: -0.01em;
  color: var(--ink);
}

.finds__note {
  margin-top: 2px;
  font-size: var(--text-sm);
  color: var(--muted);
}

/* A real list, because it is one: a screen reader should hear "6 items" before
   deciding whether to walk them. */
.finds__list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  list-style: none;
}
</style>
