<script setup>
/*
 * The route expander (design/README.md §5): three origin buttons, a destination
 * box that suggests as you type, and two things to do with what it holds.
 *
 * =============================================================================
 * LOOK BEFORE YOU WATCH — the second departure from the design, asked for by
 * the owner on 2026-08-15 for the same reason as the first one
 * =============================================================================
 * This form had exactly one action, and it was a COMMITMENT: the only way to
 * find out what Amsterdam to Palma costs was to start watching Amsterdam to
 * Palma, at which point it was a card on the list, in the globe's tour, and in
 * tomorrow morning's alerts — for a question somebody asked once. The list
 * filled up with routes nobody meant to keep, and the way to un-ask was a bin
 * and a confirmation.
 *
 * So the PRIMARY action is now "Look up", and it opens the route's own screen
 * without writing anything: the detail screen prices the pair on arrival if
 * Orbit has nothing recent for it (docs/API.md, `POST /api/routes/lookup`).
 * "Add route" is still here, still one tap, for somebody who already knows they
 * want it — it is simply no longer the toll on the way to a price.
 *
 * THE LOOK-UP DOES NOT TOUCH THE NETWORK FROM HERE. It is a navigation, and a
 * form that sat spinning for three seconds before the screen changed would be
 * the app freezing on the page you are leaving. The screen being opened is the
 * one that has to handle a route with no fares anyway — for a bookmark, a
 * shared link, or a lookup made a month ago — so putting the fetch there is one
 * path rather than two.
 *
 * WHAT THAT COSTS, stated plainly: a well-formed code Orbit has no airport for
 * ("ZZZ") is refused on the detail screen rather than in this form, because
 * only the server knows. The ADD path still answers here, beside the field,
 * exactly as it always did.
 *
 * THE TYPEAHEAD IS A DELIBERATE DEPARTURE FROM THE DESIGN, asked for by the
 * owner on 2026-08-15 after using the app. The handoff draws a bare three-
 * letter field, which assumes the person filling it in knows that Bilbao is
 * BIO and Palma is PMI — and the person this was built for does not, so the
 * screen's one write was unusable without a second tab open. The box still
 * TAKES a three-letter code and the server still validates exactly what it did
 * before; what is new is that typing "bilb" offers the answer.
 *
 * THE THREE ORIGINS ARE WRITTEN OUT HERE and the server validates against
 * `config('orbit.origins')`. That is one fact in two places, and it is the
 * deliberate choice: three strings that have not changed since the design was
 * drawn are not worth a request. If they ever diverge the server wins and says
 * so — "Orbit only tracks departures from AMS, EIN or DUS." is a message this
 * form displays verbatim — so the failure is visible rather than silent.
 *
 * THE DESTINATIONS ARE NOT WRITTEN OUT HERE, and the difference is the point:
 * there are a hundred and eighty-four of them, they carry a city and a country
 * each, and they come from a seeder that is edited. That IS worth an endpoint —
 * one, on the first open, cached for the page. See stores/destinations.js.
 *
 * =============================================================================
 * TWO LISTS IN ONE PANEL — world flights
 * =============================================================================
 * The box now finds any of the 3,270 airports Orbit can price, and it does it
 * without giving up the thing that made the typeahead worth having: a
 * suggestion on the keystroke rather than after a round trip.
 *
 *   - THE CURATED LIST IS STILL LOADED ONCE AND SEARCHED IN MEMORY. It answers
 *     first, instantly, and it goes at the top — those are the places with
 *     vibes and honest month-by-month warmth, and they are the only ones a rule
 *     can ever match (docs/BUSINESS-LOGIC.md §1).
 *   - EVERYWHERE ELSE ARRIVES 250 ms LATER from `GET /api/airports?q=`, under
 *     a quiet divider, deduped against the rows above it. See
 *     stores/airports.js for the debounce, the abort and the sequence guard.
 *
 * WHAT THE PANEL MUST NEVER DO IS FLICKER BETWEEN THE TWO. "No matching
 * destination." while a request for the answer is in flight is a lie the box
 * tells for a quarter of a second, and the did-you-mean guess underneath it is
 * a worse one — so both wait for the search to land.
 *
 * THE CLIENT-SIDE CHECK IS A COURTESY, NOT A GUARD. It catches "LIS " and
 * "lisbon" without a round trip; everything that matters — does the airport
 * exist, is the pair already watched — can only be answered by the server, and
 * this component renders whatever it says.
 *
 * ACCESSIBILITY: the ARIA 1.2 combobox pattern, and no more of it than this
 * box needs. The input keeps `role="combobox"` with `aria-expanded`,
 * `aria-controls` and `aria-autocomplete="list"`; the suggestions are a
 * `listbox` of `option`s; the highlighted row is named by
 * `aria-activedescendant` rather than being focused, so the caret never leaves
 * the box somebody is typing in. Arrow keys move, Enter takes, Escape closes.
 */
