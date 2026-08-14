<script setup>
/*
 * One watched route, drawn as a boarding pass (design/README.md §5).
 *
 * The card is in two halves with a perforated tear line between them: the
 * itinerary above — flight number, status pill, both IATA codes with their
 * cities and a dashed flight path — and the stub below, with the fare, the
 * usual price, a barcode, the switch and the remove action.
 *
 * IT RENDERS A ROUTE THAT KNOWS NOTHING ABOUT ITSELF. A route added a minute
 * ago has `confident: false`, `price.current: null` and an empty sparkline
 * (docs/API.md, day-1 honesty), and that is the state this card is in for the
 * first morning of every route's life. Prices fall back to an em dash and the
 * stub says how long we have been watching instead of pretending to a fare.
 *
 * THE STATUS PILL IS INLINE AND SHOULD NOT STAY THAT WAY. `VerdictPill.vue`
 * is being written in a parallel branch for the globe and the detail screen;
 * this is the same pill in the same tone colours, scoped to this component to
 * avoid two branches creating one file. Fold it in on the DRY pass.
 */
import { computed, ref } from 'vue'
import ToggleSwitch from '@/Components/settings/ToggleSwitch.vue'
import { flagFor, flightNumberFor } from './boardingPass'

const props = defineProps({
  /** One element of `GET /api/watchlist`'s `data`. */
  route: { type: Object, required: true },

  /** True while a write for this row is in flight. */
  busy: { type: Boolean, default: false },
})

const emit = defineEmits(['toggle', 'remove'])

const confirming = ref(false)

const flightNumber = computed(() => flightNumberFor(props.route.code))
const flagStyle = computed(() => ({ background: flagFor(props.route.destination.countryCode) }))

/*
 * The one thing to switch colour on, per docs/API.md: `verdict.tone`, never
 * the label. Each tone is a token pair in tokens.css, so this maps to CSS
 * custom properties rather than to colours.
 */
const toneStyle = computed(() => ({
  '--tone-bg': `var(--${props.route.verdict.tone === 'normal' ? 'neu' : props.route.verdict.tone}-bg)`,
  '--tone-ink': `var(--${props.route.verdict.tone === 'normal' ? 'neu' : props.route.verdict.tone}-ink)`,
  '--tone-dot': props.route.verdict.tone === 'normal' ? 'var(--muted)' : `var(--${props.route.verdict.tone})`,
}))

/** €58, or an em dash — never €0. A missing price is not a free flight. */
function euros(amount) {
  return amount === null || amount === undefined ? '—' : `€${Math.round(amount)}`
}

/*
 * The design's "tracking N days" note. Shown while the route has less than a
 * fortnight of history, which is the threshold docs/API.md sets, and phrased
 * for the very first morning when there is nothing at all.
 */
const trackingNote = computed(() => {
  const days = props.route.trackingDays

  if (days === 0) {
    return 'Waiting for the first fare'
  }

  return days === 1 ? 'Tracking 1 day' : `Tracking ${days} days`
})

const showTrackingNote = computed(() => props.route.trackingDays < 14)

function askToRemove() {
  confirming.value = true
}

function confirmRemove() {
  confirming.value = false
  emit('remove')
}
</script>

<template>
  <article class="pass" :style="toneStyle">
    <div class="pass__top">
      <div class="pass__eyebrow">
        <svg width="15" height="15" viewBox="0 0 30 30" fill="none" aria-hidden="true">
          <path d="M7 19 Q15 6 23 11" stroke="var(--accent)" stroke-width="1.8" stroke-dasharray="2.4 2.4" fill="none" />
          <circle cx="7" cy="19" r="2.4" fill="var(--good)" />
          <path d="M22 9.5l2 1.4-1.1 2.2-.9-.6.3-1-1.1-.5z" fill="var(--accent)" />
        </svg>
        <span class="pass__flight">Flight watch · {{ flightNumber }}</span>
      </div>

      <span class="pill">
        <span class="pill__dot"></span>{{ route.verdict.short }}
      </span>
    </div>

    <div class="pass__itinerary">
      <div class="end">
        <p class="end__code">{{ route.origin.iata }}</p>
        <p class="end__city">{{ route.origin.city }}</p>
      </div>

      <div class="path" aria-hidden="true">
        <span class="path__line"></span>
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none">
          <path d="M2 13.5l20-7-7 16-3-7-7-2z" fill="var(--accent)" />
        </svg>
        <span class="path__line"></span>
      </div>

      <div class="end end--to">
        <p class="end__code">{{ route.destination.iata }}</p>
        <p class="end__city">
          <span class="flag" :style="flagStyle" aria-hidden="true"></span>
          {{ route.destination.city }}
        </p>
      </div>
    </div>

    <div class="pass__tear">
      <span class="pass__notch pass__notch--left"></span>
      <span class="pass__notch pass__notch--right"></span>
    </div>

    <div v-if="confirming" class="confirm">
      <p class="confirm__question">Stop watching {{ route.code }}?</p>
      <div class="confirm__actions">
        <button type="button" class="confirm__button" @click="confirming = false">Keep</button>
        <button type="button" class="confirm__button confirm__button--go" @click="confirmRemove">Remove</button>
      </div>
    </div>

    <div v-else class="pass__stub">
      <div class="stub__figure">
        <p class="stub__label">Fare</p>
        <p class="stub__price tabular">{{ euros(route.price.current) }}</p>
      </div>

      <div class="stub__figure">
        <p class="stub__label">Usual</p>
        <p class="stub__price stub__price--usual tabular">{{ euros(route.price.usual) }}</p>
      </div>

      <p v-if="showTrackingNote" class="stub__tracking">{{ trackingNote }}</p>
      <span v-else class="stub__barcode" aria-hidden="true"></span>

      <ToggleSwitch
        :model-value="route.active"
        :disabled="busy"
        :label="`Watch ${route.code}`"
        @update:model-value="emit('toggle', $event)"
      />

      <button type="button" class="stub__remove" :aria-label="`Stop watching ${route.code}`" @click="askToRemove">
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <path d="M3 4.5h10M6.5 4V3h3v1M5 4.5l.5 8h5l.5-8" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </button>
    </div>
  </article>
