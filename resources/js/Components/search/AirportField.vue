<script setup>
/*
 * One airport box: type a place, get the airport.
 *
 * =============================================================================
 * WHY THIS IS A COMPONENT AND NOT A SECOND COPY OF THE ADD FORM
 * =============================================================================
 * All of this — the two-tier suggestion panel, the did-you-mean, the combobox
 * keyboard, the strip watcher and the three focus traps underneath it — was
 * written once, inside Components/watch/AddRouteForm.vue, for the ONE box that
 * screen had. The search screen has two, and the second one is not a different
 * kind of box: "which airport is that" is the same question at either end of a
 * route. So the machinery moved here whole and the form above it kept only the
 * things that are about a PAIR — what the two codes are, whether they differ,
 * and which of the two buttons was pressed.
 *
 * The long-form arguments for every decision below were made in that file and
 * are reproduced here because this is where the code now lives. Nothing about
 * the behaviour changed in the move except what is marked TWO BOXES.
 *
 * =============================================================================
 * TWO LISTS IN ONE PANEL
 * =============================================================================
 * The box finds any of the 3,270 airports Orbit can price, and it does it
 * without giving up the thing that made the typeahead worth having: a
 * suggestion on the keystroke rather than after a round trip.
 *
 *   - THE CURATED LIST IS LOADED ONCE AND SEARCHED IN MEMORY. It answers
 *     first, instantly, and it goes at the top — those are the places with
 *     vibes and honest month-by-month warmth, and they are the only ones a rule
 *     can ever match (docs/BUSINESS-LOGIC.md §1).
 *   - EVERYWHERE ELSE ARRIVES 250 ms LATER from `GET /api/airports?q=`, under
 *     a quiet divider, deduped against the rows above it. See
 *     stores/airports.js for the debounce, the abort and the sequence guard.
 *
 * TWO BOXES — AND THEREFORE TWO SEARCHES. `useAirportSearch()` is a composable
 * rather than the pinia store it used to be, precisely so that this component
 * owns its own results: a singleton would have the From panel repainting itself
 * with whatever was typed into To. The CURATED list is still a shared store,
 * because 184 rows fetched once is what shared state is for.
 *
 * WHAT THE PANEL MUST NEVER DO IS FLICKER BETWEEN THE TWO. "No matching
 * airport." while a request for the answer is in flight is a lie the box tells
 * for a quarter of a second, and the did-you-mean guess underneath it is a
 * worse one — so both wait for the search to land.
 *
 * THE CLIENT-SIDE CHECK IS A COURTESY, NOT A GUARD. It catches "LIS " and
 * "lisbon" without a round trip; everything that matters — does the airport
 * exist, is the pair already watched — can only be answered by the server, and
 * the screen renders whatever it says.
 *
 * =============================================================================
 * `open` BELONGS TO THE FORM, WHICH IS THE ONE THING THAT DID CHANGE
 * =============================================================================
 * It was local state in the single-box form, closed by a `focusout` on the
 * <form> element with a containment check. That check is the fix for a real
 * defect and it cannot move in here with everything else:
 *
 *   THE PANEL IS IN THE FLOW, so closing it moves everything below it up — and
 *   blur/focusout fire on MOUSEDOWN. Press "Look up" with a panel open and the
 *   sequence is: mousedown, focusout, panel gone, button jumps ~250 px, mouseup
 *   lands on empty space, and no click event is ever produced. The button is
 *   unpressable whenever there are suggestions on screen, on a phone exactly as
 *   much as in Playwright, and nothing anywhere says so.
 *
 * A field can only ask "did focus leave ME", and the buttons are not in it — so
 * this component would close the panel under the pointer every time. The parent
 * owns the flag, asks "did focus leave the FORM", and gets one more thing for
 * free that two independent booleans would not give: only ever one panel open,
 * so the two lists can never be stacked on screen at once.
 *
 * ACCESSIBILITY: the ARIA 1.2 combobox pattern, and no more of it than this box
 * needs. The input keeps `role="combobox"` with `aria-expanded`,
 * `aria-controls` and `aria-autocomplete="list"`; the suggestions are a
 * `listbox` of `option`s; the highlighted row is named by
 * `aria-activedescendant` rather than being focused, so the caret never leaves
 * the box somebody is typing in. Arrow keys move, Enter takes, Escape closes.
 */
