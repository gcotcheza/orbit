<script setup>
/*
 * Orbit's opinion, in one pill.
 *
 * The API sends the sentence AND the colour to say it in (`verdict.label`,
 * `verdict.tone` — docs/API.md), and the tone is the ONLY thing this switches
 * on. Deriving a colour from the label instead — matching on "falling", or on
 * "book" — is the kind of thing that works for four weeks and then turns a new
 * verdict grey, silently, on every screen at once.
 *
 * The mapping is CSS rather than a JavaScript lookup returning `var(--…)`
 * strings: four token pairs, one attribute selector each, and the component
 * ships no colour logic at all.
 *
 * Used by the globe home's spotlight card (design/README.md §1) and, with
 * `verdict.short`, by the watchlist rows (§5).
 */
defineProps({
  label: { type: String, required: true },
  tone: {
    type: String,
    required: true,
    // The four docs/API.md sends. Listed inline rather than as a constant
    // above: defineProps is compiled OUT of this scope, so it cannot see a
    // local — and a tone that is not one of these gets no colour at all, which
    // is worth a console warning in development.
    validator: (value) => ['good', 'info', 'normal', 'warn'].includes(value),
  },
})
</script>

<template>
  <span class="pill" :data-tone="tone">
    <span class="pill__dot"></span>
    {{ label }}
  </span>
</template>

<style scoped>
.pill {
  display: inline-flex;
  align-items: center;
  gap: 7px;

  padding: 5px 11px;
  border-radius: var(--radius-pill);

  font-size: var(--text-md);
  font-weight: 600;
  /* Long verdicts ("Cheap & still falling") sit beside a sparkline in a 352 px
     card. Wrapping is better than pushing the chart off the edge. */
  text-wrap: balance;
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
