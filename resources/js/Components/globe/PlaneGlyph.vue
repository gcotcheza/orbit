<script setup>
/*
 * The aeroplane over the globe. It never moves: the camera flies the arc and the plane sits at
 * the viewport centre, so only the heading changes (design/README.md §1).
 */
defineProps({
  /** Degrees clockwise from north — lib/geo.js's bearing(). */
  bearing: { type: Number, default: 0 },
})
</script>

<template>
  <svg
    class="plane"
    width="32"
    height="32"
    viewBox="0 0 24 24"
    aria-hidden="true"
    :style="{ transform: `rotate(${bearing.toFixed(1)}deg)` }"
  >
    <path d="M21 16v-2l-8-5V3.5a1.5 1.5 0 0 0-3 0V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z" />
  </svg>
</template>

<style scoped>
.plane {
  position: absolute;
  left: 50%;
  top: 50%;
  z-index: 5;
  margin: -16px 0 0 -16px;
  pointer-events: none;
  fill: var(--globe-plane);

  /* The glow the design asks for. --accent-ink IS that colour to within a few
     units in the dark theme, and in the light theme it darkens instead — which
     is what keeps a white plane visible over a bright Atlantic. A literal
     rgba() here would have been the one colour in this app that could not
     follow the theme. */
  filter: drop-shadow(0 0 7px var(--accent-ink));
}
</style>
