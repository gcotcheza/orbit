<script setup>
/*
 * Orbit's opinion, in one pill. The API sends the sentence AND the tone, and the tone is the
 * ONLY thing this switches on. Two sizes, not two components (docs/API.md).
 */
defineProps({
  label: { type: String, required: true },
  tone: {
    type: String,
    required: true,
    // The four docs/API.md sends, listed inline because defineProps is compiled out of this
    // scope; an unknown tone gets no colour, which is worth a development warning.
    validator: (value) => ['good', 'info', 'normal', 'warn'].includes(value),
  },
  /** 'md' on the spotlight card, 'sm' on a watchlist row. */
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['sm', 'md'].includes(value),
  },
})
</script>

<template>
  <span class="pill" :data-tone="tone" :data-size="size">
    <span class="pill__dot"></span>
    {{ label }}
  </span>
</template>

<style scoped>
.pill {
  display: inline-flex;
  align-items: center;

  border-radius: var(--radius-pill);
  font-weight: 600;
}

/* The spotlight card, where the pill carries the whole verdict sentence. */
.pill[data-size='md'] {
  gap: 7px;
  padding: 5px 11px;

  font-size: var(--text-md);
  /* Long verdicts ("Cheap & still falling") sit beside a sparkline in a 352 px
     card. Wrapping is better than pushing the chart off the edge. */
  text-wrap: balance;
}

/* A watchlist row, where the pill shares the boarding pass's eyebrow with the
   flight number and carries `verdict.short` — one word, so nothing to balance. */
.pill[data-size='sm'] {
  gap: 6px;
  padding: 4px 10px;

  font-size: var(--text-sm);
}

.pill__dot {
  width: 6px;
  height: 6px;
  flex-shrink: 0;
  border-radius: 50%;
}

.pill[data-tone='good'] {
  background: var(--good-bg);
  color: var(--good-ink);
}

.pill[data-tone='good'] .pill__dot {
  background: var(--good);
}

.pill[data-tone='info'] {
  background: var(--info-bg);
  color: var(--info-ink);
}

.pill[data-tone='info'] .pill__dot {
  background: var(--info);
}

.pill[data-tone='warn'] {
  background: var(--warn-bg);
  color: var(--warn-ink);
}

.pill[data-tone='warn'] .pill__dot {
  background: var(--warn);
}

.pill[data-tone='normal'] {
  background: var(--neu-bg);
  color: var(--neu-ink);
}

.pill[data-tone='normal'] .pill__dot {
  background: var(--muted);
}
</style>
