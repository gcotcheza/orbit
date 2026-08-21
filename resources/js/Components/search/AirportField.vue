<script setup>
/*
 * One airport box: type a place, get the airport. Two-tier suggestions (curated then world),
 * `open` owned by the parent form, ARIA 1.2 combobox (docs/BUSINESS-LOGIC.md §36).
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
   * What a screen reader calls the box, when the label above it isn't the whole story (e.g. From,
   * sitting under three home-airport pills). Empty by default (docs/BUSINESS-LOGIC.md §36).
   */
  ariaLabel: { type: String, default: '' },

  /**
   * What the ✕ that empties the box is called, or '' for a box that has none — one prop rather than
   * a boolean+name pair, since an unnamed clear announces as "button" (docs/BUSINESS-LOGIC.md §36).
   */
  clearLabel: { type: String, default: '' },

  /** What a screen reader calls the suggestion list. */
  listLabel: { type: String, required: true },

  /**
   * Whether this field's panel is the one showing. The form owns it — see the note above.
   */
  open: { type: Boolean, default: false },

  /**
   * The other end of the pair, upper-cased, or ''. Never suggested: a route from a place to itself
   * is not a route.
   */
  exclude: { type: String, default: '' },
})

const value = defineModel({ type: String, required: true })

/**
 * `open`/`close` are requests to the form, which decides; nothing is emitted on an empty Enter —
 * the keypress falls through to the browser's own submit (docs/BUSINESS-LOGIC.md §36).
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
 * The did-you-mean guess: curated-list edit distance (docs/BUSINESS-LOGIC.md §36).
 * Shown only when nothing matched and the world search is done; never for the excluded end.
 */
const didYouMean = computed(() => {
  if (suggestions.value.length > 0 || worldStatus.value === 'searching') {
    return null
  }

  const guess = nearestDestination(destinations.value, value.value)

  return guess === null || guess.iata === props.exclude ? null : guess
})

/**
 * Whether the box holds something — trimmed, so a stray space doesn't count for either the
 * suggestion panel or the ✕ (docs/BUSINESS-LOGIC.md §36).
 */
const filled = computed(() => value.value.trim() !== '')

/*
 * Not merely `open`: an empty box has nothing to suggest, and showing on focus alone would cover
 * the buttons before anything was asked (docs/BUSINESS-LOGIC.md §36).
 */
const showing = computed(() => props.open && filled.value)

/**
 * What the panel says when empty, in priority order: still searching, curated list failed
 * (code-only mode), or genuinely nothing found (docs/BUSINESS-LOGIC.md §36).
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
 * The box shows what was typed; `code` (toCode()) is the upper-cased form the form actually submits
 * — the box no longer shouts back every keystroke (docs/BUSINESS-LOGIC.md §36).
 */
const code = computed(() => toCode(value.value))

/*
 * A code Orbit actually knows, not just three letters — checked against curated destinations
 * AND world suggestions, so Enter never submits a half-typed "por" (docs/BUSINESS-LOGIC.md §36).
 */
const isKnownCode = computed(() =>
  IATA.test(code.value)
  && (destinations.value.some((place) => place.iata === code.value)
    || suggestions.value.some((suggestion) => suggestion.iata === code.value)),
)

/*
 * One fetch, however many boxes: the store dedupes concurrent load() calls via its in-flight
 * promise (docs/BUSINESS-LOGIC.md §36).
 */
onMounted(() => {
  store.load()
})

/*
 * Digits are stripped as typed (no place name has one), via v-model + a pre-flush watcher:
 * a hand-rolled binding can leave rejected digits stuck in the DOM (docs/BUSINESS-LOGIC.md §36).
 */