import { computed, nextTick, onMounted, ref, useTemplateRef, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { MAX_SUGGESTIONS, nearestDestination, searchDestinations, useDestinationsStore } from '@/stores/destinations'
import { IATA, mergeSuggestions, toCode, useAirportSearch } from '@/stores/airports'

const props = defineProps({
  /** Prefix for the input, the listbox and every option id. Unique per field. */
  id: { type: String, required: true },

  /** The word above the box — "From", "To". */
  label: { type: String, required: true },

  placeholder: { type: String, default: 'City or code' },

  /**
   * What a screen reader calls the BOX, when the word above it is not the whole
   * story. Empty by default, and the visible label is the name then.
   *
   * The From box needs it because the word above it is "From" and the thing
   * directly above THAT is three home-airport pills — so a screen reader
   * arriving at the input hears the label of a control it has already passed
   * and no hint that this one takes anywhere at all.
   */
  ariaLabel: { type: String, default: '' },

  /**
   * What the ✕ that empties the box is called, or '' for a box that has none.
   *
   * ONE PROP RATHER THAN TWO — a boolean AND a name — because a clear nobody
   * can name is a clear a screen reader announces as "button", and the two
   * would never be set apart anyway. It is off for the To box on purpose: an
   * empty To box means "nothing chosen yet" and clearing it buys nothing,
   * whereas an empty From box means "use the pills", which is a real answer.
   */
  clearLabel: { type: String, default: '' },

  /** What a screen reader calls the suggestion list. */
  listLabel: { type: String, required: true },

  /**
   * Whether this field's panel is the one showing. The form owns it — see the
   * note above.
   */
  open: { type: Boolean, default: false },

  /**
   * The other end of the pair, upper-cased, or ''. Never suggested: a route
   * from a place to itself is not a route.
   */
  exclude: { type: String, default: '' },
})

const value = defineModel({ type: String, required: true })

/**
 * `open` and `close` are requests to the form, which decides. `enter-on-empty`
 * is not raised at all — a box with nothing to take lets the keypress through
 * and the browser submits the form, exactly as it did before any of this.
 */
const emit = defineEmits(['open', 'close'])

const store = useDestinationsStore()
const { destinations, status: listStatus } = storeToRefs(store)

const world = useAirportSearch()
const { results: worldAirports, status: worldStatus } = world

/** Which suggestion is highlighted, or -1 for none. */
const active = ref(-1)

const listbox = useTemplateRef('listbox')

/** The 184, ranked and highlighted in the browser. Instant. */
const curated = computed(() => searchDestinations(destinations.value, value.value, MAX_SUGGESTIONS))

const suggestions = computed(() =>
  mergeSuggestions(curated.value, worldAirports.value, value.value, MAX_SUGGESTIONS, props.exclude),
)

/** Where the divider goes, or -1 when the panel is one list. */
const worldStartsAt = computed(() => suggestions.value.findIndex((suggestion) => suggestion.world))

/*
 * WHAT WAS PROBABLY MEANT, when nothing was found.
 *
 * "barcelna" is one letter away from a place this app knows and produced a
 * flat "No matching airport." — a dead end reached by the most ordinary mistake
 * anybody makes on a phone keyboard. Guarded by `suggestions.length` so it can
 * never appear beside real results: it is what the panel says INSTEAD of
 * admitting defeat, not a ninth suggestion.
 *
 * AND NOT WHILE THE WORLD SEARCH IS STILL OUT. A guess offered in the quarter
 * second before the answer arrives is a guess about a question that is about to
 * be answered properly — and it would appear and vanish under the thumb.
 *
 * IT SEARCHES THE CURATED LIST ONLY, which is a deliberate floor rather than a
 * gap. Edit distance over 184 city names on a keystroke is free and happens in
 * memory; over 3,270 it is a query nobody has written, and "did you mean" as a
 * round trip is a suggestion that arrives after the correction.
 *
 * IT CANNOT GUESS THE OTHER END OF THE PAIR EITHER. `nearestDestination` walks
 * the whole curated list, so with AMS in the From box a mistyped "amstedam" in
 * To would be answered "Did you mean Amsterdam?" — a suggestion this form is
 * about to refuse.
 */
const didYouMean = computed(() => {
  if (suggestions.value.length > 0 || worldStatus.value === 'searching') {
    return null
  }

  const guess = nearestDestination(destinations.value, value.value)

  return guess === null || guess.iata === props.exclude ? null : guess
})

/**
 * Somebody has put something in the box.
 *
 * TRIMMED, so a stray space is not "something". It is the same question the
 * panel and the ✕ both ask — one is "is there anything to suggest against",
 * the other "is there anything to clear" — and they must not be able to
 * disagree about a box holding one space.
 */
const filled = computed(() => value.value.trim() !== '')

/*
 * The dropdown is not merely `open`: an empty box has nothing to suggest, and a
 * list that appeared the moment the field was focused would cover the buttons
 * before anybody had asked it anything.
 */
const showing = computed(() => props.open && filled.value)

/**
 * What the panel says when it has nothing to offer, in the order the three
 * cases actually happen: still looking, cannot look, found nothing.
 *
 * The curated list failing is worth its own sentence because it is the one that
 * changes what the box can do — no suggestions at all, code only. A world
 * search that fails is not: the curated list is still there, still instant, and
 * "no matching airport" is what the person sees either way.
 */
const emptyText = computed(() => {
  if (worldStatus.value === 'searching') {
    return 'Searching…'
  }

  return listStatus.value === 'failed'
    ? 'Suggestions are unavailable — a three-letter code still works.'
    : 'No matching airport.'
})

/*
 * =============================================================================
 * WHAT THE BOX SHOWS, AND WHAT THE FORM SENDS — two different strings
 * =============================================================================
 * They used to be one, and the box shouted. Every keystroke was upper-cased into
 * the model, which is right for a three-letter code and wrong for everything the
 * box has taken since it grew a typeahead: typing "Lisbon" produced "LISBON",
 * typing "las palmas" produced "LAS PALMAS" (the UX pass, screenshot
 * 23-j3-type-lisbon-already-watched). A search field that rewrites a place name
 * in capitals reads as an error message about what was just typed.
 *
 * THERE IS NO MIDDLE SETTING, and that is worth writing down because the obvious
 * one does not work. "Upper-case only while it is three characters or fewer"
 * cannot un-do itself: by the time "LIS" becomes four characters the first three
 * are already capitals in the model and there is nothing left to recover the
 * original case from, so "Lisbon" comes out as "LISbon". The transform has to be
 * all or nothing, and nothing is the honest choice — the box is a search box,
 * and searching is case-insensitive at both ends (`fold()` in
 * stores/destinations.js, an ILIKE on the server).
 *
 * SO THE UPPER-CASING IS A BOUNDARY: `toCode()` in stores/airports.js, applied
 * by this component only where it has to decide whether the box holds a
 * finished code, and by the form where it builds the request. Somebody who
 * types "lis" still watches AMS-LIS; what changed is that the field stops
 * arguing with them while they do it. Taking a suggestion writes
 * `suggestion.iata`, which is already capitals.
 *
 * `autocapitalize` HAD TO GO WITH IT. It was "characters", which is the mobile
 * keyboard doing the same shouting one layer down where no watcher of ours could
 * see it — the model would receive "LISBON" already capitalised and this file
 * would be none the wiser.
 */
const code = computed(() => toCode(value.value))

/*
 * A CODE ORBIT ACTUALLY KNOWS, which is not the same question as "three
 * letters" and the difference is a bug this had before the test caught it.
 * "por" is three letters and is somebody halfway through typing Porto; "bar" is
 * three letters and is Barcelona. Treating either as a finished code meant Enter
 * sent it and the server answered "Orbit does not know an airport with that
 * code" about a place three feet down the suggestion list.
 *
 * IT COUNTS THE WORLD SUGGESTIONS TOO. "JFK" is a code Orbit will happily price
 * and is not in the curated list, so without this the panel would offer JFK,
 * Enter would "take" the suggestion the box already held, and the form would
 * need a second Enter to send it — the exact double-press this computed exists
 * to prevent, reintroduced for every airport outside Europe.
 */
const isKnownCode = computed(() =>
  IATA.test(code.value)
  && (destinations.value.some((place) => place.iata === code.value)
    || suggestions.value.some((suggestion) => suggestion.iata === code.value)),
)

/*
 * ONE FETCH, HOWEVER MANY BOXES. Both fields mount together and both call this;
 * the store answers the second, third and hundredth call out of what it already
 * has (stores/destinations.js holds the in-flight promise for exactly this).
 */
onMounted(() => {
  store.load()
})

/*
 * Stripped of anything that is not part of a place's name AS IT IS TYPED, rather
 * than on submit — so a character the form will not accept never sits in the box
 * looking accepted.
 *
 * IT DOES NOT UPPER-CASE, and the note on `code` above is the whole argument.
 *
 * WHAT SURVIVES THE STRIP is letters in any alphabet, spaces and the punctuation
 * that appears in a city name; digits — which no airport, city or country on the
 * list has in its name — do not. It used to be `[^A-Z]` and a cut at three
 * characters, which is right for a code and wrong for everything somebody now
 * types into it: it would turn "las palmas" into "LASPALMAS" and "málaga" into
 * "MLAGA", neither of which matches anything.
 *
 * =============================================================================
 * WHY THIS IS `v-model` PLUS A WATCHER, AND NOT `:value` PLUS `@input`
 * =============================================================================
 * It was the hand-rolled pair, and the pair has a hole in it that only a browser
 * can see. `@input` normalised the event's value straight into the ref: type
 * "1L" and the ref went "" → "L", Vue re-rendered, the box showed "L" —
 * correct. Type "12" and the strip produces "", which is what the ref ALREADY
 * held. No change, no re-render, and the DOM kept the two digits the user typed:
 * a field showing `12`, a disabled button, and nothing on screen saying why.
 *
 * `v-model` closes it because of the order it works in. Its own listener assigns
 * the RAW value first ("12"), which is always a change and therefore always
 * schedules a render; this watcher then normalises it back to "" before that
 * render runs (watchers are pre-flush). The render finds the model unchanged
 * from last time — but `v-model`'s directive force-writes `el.value` from the
 * model on every update precisely for this case, and the digits disappear.
 *
 * jsdom cannot catch this: the model is right in every component test, and it is
 * the ELEMENT that is wrong. e2e/specs/search.spec.js types into a real box and
 * reads it back.
 */
watch(value, (typed) => {
  const cleaned = typed.replace(/[^\p{L} ’'.-]/gu, '').slice(0, 40)

  if (cleaned !== typed) {
    value.value = cleaned
  }
})

/*
 * ASK THE WORLD, on the NORMALISED value.
 *
 * This watcher runs after the one above it, so what reaches the search is what
 * the box will actually show — "MÁLAGA" rather than the "málaga1" that was
 * typed. It debounces, so a watcher that fires per keystroke is one request per
 * word; it also cancels, so the answer to a query somebody has typed past never
 * reaches the panel. See stores/airports.js.
 *
 * IT WATCHES THE VALUE AND NOT THE TYPING, and that distinction cost a browser
 * test to learn. The obvious guard here is `if (open)` — "only search while
 * somebody is looking at the list" — and it is wrong, because it depends on two
 * DOM listeners for the SAME `input` event running in a particular order:
 * `@input="onType"` opens the panel, `v-model` writes the model, and a pre-flush
 * watcher runs after both. Type character by character and it works; put a value
 * in with ONE event — a paste, an autofill, Playwright's `fill()` — and the
 * watcher can run against an `open` that is still false, so the box holds "NEW
 * YORK" and never asks anybody about it. The suppression it was for lives in
 * `choose()`, where it is an explicit cancellation of something this component
 * itself started.
 */
watch(value, (typed) => {
  world.search(typed)
})

/*
 * A NEW QUERY UN-HIGHLIGHTS EVERYTHING. Keeping index 2 highlighted while the
 * list underneath it changes is how somebody presses Enter and gets a city they
 * never saw.
 */
watch(suggestions, () => {
  active.value = -1
})

/**
 * Typed into, as opposed to written to.
 *
 * The list opens HERE and not in a watcher on the model, which is what keeps
 * `choose()` from re-opening the thing it just closed — and what keeps the form
 * swapping the two fields' contents from opening a panel nobody asked for.
 */
function onType() {
  emit('open')
}

function choose(suggestion) {
  value.value = suggestion.iata
  active.value = -1
  emit('close')

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

/**
 * Enter, and the one judgement in this component.
 *
 * A BOX THAT ALREADY HOLDS A CODE SUBMITS. Somebody who typed LIS and pressed
 * Enter has said what they want, and making them press it twice — once to accept
 * a suggestion that says LIS back to them, once to send it — is the feature
 * getting in the way of the people who never needed it. Anything else takes the
 * highlighted suggestion, or the first one if the keyboard has not been near the
 * list. "Left alone" means the browser submits the form, which is the look-up.
 *
 * @param {KeyboardEvent} event
 */
function onEnter(event) {
  if (!showing.value || isKnownCode.value) {
    return
  }

  /*
   * NOTHING WAS FOUND, BUT SOMETHING WAS GUESSED. The panel is showing "Did you
   * mean Barcelona?" and Enter is what somebody answers a question with — and it
   * is the only way to reach that row from the keyboard, since the arrows walk
   * `suggestions` and this is not one of them.
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
    emit('open')

    return
  }

  const count = suggestions.value.length

  if (count === 0) {
    return
  }

  /*
   * THE RING HAS ONE MORE STOP THAN THERE ARE ROWS, and that stop is "nothing
   * highlighted" — arrowing off the bottom of the list puts the box itself back
   * in hand rather than jumping to the top. `slot` is `active` shifted so that
   * -1 becomes 0 and the modulo can do the wrapping.
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
     * BY ID, NOT BY CHILD INDEX. The panel is not a list of nothing but options
     * — the divider between the curated rows and the world ones is a child too —
     * so `children[active]` scrolls to the row above the highlighted one from
     * the divider onwards. The id is the same thing `aria-activedescendant`
     * names, which makes this the same lookup the browser is doing.
     *
     * The optional CALL is for jsdom, which has no layout and therefore no
     * scrollIntoView — this is a nicety of a real viewport, not behaviour.
     */
    listbox.value
      ?.querySelector(`#${props.id}-option-${active.value}`)
      ?.scrollIntoView?.({ block: 'nearest' })
  })
}

/**
 * Empty the box — the ✕ inside it, and the search screen's home pills.
 *
 * IT USED TO BE `take(iata)`, which put a CODE in the box for those same pills,
 * and it went through `choose()` for a documented reason: a parent writing
 * `from.value = 'AMS'` fires the watcher above exactly as typing does, so a
 * shut panel would still queue a request for "AMS". Nothing writes a code in
 * from outside any more — the pills are the origin now rather than a shortcut
 * to typing one (see Views/Search.vue) — so what is left to do from outside is
 * empty it.
 *
 * AND EMPTYING IT NEEDS NO CANCELLATION, which is the one thing that is
 * genuinely different rather than renamed. The watcher this write fires calls
 * `world.search('')`, and a query under `MIN_QUERY` cancels the debounce and
 * drops the rows instead of asking anybody anything (stores/airports.js). The
 * write IS the cancellation; a `nextTick(world.clear())` here would be the same
 * call a second time.
 */
function clear() {
  value.value = ''
  active.value = -1
  emit('close')
}

defineExpose({ clear })
</script>

<template>
  <div class="field">
    <label class="field__label" :for="id">{{ label }}</label>

    <slot name="quick" />

    <!--
      A LINE OF ITS OWN FOR THE INPUT AND THE ✕, because the ✕ is positioned
      against it. The suggestion panel below stays a SIBLING of this rather than
      going inside it: the panel is in the flow (see the note under the styles)
      and anything positioned against a box the panel is inside would be
      positioned against the panel's height too.
    -->
    <div class="field__box">
      <input
        :id="id"
        v-model="value"
        class="field__input"
        :class="{ 'field__input--clearable': clearLabel }"
        type="text"
        role="combobox"
        inputmode="text"
        autocapitalize="none"
        autocomplete="off"
        spellcheck="false"
        aria-autocomplete="list"
        :aria-label="ariaLabel || undefined"
        :aria-controls="`${id}-list`"
        :aria-expanded="showing"
        :aria-activedescendant="active === -1 ? undefined : `${id}-option-${active}`"
        :placeholder="placeholder"
        @input="onType"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.esc.prevent="emit('close')"
        @keydown.enter="onEnter"
      >

      <!--
        ONLY WHEN THERE IS SOMETHING TO CLEAR. A ✕ sitting on an empty box is a
        control that does nothing, and on this screen it would be doing nothing
        directly above a placeholder inviting somebody to type.

        `@mousedown.prevent` FOR THE SAME REASON THE SUGGESTIONS HAVE IT: the
        browser must not move focus, so the caret stays in the box and the next
        keystroke goes where somebody who just emptied a field expects it to.
      -->
      <button
        v-if="clearLabel && filled"
        type="button"
        class="field__clear"
        :aria-label="clearLabel"
        @mousedown.prevent
        @click="clear"
      >
        <!-- Stroked from the style block, like every other glyph in this app: a
             var() in a presentation attribute is not portable. -->
        <svg width="13" height="13" viewBox="0 0 14 14" fill="none" aria-hidden="true">
          <path d="M3.5 3.5l7 7M10.5 3.5l-7 7" stroke-width="1.6" stroke-linecap="round" />
        </svg>
      </button>
    </div>

    <!--
      `v-show` RATHER THAN `v-if`, so the listbox `aria-controls` names is in the
      document even while it is empty. A control that points at an id that does
      not exist yet is one screen readers announce as broken.
    -->
    <ul
      v-show="showing"
      :id="`${id}-list`"
      ref="listbox"
      class="options"
      role="listbox"
      :aria-label="listLabel"
    >
      <!--
        `@mousedown.prevent` IS THE WHOLE FOCUS RACE, in one modifier. A tap on a
        suggestion would otherwise blur the input first, the form's `focusout`
        would close the list, and the click would land on nothing — the classic
        dropdown that cannot be clicked, only arrowed to. Preventing the default
        on mousedown stops the browser moving focus at all, so the box keeps the
        caret and the click arrives where it was aimed.
      -->
      <template v-for="(suggestion, index) in suggestions" :key="suggestion.iata">
        <!--
          THE JOIN BETWEEN THE TWO TIERS, drawn once and only when both are on
          screen. A badge on every world row would be four badges saying the same
          thing; a header with nothing above it would be a label on a list with
          nothing to contrast against, which is why this is `worldStartsAt > 0`
          rather than `>= 0`.

          `role="presentation"` because it is not a choice — the listbox's
          options are the rows, and a separator that announced itself as one
          would be a suggestion a screen reader can land on and not take.
        -->
        <li v-if="index === worldStartsAt && worldStartsAt > 0" class="options__split" role="presentation">
          Everywhere else Orbit can price
        </li>

        <li
          :id="`${id}-option-${index}`"
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
        THE TYPO'S WAY OUT, and it is a real `option` because it really is one:
        tapping it fills the box exactly as any other suggestion does. It is not
        reachable with the arrow keys — `move()` walks `suggestions`, which this
        deliberately is not — so Enter takes it instead (see `onEnter`), which is
        the one keystroke somebody who has just been told they mistyped is going
        to press.
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

        "SEARCHING…" IS NOT A SPINNER, it is the absence of a wrong answer. The
        curated list has already been searched and found nothing; the other 3,086
        airports are being asked about right now, and the panel saying "No
        matching airport." in the meantime would be a verdict delivered before
        the evidence. It only ever shows for the length of one debounce plus one
        request.
      -->
      <li v-if="suggestions.length === 0 && !didYouMean" class="option option--empty">
        {{ emptyText }}
      </li>
    </ul>
  </div>
</template>

<style scoped>
.field__label {
  display: block;

  font-size: var(--text-sm);
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--muted);
}

/* What the ✕ is positioned against, and nothing else — the margin moves here
   from the input so that "the top of the box" and "the top of the input" are
   the same 44 px the ✕ has to centre itself in. */
.field__box {
  position: relative;
  margin-top: 9px;
}

.field__input {
  width: 100%;
  height: 44px;
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

.field__input:focus {
  outline: none;
  border-color: var(--accent);
}

/* Room at the end of the line for the ✕, so a long city name runs under the
   caret's own scroll rather than under the button. Reserved whether or not the
   ✕ is on screen: a field whose text width changed the moment it grew a clear
   would reflow what somebody is in the middle of typing. */
.field__input--clearable {
  padding-right: 42px;
}

/* ON the field rather than beside it, so the box is still one control — and
   quiet, because it is the smallest thing anybody does on this screen. 30 px
   square is the same target the update toast's dismiss uses; it stops short of
   the field's full 44 px height deliberately, so a tap aimed at the end of what
   was typed still lands in the text. */
.field__clear {
  position: absolute;
  top: 50%;
  right: 7px;
  transform: translateY(-50%);

  display: flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 50%;

  color: var(--muted);
}

.field__clear:hover {
  color: var(--ink);
}

.field__clear path {
  stroke: currentColor;
}

/* --- The suggestions ------------------------------------------------------
   IN THE FLOW, NOT FLOATING OVER IT, which is the one place this departs from
   the dropdown every flight search draws. Orbit is a 430 px column and the
   panel would otherwise sit exactly on top of the buttons under it — so the tap
   that follows "I found it" would land on a suggestion instead of on the
   button, and the button would have to be tapped twice. Pushing them down
   instead costs a few hundred pixels while the list is open and nothing at all
   once a suggestion has been taken, because taking one closes the list.

   Capped and scrolled internally so that "a few hundred pixels" stays true with
   eight matches on screen.

   The panel is --panel and not --card: it sits ON the card, and two surfaces at
   the same lightness with a hairline between them is how a dropdown stops
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
 * ONE HIGHLIGHT FOR THE MOUSE AND THE KEYBOARD, driven by `active` in both cases
 * (`@mousemove` sets it). A separate `:hover` rule would mean two rows looking
 * chosen at once the moment somebody arrows down with the pointer still resting
 * on the list.
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

/* The code is already bold and already the accent colour, so the match inside it
   has nothing left to gain and keeps the colour of the code it is part of. */
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
   like the field labels, with the hairline doing the separating so the text does
   not have to shout to be a boundary. */
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
   answers the question is the word to read. The code sits at the end, so the row
   lines up with the suggestions it stands in for. */
.option--guess {
  justify-content: space-between;
  color: var(--muted);
}

.option--guess b {
  color: var(--ink);
}
</style>