</template>

<style scoped>
.pass {
  position: relative;
  overflow: hidden;

  border: 1px solid var(--line);
  border-radius: 18px;
  background: var(--card);
  box-shadow: var(--shadow);
}

.pass__top {
  display: flex;
  align-items: center;
  justify-content: space-between;

  padding: 13px 16px 0;
}

.pass__eyebrow {
  display: flex;
  align-items: center;
  gap: 7px;
}

.pass__flight {
  font-family: var(--font-display);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--muted);
}

/* --- The verdict pill. Colours come entirely from the --tone-* properties
   set on the card, which come entirely from `verdict.tone`. ---------------- */

.pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;

  padding: 4px 10px;
  border-radius: var(--radius-pill);

  font-size: var(--text-sm);
  font-weight: 600;

  background: var(--tone-bg);
  color: var(--tone-ink);
}

.pill__dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  flex-shrink: 0;
  background: var(--tone-dot);
}

/* --- Itinerary ----------------------------------------------------------- */

.pass__itinerary {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 10px;

  padding: 13px 16px;
}

.end {
  min-width: 0;
}

.end--to {
  text-align: right;
}

.end__code {
  font-family: var(--font-display);
  font-size: var(--text-3xl);
  font-weight: 700;
  line-height: 1;
  letter-spacing: -0.01em;
  color: var(--ink);
}

.end__city {
  display: flex;
  align-items: center;
  gap: 6px;

  margin-top: 4px;
  font-size: var(--text-sm);
  color: var(--muted);
}

.end--to .end__city {
  justify-content: flex-end;
}

.flag {
  width: 22px;
  height: 15px;
  flex-shrink: 0;

  border: 1px solid rgba(0, 0, 0, 0.18);
  border-radius: 3px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
}

.path {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 0 2px 7px;
}

.path__line {
  flex: 1;
  height: 0;
  border-top: 1.5px dashed var(--line);
}

/* --- The tear line ------------------------------------------------------
   Two half-circles bitten out of the card's edges, drawn in the PAGE colour
   rather than in a transparent hole — the card sits on the app's gradient
   wash, and a real hole would show the wash's own colour at that point rather
   than the flat background the design draws. */

.pass__tear {
  position: relative;
  height: 0;
  border-top: 1.5px dashed var(--line);
}

.pass__notch {
  position: absolute;
  top: -9px;

  width: 18px;
  height: 18px;
  border-radius: 50%;

  background: var(--bg);
  border: 1px solid var(--line);
}

.pass__notch--left {
  left: -9px;
}

.pass__notch--right {
  right: -9px;
}

/* --- The stub ------------------------------------------------------------ */

.pass__stub {
  display: flex;
  align-items: center;
  gap: 14px;

  padding: 11px 16px 12px;
}

.stub__label {
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--muted);
}

.stub__price {
  margin-top: 2px;
  font-family: var(--font-display);
  font-size: 16px;
  font-weight: 700;
  color: var(--ink);
}

.stub__price--usual {
  font-weight: 600;
  color: var(--muted);
  text-decoration: line-through;
}

.stub__barcode {
  flex: 1;
  height: 26px;
  margin: 0 2px;
  border-radius: 2px;
  opacity: 0.5;

  background: repeating-linear-gradient(
    90deg,
    var(--ink) 0 1px,
    transparent 1px 3px,
    var(--ink) 3px 5px,
    transparent 5px 6px,
    var(--ink) 6px 7px,
    transparent 7px 10px
  );
}

/* Where the barcode would be, while the route has nothing to say yet. */
.stub__tracking {
  flex: 1;
  min-width: 0;
  text-align: center;

  font-size: var(--text-sm);
  color: var(--muted);
}

.stub__remove {
  display: flex;
  align-items: center;
  justify-content: center;

  width: 26px;
  height: 26px;
  flex-shrink: 0;

  color: var(--muted);
  transition: color 0.18s ease;
}

.stub__remove:hover {
  color: var(--warn);
}

/* --- Inline confirmation -------------------------------------------------
   In the stub's place, not in a browser confirm(): a native dialog is the one
   piece of UI this app cannot theme, cannot animate and cannot show inside the
   card the question is about. */

.confirm {
  display: flex;
  align-items: center;
  gap: 10px;

  padding: 11px 16px 12px;
  background: var(--warn-bg);
}

.confirm__question {
  flex: 1;
  min-width: 0;
  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--warn-ink);
}

.confirm__actions {
  display: flex;
  gap: 8px;
  flex-shrink: 0;
}

.confirm__button {
  padding: 7px 12px;
  border-radius: 10px;

  font-size: var(--text-md);
  font-weight: 600;
  color: var(--ink2);
  background: var(--card);
}

.confirm__button--go {
  color: #fff;
  background: var(--warn);
}
</style>
