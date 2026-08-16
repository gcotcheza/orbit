<script setup>
/*
 * Search — the centre tab, and what this app turned out to be for.
 *
 * =============================================================================
 * WHY THIS SCREEN EXISTS, AND WHAT IT REPLACED
 * =============================================================================
 * The centre of the tab bar was a + that wrote a deal RULE. On the first day of
 * real use the owner made thirty-two look-ups and wrote zero rules — through a
 * form that was folded away behind a small + in the watch screen's header,
 * offered three origins, and was the second thing on the third screen. The most
 * used feature in the app was the hardest one to reach, and the least used one
 * had the biggest button.
 *
 * So the centre button is a magnifying glass now and this is what is behind it:
 * two boxes and two things to do with them. Rules did not go away — /create is
 * unchanged and is reached from the rules section of the watch screen, where
 * the rules themselves already live (Watchlist.vue).
 *
 * =============================================================================
 * ANY AIRPORT TO ANY AIRPORT
 * =============================================================================
 * The From box is new and it is the point. It takes any of the 3,270 airports
 * Orbit can price, exactly as the To box does, because
 * App\Http\Requests\RoutePairRequest stopped restricting the origin to the three
 * within a drive on the same day this screen was drawn — "what does Barcelona
 * to Palermo cost while I am already in Barcelona" is an ordinary question and
 * that rule was the only thing making it unaskable. See the note there, and the
 * one on `origins` in config/orbit.php for what stayed home-only (rules).
 *
 * THE THREE HOME AIRPORTS ARE STILL ONE TAP. They are quick chips above the
 * box, not a closed list: nine flights in ten leave from AMS, EIN or DUS, and a
 * screen that made the common case cost eight keystrokes to buy the rare one
 * would be a worse screen. They are written out here rather than fetched, which
 * is the same call the old form made about the same three strings — they have
 * not changed since the design was drawn, and they are now presentation rather
 * than validation, so there is nothing left for them to disagree with.
 *
 * =============================================================================
 * TWO ACTIONS, AND THE PRIMARY ONE IS THE QUESTION
 * =============================================================================
 * "Look up" opens the route's own screen without writing anything: the detail
 * screen prices the pair on arrival if Orbit has nothing recent for it
 * (docs/API.md, `POST /api/routes/lookup`). "Add to watch" is the commitment,
 * still one tap, for somebody who already knows they want it.
 *
 * THE LOOK-UP DOES NOT TOUCH THE NETWORK FROM HERE. It is a navigation, and a
 * screen that sat spinning for three seconds before it changed would be the app
 * freezing on the page you are leaving. The screen being opened is the one that
 * has to handle a route with no fares anyway — a bookmark, a shared link, a
 * lookup made a month ago — so putting the fetch there is one path rather than
 * two. What that costs, stated plainly: a well-formed code Orbit has no airport
 * for ("ZZZ") is refused on the detail screen rather than here. The ADD path
 * still answers below the fields, exactly as it always did.
 *
 * THE PANEL FLAG LIVES HERE AND NOT IN THE FIELDS, and it is not tidiness — see
 * the long note in Components/search/AirportField.vue. A field can only ask "did
 * focus leave me"; the buttons are not in it, so a field closing its own panel
 * on focusout would move the buttons out from under the pointer between
 * mousedown and mouseup and no click would ever be produced. Asking "did focus
 * leave the FORM" is the fix, and it can only be asked here.
 */
import { computed, ref, useTemplateRef } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import AirportField from '@/Components/search/AirportField.vue'
import { IATA, toCode } from '@/stores/airports'
import { useWatchlistStore } from '@/stores/watchlist'

/**
 * The airports within a sensible drive — `config('orbit.origins')`, and the one
 * place in the client that still names them. Presentation only now: the server
 * takes any airport at either end, so these three are a shortcut rather than a
 * list to be refused for straying from.
 */
const HOME = ['AMS', 'EIN', 'DUS']

const router = useRouter()
const watchlist = useWatchlistStore()

const from = ref(HOME[0])
const to = ref('')

/** '' | 'from' | 'to' — at most one suggestion panel is ever showing. */
const openField = ref('')

const error = ref('')
const adding = ref(false)

/** `{ code, label }` of the route just added, or null. */
const added = ref(null)

const fromField = useTemplateRef('fromField')

/* The boundary: what the boxes show is a place, what the API takes is a code. */
const origin = computed(() => toCode(from.value))
const destination = computed(() => toCode(to.value))

const canSubmit = computed(() => IATA.test(origin.value) && IATA.test(destination.value) && !adding.value)

