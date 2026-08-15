<script setup>
/*
 * The "Add route" expander (design/README.md §5): three origin buttons, a
 * destination box that suggests as you type, one button.
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
 * there are seventy-seven of them, they carry a city and a country each, and
 * they come from a seeder that is edited. That IS worth an endpoint — one, on
 * the first open, cached for the page. See stores/destinations.js.
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

const props = defineProps({
  /** The server's 422 message for the last attempt, if there was one. */
  error: { type: String, default: '' },

  busy: { type: Boolean, default: false },
})

const emit = defineEmits(['submit'])

const ORIGINS = ['AMS', 'EIN', 'DUS']

/** What the box has to hold before it is a code the form can send. */
const CODE = /^[A-Z]{3}$/

const origin = ref(ORIGINS[0])
const destination = ref('')
const localError = ref('')

const store = useDestinationsStore()
const { destinations, status: listStatus } = storeToRefs(store)

/** Whether the suggestions are showing, and which of them is highlighted. */
const open = ref(false)
const active = ref(-1)

const listbox = useTemplateRef('listbox')

const suggestions = computed(() => searchDestinations(destinations.value, destination.value, MAX_SUGGESTIONS))

/*
 * WHAT WAS PROBABLY MEANT, when nothing was found.
 *
 * "barcelna" is one letter away from a place this app knows and produced a
 * flat "No matching destination." — a dead end reached by the most ordinary
 * mistake anybody makes on a phone keyboard. Guarded by `suggestions.length`
 * so it can never appear beside real results: it is what the panel says
 * INSTEAD of admitting defeat, not a ninth suggestion.
 */
const didYouMean = computed(() =>
  suggestions.value.length === 0 ? nearestDestination(destinations.value, destination.value) : null,
)

/*
 * The dropdown is not merely `open`: an empty box has nothing to suggest, and
 * a list that appeared the moment the field was focused would cover the button
 * before anybody had asked it anything.
 */
const showing = computed(() => open.value && destination.value.trim() !== '')

const canSubmit = computed(() => CODE.test(destination.value) && !props.busy)

/*
 * A CODE ORBIT ACTUALLY KNOWS, which is not the same question as "three
 * letters" and the difference is a bug this had before the test caught it.
 * "por" is three letters and is somebody halfway through typing Porto; "bar"
 * is three letters and is Barcelona. Treating either as a finished code meant
 * Enter sent it and the server answered "Orbit does not know an airport with
 * that code" about a place three feet down the suggestion list.
 *
 * A code that is not on this list — one of the origins, or an airport with no
 * `destinations` row — is not "known" here and does not need to be: it falls
 * through to the empty-suggestions branch, which submits.
 */
const isKnownCode = computed(() =>
  CODE.test(destination.value) && destinations.value.some((place) => place.iata === destination.value),
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
    // The optional CALL is for jsdom, which has no layout and therefore no
    // scrollIntoView — this is a nicety of a real viewport, not behaviour.
    listbox.value?.children[active.value]?.scrollIntoView?.({ block: 'nearest' })
  })
}

function submit() {
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

  emit('submit', { origin: origin.value, destination: destination.value })
}

/** Called by the parent once the route has actually landed. */
function reset() {
  destination.value = ''
  localError.value = ''
  open.value = false
  active.value = -1
}

defineExpose({ reset })
</script>

<template>
  <form class="add rise-in" novalidate @submit.prevent="submit" @focusout="onFocusOut">
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
        <li
          v-for="(suggestion, index) in suggestions"
          :id="`add-destination-option-${index}`"
          :key="suggestion.iata"
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

        <!-- Not a `role="option"`: there is nothing here to choose. -->
        <li v-if="suggestions.length === 0 && !didYouMean" class="option option--empty">
          {{
            listStatus === 'failed'
              ? 'Suggestions are unavailable — a three-letter code still works.'
              : 'No matching destination.'
          }}
        </li>
      </ul>
    </div>

    <p v-if="localError || error" class="add__error" role="alert">{{ localError || error }}</p>

    <button class="add__submit" type="submit" :disabled="!canSubmit">
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

.add__submit:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
</style>
