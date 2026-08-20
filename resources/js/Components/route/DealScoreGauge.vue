<script setup>
/*
 * The deal-score ring (design/README.md §2).
 *
 * THIS IS NOT THE ALERT TIER. The API also sends `tier` — insane / great /
 * good / none — and that is the threshold the alert sensitivities in PR11 fire
 * on, at ≥80 / ≥65 / ≥50. The ring uses a DIFFERENT scale, the design's:
 * ≥80 good, ≥60 info, ≥45 warn, else bad. They are deliberately not the same
 * numbers, so the colour is computed here from `score` and the API sends none
 * (docs/API.md).
 *
 * 157 is the circumference: 2π × 25, the radius the design draws. Dashing the
 * whole ring and offsetting by the unfilled part is what turns one circle into
 * a progress arc.
 */
import { computed } from 'vue'

const CIRCUMFERENCE = 157

const props = defineProps({
  score: { type: Number, required: true },
  // `false` means the score is a placeholder: no fares and no statistics yet (docs/API.md). Branch
  // on THIS, never on `score === 0` — zero is also a real, terrible score.
  confident: { type: Boolean, default: false },
})

const tone = computed(() => {
  if (props.score >= 80) {
    return 'good'
  }

  if (props.score >= 60) {
    return 'info'
  }

  return props.score >= 45 ? 'warn' : 'bad'
})

// An unknown score draws no arc at all rather than an arc of zero length in a damning red. Nothing
// is known; nothing is claimed.
const offset = computed(() => (props.confident ? (CIRCUMFERENCE * (100 - props.score)) / 100 : CIRCUMFERENCE))

const label = computed(() => (props.confident ? `Deal score ${props.score} out of 100` : 'Deal score not known yet'))
</script>

<template>
  <div class="gauge">
    <div class="gauge__dial" role="img" :aria-label="label">
      <svg width="58" height="58" viewBox="0 0 58 58">
        <circle class="gauge__track" cx="29" cy="29" r="25" />
        <circle
          class="gauge__ring"
          :class="`gauge__ring--${tone}`"
          cx="29"
          cy="29"
          r="25"
          stroke-dasharray="157"
          :stroke-dashoffset="offset"
          transform="rotate(-90 29 29)"
        />
      </svg>

      <span class="gauge__value tabular">{{ confident ? score : '—' }}</span>
    </div>

    <!-- THE SCALE IS PART OF THE NUMBER. A ring reading 65 with "DEAL SCORE"
         under it is a figure with no units: 65 out of 100, out of 10, out of
         five stars, or a rank among the routes on the list — all four are
         readings a person actually offered, and the arc does not settle it
         because an arc is what a battery meter is too. The `aria-label` has
         said "out of 100" from the start; this is the sighted half of the same
         sentence. Dropped when there is no score to scale — "/100" under a
         dash would be putting units on a number that is not there. -->
    <p class="gauge__caption">Deal score{{ confident ? ' /100' : '' }}</p>
  </div>
</template>

<style scoped>
.gauge {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}

.gauge__dial {
  position: relative;
  width: 58px;
  height: 58px;
}

.gauge__track {
  fill: none;
  stroke: var(--track);
  stroke-width: 6;
}

/* The stroke is a CSS property rather than a `stroke="var(--good)"` attribute:
   var() is a CSS value, and browsers disagree about whether a presentation
   attribute may carry one. The ones that say no paint the ring black. */
.gauge__ring {
  fill: none;
  stroke-width: 6;
  stroke-linecap: round;
  transition: stroke-dashoffset 1s ease, stroke 0.3s ease;
}

.gauge__ring--good {
  stroke: var(--good);
}

.gauge__ring--info {
  stroke: var(--info);
}

.gauge__ring--warn {
  stroke: var(--warn);
}

.gauge__ring--bad {
  stroke: var(--bad);
}

.gauge__value {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;

  font-family: var(--font-display);
  font-size: 17px;
  font-weight: 700;
  line-height: 1;
  color: var(--ink);
}

/* The caption is wider than the 58 px dial above it now that it carries the
   scale, and it must stay on one line: "DEAL" over "SCORE /100" would read as
   two labels. The gauge block simply gets as wide as its widest child, which
   the price row beside it has room for. */
.gauge__caption {
  white-space: nowrap;
  font-size: var(--text-xs);
  font-weight: 600;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  color: var(--muted);
}
</style>