import { computed, nextTick, onMounted, ref, useTemplateRef, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { MAX_SUGGESTIONS, nearestDestination, searchDestinations, useDestinationsStore } from '@/stores/destinations'
import { mergeSuggestions, useAirportsStore } from '@/stores/airports'

const props = defineProps({
  /** The server's 422 message for the last attempt, if there was one. */
  error: { type: String, default: '' },

  /** An ADD is in flight. The look-up makes no request from here. */
  busy: { type: Boolean, default: false },
})

/**
 * `lookup` opens the route; `watch` puts it on the list. Both carry the same
 * `{ origin, destination }` and both are refused by the same two checks below —
 * the difference is entirely what the screen does with them.
 */
const emit = defineEmits(['lookup', 'watch'])

const ORIGINS = ['AMS', 'EIN', 'DUS']

/** What the box has to hold before it is a code the form can send. */
const CODE = /^[A-Z]{3}$/

const origin = ref(ORIGINS[0])
const destination = ref('')
const localError = ref('')

const store = useDestinationsStore()
const { destinations, status: listStatus } = storeToRefs(store)

const world = useAirportsStore()
const { results: worldAirports, status: worldStatus } = storeToRefs(world)

/** Whether the suggestions are showing, and which of them is highlighted. */
const open = ref(false)
const active = ref(-1)

const listbox = useTemplateRef('listbox')

/** The 184, ranked and highlighted in the browser. Instant. */
const curated = computed(() => searchDestinations(destinations.value, destination.value, MAX_SUGGESTIONS))

const suggestions = computed(() =>
  mergeSuggestions(curated.value, worldAirports.value, destination.value, MAX_SUGGESTIONS),
)

/** Where the divider goes, or -1 when the panel is one list. */
const worldStartsAt = computed(() => suggestions.value.findIndex((suggestion) => suggestion.world))

/*
 * WHAT WAS PROBABLY MEANT, when nothing was found.
 *
 * "barcelna" is one letter away from a place this app knows and produced a
 * flat "No matching destination." — a dead end reached by the most ordinary
 * mistake anybody makes on a phone keyboard. Guarded by `suggestions.length`
 * so it can never appear beside real results: it is what the panel says
 * INSTEAD of admitting defeat, not a ninth suggestion.
 *
 * AND NOT WHILE THE WORLD SEARCH IS STILL OUT. A guess offered in the quarter
 * second before the answer arrives is a guess about a question that is about to
 * be answered properly — and it would appear and vanish under the thumb.
 *
 * IT SEARCHES THE CURATED LIST ONLY, which is a deliberate floor rather than a
 * gap. Edit distance over 184 city names on a keystroke is free and happens
 * in memory; over 3,270 it is a query nobody has written, and "did you mean"
 * as a round trip is a suggestion that arrives after the correction.
 */
const didYouMean = computed(() =>
  suggestions.value.length === 0 && worldStatus.value !== 'searching'
    ? nearestDestination(destinations.value, destination.value)
    : null,
)

/*
 * The dropdown is not merely `open`: an empty box has nothing to suggest, and
 * a list that appeared the moment the field was focused would cover the button
 * before anybody had asked it anything.
 */
const showing = computed(() => open.value && destination.value.trim() !== '')

/**
 * What the panel says when it has nothing to offer, in the order the three
 * cases actually happen: still looking, cannot look, found nothing.
 *
 * The curated list failing is worth its own sentence because it is the one
 * that changes what the box can do — no suggestions at all, code only. A world
 * search that fails is not: the curated list is still there, still instant,
 * and "no matching destination" is what the person sees either way.
 */
const emptyText = computed(() => {
  if (worldStatus.value === 'searching') {
    return 'Searching…'
  }

  return listStatus.value === 'failed'
    ? 'Suggestions are unavailable — a three-letter code still works.'
    : 'No matching destination.'
})

const canSubmit = computed(() => CODE.test(destination.value) && !props.busy)

/*
 * A CODE ORBIT ACTUALLY KNOWS, which is not the same question as "three
 * letters" and the difference is a bug this had before the test caught it.
 * "por" is three letters and is somebody halfway through typing Porto; "bar"
 * is three letters and is Barcelona. Treating either as a finished code meant
 * Enter sent it and the server answered "Orbit does not know an airport with
 * that code" about a place three feet down the suggestion list.
 *
 * IT COUNTS THE WORLD SUGGESTIONS TOO, since world flights. "JFK" is a code
 * Orbit will happily price and is not in the curated list, so without this the
 * panel would offer JFK, Enter would "take" the suggestion the box already
 * held, and the form would need a second Enter to send it — the exact
 * double-press this computed exists to prevent, reintroduced for every airport
 * outside Europe.
 */
const isKnownCode = computed(() =>
  CODE.test(destination.value)
  && (destinations.value.some((place) => place.iata === destination.value)
    || suggestions.value.some((suggestion) => suggestion.iata === destination.value)),
)

/*
 * ONE FETCH, ON THE FIRST OPEN. The form is `v-if`d by the screen, so mounting
 * IS opening it — and the store answers the second, third and hundredth call
 * out of what it already has.
 */
onMounted(() => {
  store.load()
})

/*
 * Upper-cased and stripped of anything that is not part of a place's name AS
 * IT IS TYPED, rather than on submit. A destination box that shows `lis` and
 * sends `LIS` is a box that disagrees with the row it produces.
 *
 * WHAT SURVIVES THE STRIP CHANGED WHEN THE BOX STOPPED BEING THREE LETTERS
 * WIDE. It used to be `[^A-Z]` and a cut at three characters, which is right
 * for a code and wrong for everything somebody now types into it: it would
 * turn "las palmas" into "LASPALMAS" and "málaga" into "MLAGA", neither of
 * which matches anything. Letters in any alphabet, spaces and the punctuation
 * that appears in a city name stay; digits — which no airport, city or country
 * on the list has in its name — still do not.
 *
 * =============================================================================
 * WHY THIS IS `v-model` PLUS A WATCHER, AND NOT `:value` PLUS `@input`
 * =============================================================================
 * It was the hand-rolled pair, and the pair has a hole in it that only a
 * browser can see. `@input` normalised the event's value straight into the ref:
 * type "1L" and the ref went "" → "L", Vue re-rendered, the box showed "L" —
 * correct. Type "12" and the strip produces "", which is what the ref ALREADY
 * held. No change, no re-render, and the DOM kept the two digits the user
 * typed: a field showing `12`, an Add button disabled, and nothing on screen
 * saying why.
 *
 * `v-model` closes it because of the order it works in. Its own listener
 * assigns the RAW value first ("12"), which is always a change and therefore
 * always schedules a render; this watcher then normalises it back to "" before
 * that render runs (watchers are pre-flush). The render finds the model
 * unchanged from last time — but `v-model`'s directive force-writes
 * `el.value` from the model on every update precisely for this case, and the
 * digits disappear.
 *
 * jsdom cannot catch this: the model is right in every component test, and it
 * is the ELEMENT that is wrong. e2e/specs/watchlist.spec.js types into a real
 * box and reads it back.
 */
watch(destination, (typed) => {
  const cleaned = typed.toUpperCase().replace(/[^\p{L} ’'.-]/gu, '').slice(0, 40)

  if (cleaned !== typed) {
    destination.value = cleaned
  }
})

// A fresh attempt clears the client-side complaint; the server's own message
// is cleared by whoever owns the request.
watch(destination, () => {
  localError.value = ''
})

/*
 * ASK THE WORLD, on the NORMALISED value.
 *
 * This watcher runs after the one above it, so what reaches the store is what
 * the box will actually show — "MÁLAGA" rather than the "málaga1" that was
 * typed. The store debounces, so a watcher that fires per keystroke is one
 * request per word; it also cancels, so the answer to a query somebody has
 * typed past never reaches the panel. See stores/airports.js.
 *
 * IT WATCHES THE VALUE AND NOT THE TYPING, and that distinction cost a browser
 * test to learn. The obvious guard here is `if (open)` — "only search while
 * somebody is looking at the list" — and it is wrong, because it depends on
 * two DOM listeners for the SAME `input` event running in a particular order:
 * `@input="onType"` opens the panel, `v-model` writes the model, and a pre-flush
 * watcher runs after both. Type character by character and it works; put a
 * value in with ONE event — a paste, an autofill, Playwright's `fill()` — and
 * the watcher can run against an `open` that is still false, so the box holds
 * "NEW YORK" and never asks anybody about it. The suppression it was for lives
 * in `choose()` and `reset()` instead, where it is an explicit cancellation of
 * something this component itself started.
 */
watch(destination, (typed) => {
  world.search(typed)
})

/*
 * A NEW QUERY UN-HIGHLIGHTS EVERYTHING. Keeping index 2 highlighted while the
 * list underneath it changes is how somebody presses Enter and gets a city
 * they never saw.
 */
watch(suggestions, () => {
  active.value = -1
})

/**
 * Typed into, as opposed to written to.
 *
 * The list opens HERE and not in a watcher on the model, which is what keeps
 * `choose()` and `reset()` from re-opening the thing they just closed.
 */
function onType() {
  open.value = true
}

function choose(suggestion) {
  destination.value = suggestion.iata
  open.value = false
  active.value = -1

  /*
   * AND STOP THE SEARCH THIS FUNCTION JUST STARTED, on the next tick.
   *
   * Writing to the box triggers the watcher above exactly as typing does — it
   * cannot tell the difference and should not have to — so `choose()` queues a
   * search for the three-letter code it has just put in a panel it has just
   * closed. `nextTick` is what makes cancelling it reliable: the watcher is
   * pre-flush and has only STARTED a 250 ms debounce by then, so this lands
   * before any request is made. Calling `clear()` synchronously here would run
   * BEFORE the watcher and cancel nothing.
   */
  nextTick(() => world.clear())
}

/*
 * CLOSING WHEN FOCUS LEAVES THE FORM — not when it leaves the BOX, and the
 * difference is a defect the browser gate found rather than a preference.
 *
 * WHAT WAS WRONG. `@blur` on the input closed the list. The panel is in the
 * flow, so closing it moves everything below it up — and blur fires on
 * MOUSEDOWN. Press the Add button with the list open and the sequence is:
 * mousedown, blur, panel gone, button jumps ~50 px up, mouseup lands on empty
 * space, and no click event is ever produced. The button was unpressable
 * whenever there were suggestions on screen, on a phone exactly as much as in
 * Playwright, and nothing anywhere said so — the press simply did nothing.
 *
 * `focusout` with a containment check is the fix: focus moving to something
 * INSIDE the form (which is where the button and the origin buttons are)
 * leaves the list alone, so nothing reflows under the pointer, and the button's
 * own submit closes it half a millisecond later. Focus leaving the form for
 * good still closes it.
 */
function onFocusOut(event) {
  if (!event.currentTarget.contains(event.relatedTarget)) {
    open.value = false
  }
}

/**
 * Enter, and the one judgement in this component.
 *
 * A BOX THAT ALREADY HOLDS A CODE SUBMITS. Somebody who typed LIS and pressed
 * Enter has said what they want, and making them press it twice — once to
 * accept a suggestion that says LIS back to them, once to send it — is the
 * feature getting in the way of the people who never needed it. Anything else
 * takes the highlighted suggestion, or the first one if the keyboard has not
 * been near the list.
 *
 * @param {KeyboardEvent} event
 */
function onEnter(event) {
  if (!showing.value || isKnownCode.value) {
    // Left alone, so the browser submits the form as it always did.
    return
  }

  /*
   * NOTHING WAS FOUND, BUT SOMETHING WAS GUESSED. The panel is showing "Did
   * you mean Barcelona?" and Enter is what somebody answers a question with —
   * and it is the only way to reach that row from the keyboard, since the
   * arrows walk `suggestions` and this is not one of them.
   */
  if (suggestions.value.length === 0) {
    if (didYouMean.value === null) {
      return
    }

    event.preventDefault()
    choose(didYouMean.value)

    return
  }

  event.preventDefault()

  choose(suggestions.value[active.value === -1 ? 0 : active.value])
}

function move(step) {
  if (!showing.value) {
    open.value = true

    return
  }

  const count = suggestions.value.length

  if (count === 0) {
    return
  }

  /*
   * THE RING HAS ONE MORE STOP THAN THERE ARE ROWS, and that stop is "nothing
   * highlighted" — arrowing off the bottom of the list puts the box itself
   * back in hand rather than jumping to the top. `slot` is `active` shifted so
   * that -1 becomes 0 and the modulo can do the wrapping.
   */
  const slot = active.value + 1
  active.value = (slot + step + count + 1) % (count + 1) - 1

  scrollActiveIntoView()
}

/*
 * The highlighted row is never focused — `aria-activedescendant` names it
 * instead, so the caret stays in the box — which means the browser will not
 * scroll it into view either. Eight rows is taller than the list is allowed to
 * be, so this does it.
 */
function scrollActiveIntoView() {
  nextTick(() => {
    /*
     * BY ID, NOT BY CHILD INDEX. The panel is no longer a list of nothing but
     * options — the divider between the curated rows and the world ones is a
     * child too — so `children[active]` scrolls to the row above the
     * highlighted one from the divider onwards. The id is the same thing
     * `aria-activedescendant` names, which makes this the same lookup the
     * browser is doing.
     *
     * The optional CALL is for jsdom, which has no layout and therefore no
     * scrollIntoView — this is a nicety of a real viewport, not behaviour.
     */
    listbox.value
      ?.querySelector(`#add-destination-option-${active.value}`)
      ?.scrollIntoView?.({ block: 'nearest' })
  })
}

/**
 * Send the pair, whichever of the two buttons asked for it.
 *
 * ONE CHECK FOR BOTH, because both mean the same thing by "a route": three
 * letters, and two different airports. A look-up that accepted `BILB` would
 * navigate to a screen that could only apologise, which is the dead end this
 * form exists to prevent.
 *
 * @param {'lookup'|'watch'} intent
 */
function attempt(intent) {
  if (props.busy) {
    return
  }

  open.value = false

  if (!CODE.test(destination.value)) {
    localError.value = suggestions.value.length > 0
      ? 'Pick a destination from the list, or type its three-letter code.'
      : 'A destination is a three-letter airport code, like LIS.'

    return
  }

  if (destination.value === origin.value) {
    localError.value = 'A route needs two different airports.'

    return
  }

  emit(intent, { origin: origin.value, destination: destination.value })
}

/** Called by the parent once the route has actually landed. */
function reset() {
  destination.value = ''
  localError.value = ''
  open.value = false
  active.value = -1

  /* Same tick order as `choose()`, same reason. */
  nextTick(() => world.clear())
}

defineExpose({ reset })
</script>

<template>
  <!-- The form's own submit — the Enter key, and the primary button — is the
       LOOK-UP, which is what lookup-first means at the keyboard as well as
       under the thumb. -->
  <form class="add rise-in" novalidate @submit.prevent="attempt('lookup')" @focusout="onFocusOut">
    <p id="add-origin-label" class="add__label">From</p>
    <div class="add__origins" role="radiogroup" aria-labelledby="add-origin-label">
      <button
        v-for="option in ORIGINS"
        :key="option"
        type="button"
        role="radio"
        class="origin"
        :class="{ 'origin--on': option === origin }"
        :aria-checked="option === origin"
        @click="origin = option"
      >
        {{ option }}
      </button>
    </div>

    <label class="add__label" for="add-destination">To</label>

    <div class="add__field">
      <input
        id="add-destination"
        v-model="destination"
        class="add__input"
        type="text"
        role="combobox"
        inputmode="text"
        autocapitalize="characters"
        autocomplete="off"
        spellcheck="false"
        aria-autocomplete="list"
        aria-controls="add-destination-list"
        :aria-expanded="showing"
        :aria-activedescendant="active === -1 ? undefined : `add-destination-option-${active}`"
        placeholder="City or code — e.g. Lisbon"
        @input="onType"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.esc.prevent="open = false"
        @keydown.enter="onEnter"
      >

      <!--
        `v-show` RATHER THAN `v-if`, so the listbox `aria-controls` names is in
        the document even while it is empty. A control that points at an id
        that does not exist yet is one screen readers announce as broken.
      -->
      <ul
        v-show="showing"
        id="add-destination-list"
        ref="listbox"
        class="options"
        role="listbox"
        aria-label="Destination suggestions"
      >
        <!--
          `@mousedown.prevent` IS THE WHOLE FOCUS RACE, in one modifier. A tap
          on a suggestion would otherwise blur the input first, `@blur` would
          close the list, and the click would land on nothing — the classic
          dropdown that cannot be clicked, only arrowed to. Preventing the
          default on mousedown stops the browser moving focus at all, so the
          box keeps the caret and the click arrives where it was aimed.
        -->
        <template v-for="(suggestion, index) in suggestions" :key="suggestion.iata">
          <!--
            THE JOIN BETWEEN THE TWO TIERS, drawn once and only when both are
            on screen. A badge on every world row would be four badges saying
            the same thing; a header with nothing above it would be a label on
            a list with nothing to contrast against, which is why this is
            `worldStartsAt > 0` rather than `>= 0`.

            `role="presentation"` because it is not a choice — the listbox's
            options are the rows, and a separator that announced itself as one
            would be a suggestion a screen reader can land on and not take.
          -->
          <li v-if="index === worldStartsAt && worldStartsAt > 0" class="options__split" role="presentation">
            Everywhere else Orbit can price
          </li>

          <li
            :id="`add-destination-option-${index}`"
            class="option"
            :class="{ 'option--active': index === active }"
            role="option"
            :aria-selected="index === active"
            @mousedown.prevent
            @click="choose(suggestion)"
            @mousemove="active = index"
          >
            <span class="option__city">
              {{ suggestion.marks.city.before }}<b>{{ suggestion.marks.city.match }}</b>{{ suggestion.marks.city.after }}
            </span>
            <span class="option__code">
              {{ suggestion.marks.iata.before }}<b>{{ suggestion.marks.iata.match }}</b>{{ suggestion.marks.iata.after }}
            </span>
            <span class="option__country">
              {{ suggestion.marks.country.before }}<b>{{ suggestion.marks.country.match }}</b>{{ suggestion.marks.country.after }}
            </span>
          </li>
        </template>

        <!--
          THE TYPO'S WAY OUT, and it is a real `option` because it really is
          one: tapping it fills the box exactly as any other suggestion does.
          It is not reachable with the arrow keys — `move()` walks
          `suggestions`, which this deliberately is not — so Enter takes it
          instead (see `onEnter`), which is the one keystroke somebody who has
          just been told they mistyped is going to press.
        -->
        <li
          v-if="didYouMean"
          class="option option--guess"
          role="option"
          :aria-selected="false"
          @mousedown.prevent
          @click="choose(didYouMean)"
        >
          Did you mean <b>{{ didYouMean.city }}</b>?
          <span class="option__code">{{ didYouMean.iata }}</span>
        </li>

        <!--
          Not a `role="option"`: there is nothing here to choose.

          "SEARCHING…" IS NOT A SPINNER, it is the absence of a wrong answer.
          The curated list has already been searched and found nothing; the
          other 3,086 airports are being asked about right now, and the panel
          saying "No matching destination." in the meantime would be a verdict
          delivered before the evidence. It only ever shows for the length of
          one debounce plus one request.
        -->
        <li v-if="suggestions.length === 0 && !didYouMean" class="option option--empty">
          {{ emptyText }}
        </li>
      </ul>
    </div>

    <p v-if="localError || error" class="add__error" role="alert">{{ localError || error }}</p>

    <button class="add__submit" type="submit" :disabled="!canSubmit">Look up</button>

    <!--
      THE COMMITMENT, KEPT AND MADE QUIETER. It is the same write it always
      was and it is still one tap; what changed is that it is no longer the
      only way to find out what a route costs. Its label is unchanged on
      purpose — "Add route" is what the person who wants it is looking for.
    -->
    <button class="add__watch" type="button" :disabled="!canSubmit" @click="attempt('watch')">
      {{ busy ? 'Adding…' : 'Add route' }}
    </button>
  </form>
</template>

<style scoped>
.add {
  margin-top: 14px;
  padding: 16px;

  border: 1px solid var(--line);
  border-radius: 18px;
  background: var(--card);
  box-shadow: var(--shadow);
}

.add__label {
  display: block;

  font-size: var(--text-sm);
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--muted);
}

.add__label:not(:first-child) {
  margin-top: 14px;
}

.add__origins {
  display: flex;
  gap: 8px;
  margin-top: 9px;
}

.origin {
  flex: 1;
  height: 42px;
  border-radius: 11px;

  font-family: var(--font-display);
  font-size: 14px;
  font-weight: 700;

  color: var(--ink2);
  background: var(--card2);
  border: 1.5px solid var(--line);

  transition: background 0.18s ease, color 0.18s ease, border-color 0.18s ease;
}

.origin--on {
  color: var(--on-solid);
  background: var(--accent);
  border-color: var(--accent);
}

.add__input {
  width: 100%;
  height: 44px;
  margin-top: 9px;
  padding: 0 14px;

  border: 1.5px solid var(--line);
  border-radius: 11px;
  background: var(--card2);
  color: var(--ink);

  font-family: var(--font-display);
  font-size: var(--text-xl);
  font-weight: 600;
  letter-spacing: 0.05em;
}

.add__input:focus {
  outline: none;
  border-color: var(--accent);
}

/* --- The suggestions ------------------------------------------------------
   IN THE FLOW, NOT FLOATING OVER IT, which is the one place this departs from
   the dropdown every flight search draws. Orbit is a 430 px column and the
   form is an expander that is already growing and shrinking; a panel that
   floated would sit exactly on top of the Add button — so the tap that follows
   "I found it" would land on a suggestion instead of on the button, and the
   button would have to be tapped twice. Pushing it down instead costs a few
   hundred pixels while the list is open and nothing at all once a suggestion
   has been taken, because taking one closes the list.

   Capped and scrolled internally so that "a few hundred pixels" stays true
   with eight matches on screen.

   The panel is --panel and not --card: it sits ON the card, and two surfaces
   at the same lightness with a hairline between them is how a dropdown stops
   looking like a dropdown. */

.options {
  max-height: 244px;
  overflow-y: auto;
  margin-top: 7px;
  padding: 5px;

  list-style: none;
  border: 1px solid var(--line);
  border-radius: 13px;
  background: var(--panel);

  /* iOS keeps the momentum scroll inside the panel rather than dragging the
     screen behind it. */
  overscroll-behavior: contain;
}

.option {
  display: flex;
  align-items: baseline;
  gap: 7px;

  padding: 9px 10px;
  border-radius: 9px;

  font-size: var(--text-lg);
  color: var(--ink2);
  cursor: pointer;
}

/*
 * ONE HIGHLIGHT FOR THE MOUSE AND THE KEYBOARD, driven by `active` in both
 * cases (`@mousemove` sets it). A separate `:hover` rule would mean two rows
 * looking chosen at once the moment somebody arrows down with the pointer
 * still resting on the list.
 */
.option--active {
  color: var(--ink);
  background: var(--card2);
}

.option__city {
  flex: 1;
  min-width: 0;
  /* One line, and the city rather than the country is what gets clipped last. */
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* What was typed, inside what was found. The weight IS the highlight — a tint
   would be a fifth colour on a row that already has three. */
.option b {
  font-weight: 700;
  color: var(--ink);
}

.option__code {
  flex-shrink: 0;

  font-family: var(--font-display);
  font-size: var(--text-md);
  font-weight: 700;
  letter-spacing: 0.06em;
  color: var(--accent-ink);
}

/* The code is already bold and already the accent colour, so the match inside
   it has nothing left to gain and keeps the colour of the code it is part of. */
.option__code b {
  color: inherit;
}

.option__country {
  flex-shrink: 0;
  font-size: var(--text-md);
  color: var(--muted);
}

/* Not a choice, so it says so by not looking like one. */
.option--empty {
  color: var(--muted);
  cursor: default;
}

/* The line between the places Orbit has an opinion about and the places it can
   merely price. It is a caption rather than a row: smaller, quieter, uppercase
   like the form's own field labels, with the hairline doing the separating so
   the text does not have to shout to be a boundary. */
.options__split {
  margin: 6px 0 2px;
  padding: 9px 10px 3px;
  border-top: 1px solid var(--line);

  font-size: var(--text-sm);
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--muted);
}

/* A question rather than a result: quieter than a suggestion, and the city
   inside it is bold for the same reason the matched run is — the word that
   answers the question is the word to read. The code sits at the end, so the
   row lines up with the suggestions it stands in for. */
.option--guess {
  justify-content: space-between;
  color: var(--muted);
}

.option--guess b {
  color: var(--ink);
}

.add__error {
  margin-top: 12px;
  padding: 9px 11px;
  border-radius: var(--radius-chip);

  font-size: var(--text-lg);
  color: var(--warn-ink);
  background: var(--warn-bg);
}

.add__submit {
  width: 100%;
  height: 46px;
  margin-top: 14px;
  border-radius: 12px;

  /* The design's own inverted button: ink on the page, page colour on the
     button. It is the only one on this screen, so it does not compete with the
     accent + in the header. */
  background: var(--ink);
  color: var(--bg);

  font-size: var(--text-xl);
  font-weight: 600;
}

/* The second action, and it looks like one: no fill, no border, the accent the
   rest of the app uses for "this is a thing you can tap". Full width and 40 px
   tall so it is still a thumb target on a phone — quieter than the button
   above it, not smaller than a finger. */
.add__watch {
  width: 100%;
  height: 40px;
  margin-top: 4px;

  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--accent-ink);
}

.add__submit:disabled,
.add__watch:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
</style>
