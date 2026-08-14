<script setup>
/*
 * The gradient bar under the grid, labelled with the month's own bounds
 * (design/README.md §3: "€38 ↔ €116").
 *
 * The bar is built from HEAT_STOPS rather than from a gradient written out
 * here, so the key and the cells it explains cannot drift apart.
 */
import { euro } from './format'
import { heatGradient } from './heat'

defineProps({
  min: { type: Number, required: true },
  max: { type: Number, required: true },
})

const gradient = heatGradient()
</script>

<template>
  <div class="legend">
    <span class="legend__bound tabular">{{ euro(min) }}</span>
    <div class="legend__bar" :style="{ background: gradient }" aria-hidden="true"></div>
    <span class="legend__bound tabular">{{ euro(max) }}</span>
  </div>
</template>

<style scoped>
.legend {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 9px;
  margin: 16px 4px 0;
}

.legend__bound {
  font-size: 11.5px;
  font-weight: 500;
  color: var(--muted);
}

.legend__bar {
  width: 130px;
  height: 9px;
  border-radius: 5px;
}
</style>
