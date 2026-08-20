<script setup>
/*
 * The hand-off (design/README.md §2): an anchor, not a button; two destinations with Aviasales
 * the loud one; the copy says "see this fare", not "book" (docs/BUSINESS-LOGIC.md §12).
 */
defineProps({
  /** The primary hand-off — the search Orbit's own price came out of. */
  aviasalesUrl: { type: String, required: true },

  /*
   * The second opinion. Null-tolerant rather than required: a response from an older build carries
   * no second link, and the honest answer to that is one hand-off rather than none.
   */
  skyscannerUrl: { type: String, default: null },

  /**
   * 'primary' by default; 'secondary' when the advice is a warning — which demotes the whole
   * pair, since the Skyscanner half is an outline in every state.
   */
  variant: {
    type: String,
    default: 'primary',
    validator: (value) => ['primary', 'secondary'].includes(value),
  },
})
</script>

<template>
  <div class="booking">
    <!--
      LEFT THEN RIGHT, AND THE ORDER IS THE OWNER'S: the check before the act.
      Reading order and tap order agree, and the thing that spends money is the
      one furthest from where a thumb rests by accident.
    -->
    <div class="booking__actions">
      <a
        v-if="skyscannerUrl"
        class="booking__link booking__compare"
        :href="skyscannerUrl"
        target="_blank"
        rel="noopener"
      >
        <span>Compare on Skyscanner</span>
      </a>

      <a
        class="booking__link booking__cta"
        :class="`booking__cta--${variant}`"
        :href="aviasalesUrl"
        target="_blank"
        rel="noopener"
      >
        <span>See this fare on Aviasales</span>
        <!-- Stroked from the style block, on the accent fill — see the note in
             AdviceCallout.vue. Only the loud one carries it: two outward arrows
             on one line is decoration, and this is the one that is the point. -->
        <svg width="16" height="16" viewBox="0 0 17 17" fill="none" aria-hidden="true">
          <path d="M5 12L12 5M12 5H6M12 5v6" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </a>
    </div>

    <p class="booking__disclaimer">
      Prices come from recent searches — the booking site shows live availability.
    </p>
  </div>
</template>

<style scoped>
/*
 * THE SPLIT IS 4:6 AND IT IS THE HIERARCHY, not a grid. `min-width: 0` on the children, or a
 * flex item refuses to shrink below its content and the ratio becomes whatever fits.
 */
.booking__actions {
  display: flex;
  gap: 10px;
  margin-top: 16px;
}

/*
 * THE LABELS WRAP RATHER THAN TRUNCATE OR SHRINK: ellipsis hides which site it is, smaller type
 * is the fine-print defect being fixed, and cutting the verbs loses "this leaves the app".
 */
.booking__link {
  flex: 4;
  min-width: 0;

  display: flex;
  align-items: center;
  justify-content: center;
  gap: 7px;

  /* A floor and not a height: the 54 px the design gives the hand-off, kept as
     the minimum so that two lines of label grow the pair instead of spilling
     out of it. */
  min-height: 54px;
  padding: 8px 12px;
  border-radius: 16px;

  font-family: var(--font-display);
  font-size: var(--text-xl);
  font-weight: 700;
  line-height: 1.2;
  text-align: center;
  text-decoration: none;
}

.booking__cta {
  flex: 6;
}

.booking__cta--primary {
  background: var(--accent);
  color: var(--on-solid);
  box-shadow: 0 8px 20px var(--accent-glow);
}

.booking__cta--primary path {
  stroke: var(--on-solid);
}

/*
 * The outline variant, worn by BOTH buttons when the advice is a warning. Same box, same tap
 * target; the accent survives as the TEXT colour, keeping Aviasales the louder quiet one.
 */
.booking__cta--secondary {
  background: var(--card);
  color: var(--accent-ink);
  border: 1px solid var(--line);
}

.booking__cta--secondary path {
  stroke: var(--accent-ink);
}

/* The second opinion, always in the outline treatment: it is a check on the
   number above, never the conclusion. `--ink2` rather than the accent, so that
   under a warning — when the Aviasales button is an outline too — the pair still
   reads in the right order. */
.booking__compare {
  background: var(--card);
  color: var(--ink2);
  border: 1px solid var(--line);
}

.booking__disclaimer {
  margin-top: 10px;
  text-align: center;
  font-size: 11.5px;
  color: var(--muted);
}
</style>