/**
 * Put a home airport in the From box.
 *
 * THROUGH THE FIELD RATHER THAN AT THE MODEL, which is a one-word difference
 * with a request behind it. Writing `from.value = 'AMS'` fires the field's own
 * watcher, which asks `GET /api/airports?q=AMS` on a 250 ms debounce for a
 * panel that is shut — the same waste `choose()` exists to cancel when a
 * suggestion is taken. `take()` IS `choose()`, so a chip and a suggestion are
 * the same act.
 */
function takeHome(code) {
  error.value = ''
  added.value = null
  fromField.value?.take(code)
}

/**
 * Focus left the form, so nothing is being chosen from.
 *
 * NOT ON THE FIELDS, AND NOT `@blur`. The whole argument is in AirportField.vue
 * — in one line: the panel is in the flow, focusout fires on mousedown, and a
 * panel that closes when focus moves to the button under it takes the button
 * with it.
 */
function onFocusOut(event) {
  if (!event.currentTarget.contains(event.relatedTarget)) {
    openField.value = ''
  }
}

/**
 * Send the pair, whichever of the two buttons asked for it.
 *
 * ONE CHECK FOR BOTH, because both mean the same thing by "a route": two
 * three-letter codes, and two different airports. A look-up that accepted
 * `BARCELO` would navigate to a screen that could only apologise.
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
 * Open the route without writing anything.
 *
 * The code is assembled here rather than asked for, because `ORIGIN-DEST` is
 * what a route code IS (App\Models\Route::codeFor) and this screen has both
 * halves in hand.
 */
function lookUp() {
  router.push({ name: 'route-detail', params: { id: `${origin.value}-${destination.value}` } })
}

/**
 * Start watching the pair.
 *
 * IT STAYS ON THIS SCREEN. A route added a second ago has no polls, no history
 * and no opinion — `confident: false`, score 0, "tracking 0 days"
 * (docs/API.md's day-1 honesty) — so pushing somebody at its detail screen
 * would be showing them the emptiest version of the thing they just asked for.
 * A sentence, a link, and a box ready for the next question is the honest
 * answer, and the store has already put the row on the list either way.
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
 * The server's sentence, or one about the connection.
 *
 * A refused add is answered here, under the fields that were refused, rather
 * than in a banner somewhere else — which is why stores/watchlist.js `add`
 * throws instead of writing a sentence of its own.
 */
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
      <h1 class="screen__title">Search</h1>
      <p class="screen__note">Any airport to any airport. See what it costs before you commit to watching it.</p>
    </header>

    <!-- The form's own submit — the Enter key, and the primary button — is the
         LOOK-UP, which is what "look before you watch" means at the keyboard as
         well as under the thumb. -->
    <form class="search rise-in" novalidate @submit.prevent="attempt('lookup')" @focusout="onFocusOut">
      <AirportField
        id="search-from"
        ref="fromField"
        v-model="from"
        label="From"
        list-label="Origin suggestions"
        placeholder="City or code — e.g. Amsterdam"
        :open="openField === 'from'"
        :exclude="destination"
        @open="openField = 'from'"
        @close="openField = ''"
      >
        <!--
          IN THE FIELD RATHER THAN ABOVE IT, so the shortcut and the box it fills
          read as one control. They are buttons and not radios: a radiogroup says
          "these are the options", and since the search screen they are three of
          three thousand.
        -->
        <template #quick>
          <div class="quick" role="group" aria-label="Home airports">
            <button
              v-for="code in HOME"
              :key="code"
              type="button"
              class="quick__chip"
              :class="{ 'quick__chip--on': code === origin }"
              :aria-pressed="code === origin"
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

      <!-- `role="status"`: nothing went wrong, and an assertive announcement
           over a deliberate action is the screen reader shouting about a thing
           the user just did. -->
      <p v-if="added" class="search__added" role="status">
        {{ added.label }} is on your watch list.
        <RouterLink class="search__added-link" :to="{ name: 'route-detail', params: { id: added.code } }">
          Open it
        </RouterLink>
      </p>

      <button class="search__submit" type="submit" :disabled="!canSubmit">Look up</button>

      <!--
        THE COMMITMENT, KEPT AND MADE QUIETER. It is the same write it always
        was and it is still one tap; what changed is that it is no longer the
        only way to find out what a route costs.
      -->
      <button class="search__watch" type="button" :disabled="!canSubmit" @click="attempt('watch')">
        {{ adding ? 'Adding…' : 'Add to watch' }}
      </button>
    </form>
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

/* The second action, and it looks like one: no fill, no border, the accent the
   rest of the app uses for "this is a thing you can tap". Full width and 40 px
   tall so it is still a thumb target on a phone — quieter than the button above
   it, not smaller than a finger. */
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
</style>
