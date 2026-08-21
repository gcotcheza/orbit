<script setup>
/*
 * A fortnight of prices, 72×24. Scales to whatever arrives and renders NOTHING when there is
 * nothing to draw. NO Y-AXIS, NO ZERO: the job is the SHAPE of the fortnight.
 */
import { computed } from 'vue'

const props = defineProps({
  /** Prices in euros, oldest first. */
  values: { type: Array, required: true },
  tone: { type: String, default: 'normal' },
})

// The design's box (design/README.md §1) is drawn at 72×24 from a 64×22 viewBox with
// preserveAspectRatio="none", which is what makes a 2px line read at this size.
const VIEW_WIDTH = 64
const VIEW_HEIGHT = 22
// Room for the stroke's own width at the extremes, so a fortnight's low does not get its bottom
// half clipped by the viewBox.
const PADDING = 3

const points = computed(() => {
  const values = props.values

  if (values.length === 0) {
    return null
  }

  const middle = VIEW_HEIGHT / 2

  if (values.length === 1) {
    // One observation is not a trend. A flat line through the middle says "here is a price" without
    // inventing a direction for it.
    return `0,${middle} ${VIEW_WIDTH},${middle}`
  }

  const low = Math.min(...values)
  const high = Math.max(...values)
  // A fortnight at exactly one price is a real answer (docs/API.md's fake provider produces them),
  // and it must not divide by zero.
  const span = high - low || 1

  return values
    .map((value, index) => {
      const x = (index / (values.length - 1)) * VIEW_WIDTH
      const y = VIEW_HEIGHT - PADDING - ((value - low) / span) * (VIEW_HEIGHT - 2 * PADDING)

      return `${x.toFixed(1)},${y.toFixed(1)}`
    })
    .join(' ')
})
</script>

<template>
  <svg
    v-if="points"
    class="spark"
    :data-tone="tone"
    width="72"
    height="24"
    :viewBox="`0 0 ${VIEW_WIDTH} ${VIEW_HEIGHT}`"
    preserveAspectRatio="none"
    aria-hidden="true"
  >
    <polyline :points="points" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
  </svg>
</template>

<style scoped>
.spark {
  /* The stroke would otherwise be clipped at the box's edge, where the first
     and last points sit. */
  overflow: visible;
}

.spark[data-tone='good'] {
  stroke: var(--good);
}

.spark[data-tone='info'] {
  stroke: var(--info);
}

.spark[data-tone='warn'] {
  stroke: var(--warn);
}

.spark[data-tone='normal'] {
  stroke: var(--muted);
}
</style>
