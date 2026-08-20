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
    <button class="month-nav__button" :disabled="!canPrev" :aria-label="prevLabel" @click="$emit('prev')">
      <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
        <path d="M11 4l-5 5 5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </button>

    <button class="month-nav__button" :disabled="!canNext" :aria-label="nextLabel" @click="$emit('next')">
      <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
        <path d="M7 4l5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </button>
  </div>
</template>

<style scoped>
.month-nav {
  display: flex;
  gap: 6px;
}

.month-nav__button {
  display: flex;
  align-items: center;
  justify-content: center;

  width: 34px;
  height: 34px;
  border-radius: 50%;

  background: var(--card);
  border: 1px solid var(--line);
  color: var(--ink2);
}

/* Disabled rather than hidden: the edge of the poll window is information, and
   an arrow that vanishes moves the one beside it under the thumb that was
   about to tap it. */
.month-nav__button:disabled {
  color: var(--muted);
  opacity: 0.4;
  cursor: not-allowed;
}
</style>
