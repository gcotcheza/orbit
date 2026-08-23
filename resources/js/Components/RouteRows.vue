<script setup>
/*
 * The master pane's route list, shared by the landing page, the calendar and the watch list. Only
 * the frame mounts it, so its rules need no media query (docs/DESKTOP-LAYOUT-PLAN.md phase 2).
 */
import { euro } from '@/lib/format'

defineProps({
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
</script>

<template>
  <div class="route-rows" :role="kind === 'tabs' ? 'tablist' : 'group'" :aria-label="label">
    <button
      v-for="one in routes"
      :key="one.code"
      class="route-row"
      :class="{ 'route-row--active': one.code === active, 'route-row--paused': one.active === false }"
      :data-code="one.code"
      type="button"
      :role="kind === 'tabs' ? 'tab' : null"
      :aria-selected="kind === 'tabs' ? one.code === active : null"
      :aria-pressed="kind === 'tabs' ? null : one.code === active"
      @click="$emit('select', one.code)"
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
</style>
