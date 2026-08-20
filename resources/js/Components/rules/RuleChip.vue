<script setup>
/*
 * One thing Orbit understood, and the × that takes it back (design/README.md §4). THE EYEBROW
 * IS THE SERVER'S WORD, and only the × is clickable, labelled with which chip it removes.
 */
defineProps({
  /** One element of a parse's `chips`: { id, category, label }. */
  chip: { type: Object, required: true },

  /** Chips go inert while the parse that would replace them is in flight. */
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['remove'])
</script>

<template>
  <div class="chip">
    <span class="chip__text">
      <span class="chip__category">{{ chip.category }}</span>
      <span class="chip__label">{{ chip.label }}</span>
    </span>

    <button
      type="button"
      class="chip__remove"
      :disabled="disabled"
      :aria-label="`Remove ${chip.category} ${chip.label}`"
      @click="emit('remove', chip.id)"
    >
      <svg width="9" height="9" viewBox="0 0 9 9" fill="none" aria-hidden="true">
        <path d="M1 1l7 7M8 1l-7 7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
      </svg>
    </button>
  </div>
</template>

<style scoped>
.chip {
  display: flex;
  align-items: center;
  gap: 9px;

  padding: 8px 10px 8px 12px;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: var(--card);

  /* The design's chips arrive rather than appear — 0.35s, rising slightly. */
  animation: chip-in 0.35s both;
}

.chip__text {
  display: flex;
  flex-direction: column;
}

.chip__category {
  font-size: 9.5px;
  font-weight: 600;
  line-height: 1.3;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--muted);
}

.chip__label {
  font-family: var(--font-display);
  font-size: 13.5px;
  font-weight: 600;
  line-height: 1.2;
  color: var(--ink);
}

.chip__remove {
  display: flex;
  align-items: center;
  justify-content: center;

  width: 18px;
  height: 18px;
  flex-shrink: 0;

  border-radius: 50%;
  background: var(--chip);
  color: var(--muted);

  transition: color 0.15s ease, background 0.15s ease;
}

.chip__remove:hover:not(:disabled),
.chip__remove:focus-visible:not(:disabled) {
  color: var(--warn-ink);
  background: var(--warn-bg);
}

.chip__remove:disabled {
  opacity: 0.5;
  cursor: progress;
}

@keyframes chip-in {
  from {
    opacity: 0;
    transform: translateY(5px) scale(0.95);
  }

  to {
    opacity: 1;
    transform: none;
  }
}

/* The rise is decoration; somebody who asked for less motion gets none. */
@media (prefers-reduced-motion: reduce) {
  .chip {
    animation: none;
  }
}
</style>