watch(value, (typed) => {
  const cleaned = typed.replace(/[^\p{L} ’'.-]/gu, '').slice(0, 40)

  if (cleaned !== typed) {
    value.value = cleaned
  }
})

/*
 * Watches the normalised value, not typing events — an `if (open)` guard would miss paste and
 * autofill; cancellation lives in choose() instead (docs/BUSINESS-LOGIC.md §36).
 */
watch(value, (typed) => {
  world.search(typed)
})

/*
 * A new query un-highlights everything — keeping an index highlighted across a changing list is how
 * Enter picks a city nobody saw (docs/BUSINESS-LOGIC.md §36).
 */
watch(suggestions, () => {
  active.value = -1
})

/**
 * Fires only on typing, not on writes: the panel opens here rather than in a value watcher, so
 * `choose()` and the form's field-swap don't reopen it (docs/BUSINESS-LOGIC.md §36).
 */
function onType() {
  emit('open')
}

function choose(suggestion) {
  value.value = suggestion.iata
  active.value = -1
  emit('close')

  /*
   * Cancels, on nextTick, the search this write's own watcher just queued — a synchronous clear()
   * would run before the pre-flush watcher and cancel nothing (docs/BUSINESS-LOGIC.md §36).
   */
  nextTick(() => world.clear())
}

/**
 * A box that already holds a known code submits on Enter without a second press; anything else
 * takes the highlighted (or first) suggestion (docs/BUSINESS-LOGIC.md §36).
 *
 * @param {KeyboardEvent} event
 */
function onEnter(event) {
  if (!showing.value || isKnownCode.value) {
    return
  }

  /*
   * The only keyboard path to the did-you-mean guess: it isn't in `suggestions`, so arrow keys
   * never reach it — Enter answers the question instead (docs/BUSINESS-LOGIC.md §36).
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
   * The ring has one extra stop beyond the rows — "nothing highlighted" — so arrowing past the last
   * row returns focus to the box instead of wrapping to the top (docs/BUSINESS-LOGIC.md §36).
   */
  const slot = active.value + 1
  active.value = (slot + step + count + 1) % (count + 1) - 1

  scrollActiveIntoView()
}

/*
 * The highlighted row is never focused (aria-activedescendant names it instead), so the browser
 * won't auto-scroll it — this does that manually (docs/BUSINESS-LOGIC.md §36).
 */
function scrollActiveIntoView() {
  nextTick(() => {
    /*
     * Looked up by id, not child index — the divider is a child too, so `children[active]` would be
     * off by one past it; optional call guards jsdom, which has no scrollIntoView.
     */
    listbox.value
      ?.querySelector(`#${props.id}-option-${active.value}`)
      ?.scrollIntoView?.({ block: 'nearest' })
  })
}

/**
 * Empties the box for the ✕ and the search screen's home pills. No explicit cancellation:
 * writing '' drops the search watcher below MIN_QUERY on its own (docs/BUSINESS-LOGIC.md §36).
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

    <!-- Input and ✕ share their own line; the panel is a sibling, not a child, so
         positioning the ✕ isn't against the panel's height too (docs/BUSINESS-LOGIC.md §36). -->
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

      <!-- Shown only when there's something to clear; @mousedown.prevent keeps focus
           (and the caret) in the box, same as the suggestion rows (docs/BUSINESS-LOGIC.md §36). -->
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

    <!-- v-show, not v-if: the id aria-controls names must exist in the DOM even
         while empty, or it announces as broken (docs/BUSINESS-LOGIC.md §36). -->
    <ul
      v-show="showing"
      :id="`${id}-list`"
      ref="listbox"
      class="options"
      role="listbox"
      :aria-label="listLabel"
    >
      <!-- @mousedown.prevent is the whole focus race: without it, tap blurs the input and the
           click lands on nothing (docs/BUSINESS-LOGIC.md §36). -->
      <template v-for="(suggestion, index) in suggestions" :key="suggestion.iata">
        <!-- Drawn only when both tiers are present (worldStartsAt > 0, not >= 0);
             role="presentation", so it is not announced (docs/BUSINESS-LOGIC.md §36). -->
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

      <!-- A real option — tapping fills the box — but not arrow-reachable (move() walks
           suggestions, not this), so Enter takes it instead (docs/BUSINESS-LOGIC.md §36). -->
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

      <!-- Not role="option" — nothing to choose. "Searching…" is the absence of a wrong
           answer while the world search is still in flight (docs/BUSINESS-LOGIC.md §36). -->
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

/* What the ✕ is positioned against: the margin moved here from the input so both share the same
   44px top the ✕ centres in. */
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

/* Padding for the ✕ is reserved whether or not it is shown — a text width changing mid-type would
   reflow what is being typed. */
.field__input--clearable {
  padding-right: 42px;
}

/* On the field, not beside it, so it reads as one control; 30px stops short of the 44px so a tap
   near the text edge still lands in the text. */
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

/* In the flow, not floating — on a 430px column an overlaid panel would sit on the buttons beneath
   it. --panel keeps the hairline look. */

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
 * One highlight source (`active`) for mouse and keyboard — a separate :hover rule could show two
 * rows chosen at once.
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

/* The divider between curated ("opinion") and priceable-only places, styled as a quiet caption
   rather than a row. */
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

/* Styled as a question, not a result: quieter, with the city bold — the word that answers — and the
   code aligned to the ordinary rows. */
.option--guess {
  justify-content: space-between;
  color: var(--muted);
}

.option--guess b {
  color: var(--ink);
}
</style>
