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

  /* --accent-ink IS the glow the design asks for, and it darkens in the light theme; a literal
     rgba() could not follow the theme. */
  filter: drop-shadow(0 0 7px var(--accent-ink));
}
</style>
