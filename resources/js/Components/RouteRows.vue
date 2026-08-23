<script setup>
/*
 * The master pane's route list, shared by the landing page, the calendar and the watch list. Only
 * the frame mounts it, so its rules need no media query (docs/DESKTOP-LAYOUT-PLAN.md phase 2).
 */
import { computed, ref, useTemplateRef } from 'vue'
import { euro } from '@/lib/format'

// Which row the arrows move to. Wrapping at both ends, and Home/End to the edges (WAI-ARIA
// "Tabs with Manual Activation" — Enter and Space are the button's own).
const STEPS = {
  ArrowUp: (index, last) => (index === 0 ? last : index - 1),
  ArrowLeft: (index, last) => (index === 0 ? last : index - 1),
  ArrowDown: (index, last) => (index === last ? 0 : index + 1),
  ArrowRight: (index, last) => (index === last ? 0 : index + 1),
  Home: () => 0,
  End: (index, last) => last,
}

const props = defineProps({
  /** Rows of `GET /api/watchlist`'s `data`, in the order they should be read. */
  routes: { type: Array, required: true },

  /** The code whose pane is on screen. */
  active: { type: String, default: null },

  /** What the list is of, for a screen reader arriving at it cold. */
  label: { type: String, required: true },

  /*
   * 'tabs' where picking a row swaps the pane beside it (the landing, the calendar); 'group' where
   * it only marks one of a list that is already on screen (the watch list).
   */
  kind: {
    type: String,
    default: 'tabs',
    validator: (value) => ['tabs', 'group'].includes(value),
  },
})

defineEmits(['select'])

const rows = useTemplateRef('rows')

/** The row focus last left, so tabbing back into the list returns to it rather than to the top. */
const roving = ref(null)

const activeIndex = computed(() => {
  const found = props.routes.findIndex((one) => one.code === props.active)

  return found === -1 ? 0 : found
})

/* Clamped: a shorter list would otherwise leave a stale index owning a tab stop no row has. */
const tabStop = computed(() =>
  Math.min(roving.value ?? activeIndex.value, Math.max(props.routes.length - 1, 0)),
)

/** One tab stop for the whole list, which is what makes the arrows the way through it. */
function tabindexFor(index) {
  if (props.kind !== 'tabs') {
    return null
  }

  return index === tabStop.value ? 0 : -1
}

function move(event, index) {
  const next = props.kind === 'tabs' ? STEPS[event.key]?.(index, props.routes.length - 1) : undefined

  if (next === undefined) {
    return
  }

  event.preventDefault()
  roving.value = next

  // By code, not by position: Vue does not promise the ref array is in the source array's order.
  rows.value?.find((row) => row.dataset.code === props.routes[next].code)?.focus()
}
</script>

<template>
  <div
    class="route-rows"
    :role="kind === 'tabs' ? 'tablist' : 'group'"
    :aria-label="label"
    :aria-orientation="kind === 'tabs' ? 'vertical' : null"
  >
    <button
      v-for="(one, index) in routes"
      :key="one.code"
      ref="rows"
      class="route-row"
      :class="{ 'route-row--active': one.code === active, 'route-row--paused': one.active === false }"
      :data-code="one.code"
      type="button"
      :role="kind === 'tabs' ? 'tab' : null"
      :tabindex="tabindexFor(index)"
      :aria-selected="kind === 'tabs' ? one.code === active : null"
      :aria-pressed="kind === 'tabs' ? null : one.code === active"
      @click="$emit('select', one.code)"
      @focus="roving = index"
      @keydown="move($event, index)"
    >
      <span class="route-row__dot" :data-tone="one.verdict.tone"></span>

      <span class="route-row__where">
        <span>{{ one.origin.iata }}→{{ one.destination.iata }}</span>
        <span class="route-row__city">{{ one.destination.city }}</span>
      </span>

      <span class="route-row__price tabular">{{ euro(one.price.current) ?? '—' }}</span>
    </button>
  </div>
</template>

<style scoped>
.route-rows {
  display: flex;
  flex-direction: column;
  gap: 9px;
}

.route-row {
  display: flex;
  align-items: center;
  gap: 10px;

  width: 100%;
  padding: 11px 13px;
  border: 1px solid var(--line);
  border-radius: var(--radius-chip);

  font-family: var(--font-display);
  font-size: var(--text-lg);
  font-weight: 600;
  text-align: left;
  color: var(--ink);

  background: var(--card);
  box-shadow: var(--shadow);
  transition: background 0.18s ease, color 0.18s ease, border-color 0.18s ease;
}

.route-row--active {
  border-color: var(--accent);
  background: var(--accent);
  /* White on accent in both themes, as the rail's active chip uses. */
  color: var(--on-solid);
  box-shadow: 0 6px 16px var(--accent-glow);
}

/* The same 0.58 the watch rows and the calendar's chips use, and still selectable. */
.route-row--paused {
  opacity: 0.58;
}

.route-row__dot {
  width: 7px;
  height: 7px;
  flex-shrink: 0;
  border-radius: 50%;
  background: var(--muted);
}

.route-row__dot[data-tone='good'] {
  background: var(--good);
}

.route-row__dot[data-tone='info'] {
  background: var(--info);
}

.route-row__dot[data-tone='warn'] {
  background: var(--warn);
}

.route-row--active .route-row__dot {
  background: var(--on-solid);
}

.route-row__where {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 1px;
}

/* Opacity, not --muted: a fixed grey is a stray colour on the accent-filled active row. */
.route-row__city {
  font-family: var(--font-body);
  font-size: var(--text-sm);
  font-weight: 500;
  opacity: 0.66;
}

.route-row__price {
  margin-left: auto;
  opacity: 0.78;
}

/* The dimming is a treatment for text on a card. White on --accent is already only 3.4:1, and
   0.66 of it measured 2.3 in the dark theme (docs/DESKTOP-LAYOUT-PLAN.md phase 4). */
.route-row--active .route-row__city,
.route-row--active .route-row__price {
  opacity: 1;
}
</style>
