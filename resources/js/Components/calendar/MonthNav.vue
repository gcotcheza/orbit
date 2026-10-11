<script setup>
/*
 * Previous / next month, with no month label: the screen's subtitle already carries it. The
 * arrows say where they GO in their accessible names, and the bounds are the parent's.
 */
import { computed } from 'vue'
import { addMonths, monthLabel } from './month'

const props = defineProps({
  month: { type: String, required: true },
  canPrev: { type: Boolean, default: false },
  canNext: { type: Boolean, default: false },
})

defineEmits(['prev', 'next'])

const prevLabel = computed(() => `Go to ${monthLabel(addMonths(props.month, -1))}`)
const nextLabel = computed(() => `Go to ${monthLabel(addMonths(props.month, 1))}`)
</script>

<template>
  <div class="month-nav">
    <button class="month-nav__button hit" :disabled="!canPrev" :aria-label="prevLabel" @click="$emit('prev')">
      <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
        <path d="M11 4l-5 5 5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </button>

    <button class="month-nav__button hit" :disabled="!canNext" :aria-label="nextLabel" @click="$emit('next')">
      <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
        <path d="M7 4l5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </button>
  </div>
</template>

<style scoped>
.month-nav {
  --arrow: 34px;
  --arrow-gap: 6px;

  display: flex;
  gap: var(--arrow-gap);
}

.month-nav__button {
  display: flex;
  align-items: center;
  justify-content: center;

  width: var(--arrow);
  height: var(--arrow);
  border-radius: 50%;

  background: var(--card);
  border: 1px solid var(--line);
  color: var(--ink2);
}

/* Two finger-sized areas are wider than the pair, so each leans outward until the two meet. */
.month-nav__button:first-child {
  --hit-shift: calc((var(--arrow) + var(--arrow-gap) - var(--hit-min)) / 2);
}

.month-nav__button:last-child {
  --hit-shift: calc((var(--hit-min) - var(--arrow) - var(--arrow-gap)) / 2);
}

/* Disabled rather than hidden: the edge of the poll window is information, and a vanishing arrow
   moves its neighbour under the thumb. */
.month-nav__button:disabled {
  color: var(--muted);
  opacity: 0.4;
  cursor: not-allowed;
}
</style>
