<script setup>
/*
 * One watched route, drawn as a boarding pass (design/README.md §5). A route with no history
 * is day-1 honest, and the link stops at the tear line (docs/BUSINESS-LOGIC.md §36).
 */
import { computed, ref } from 'vue'
import ToggleSwitch from '@/Components/ToggleSwitch.vue'
import VerdictPill from '@/Components/VerdictPill.vue'
import { euro } from '@/lib/format'
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
 * `stubNote` puts "Paused" ahead of the tracking note, deliberately (docs/BUSINESS-LOGIC.md §36).
 */
const stubNote = computed(() => {
  if (!props.route.active) {
    return 'Paused'
  }

  const days = props.route.trackingDays

  if (days >= 14) {
    return null
  }

  if (days === 0) {
    return 'Waiting for the first fare'
  }

  return days === 1 ? 'Tracking 1 day' : `Tracking ${days} days`
})

function askToRemove() {
  confirming.value = true
}

function confirmRemove() {
  confirming.value = false
  emit('remove')
}
</script>

<template>
  <article class="pass">
    <!-- aria-label overrides this link's own text — the flight number is set
         dressing, not the accessible name (docs/BUSINESS-LOGIC.md §36). -->
    <RouterLink
      class="pass__open"
      :to="{ name: 'route-detail', params: { id: route.code } }"
      :aria-label="`Open ${route.code}`"
    >
      <div class="pass__top">
        <div class="pass__eyebrow">
          <svg width="15" height="15" viewBox="0 0 30 30" fill="none" aria-hidden="true">
            <path d="M7 19 Q15 6 23 11" stroke="var(--accent)" stroke-width="1.8" stroke-dasharray="2.4 2.4" fill="none" />
            <circle cx="7" cy="19" r="2.4" fill="var(--good)" />
            <path d="M22 9.5l2 1.4-1.1 2.2-.9-.6.3-1-1.1-.5z" fill="var(--accent)" />
          </svg>
          <span class="pass__flight">Flight watch · {{ flightNumber }}</span>
        </div>

        <VerdictPill :label="route.verdict.short" :tone="route.verdict.tone" size="sm" />
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

        <!-- Same chevron affordance as SpotlightCard.vue, sized to this row. -->
        <svg class="chevron" width="15" height="15" viewBox="0 0 18 18" fill="none" aria-hidden="true">
          <path d="M6 4l5 5-5 5" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </div>
    </RouterLink>

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
        <!-- An em dash, never €0: a missing price is not a free flight. -->
        <p class="stub__price tabular">{{ euro(route.price.current) ?? '—' }}</p>
      </div>

      <div class="stub__figure">
        <p class="stub__label">Usual</p>
        <p class="stub__price stub__price--usual tabular">{{ euro(route.price.usual) ?? '—' }}</p>
      </div>

      <p v-if="stubNote" class="stub__tracking" :class="{ 'stub__tracking--paused': !route.active }">
        {{ stubNote }}
      </p>
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

/* Resets only what a bare <a> adds (underline, colour) — the card supplies
   the look; focus ring is global (app.css). */
.pass__open {
  display: block;
  color: inherit;
  text-decoration: none;
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

/* --- 1024px and up: the passes are a grid of ~263px cards ---------------
   Both halves of the query are lib/layout.js's, and they must be edited together
   (docs/DESKTOP-LAYOUT-PLAN.md, docs/BUSINESS-LOGIC.md §36). */

@media (min-width: 1024px) and (min-height: 600px) {
  /* Set dressing, and it wrapped to two lines beside the verdict pill at the grid's card width.
     One line and an ellipsis, since the flight number is not the pass's accessible name. */
  .pass__eyebrow {
    min-width: 0;
  }

  .pass__flight {
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
  }
}

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

/* align-self: center, not the row's bottom alignment — a bottom-aligned
   chevron would hang off the block it's pointing out of. */
.chevron {
  align-self: center;
  flex-shrink: 0;
}

.chevron path {
  stroke: var(--muted);
}

/* Notches use var(--bg), not a transparent hole — the page has a gradient
   wash that a real hole would reveal instead of a flat background. */

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

/* Ink colour, not a warning tone — pausing is something the owner chose, not
   an alert, and the row is already dimmed 58% around it. */
.stub__tracking--paused {
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--ink2);
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

/* Inline confirmation, not browser confirm() — a native dialog is the one
   piece of UI this app can't theme or animate. */

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
  color: var(--on-solid);
  background: var(--warn);
}
</style>
