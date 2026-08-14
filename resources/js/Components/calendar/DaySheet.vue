<script setup>
/*
 * The bottom sheet a tapped day opens (design/README.md §3): the date, the
 * fare, the verdict pill and a swatch of the colour the cell was painted.
 *
 * THE VERDICT IS THE API'S, NOT RECOMPUTED HERE. `cheap` / `mid` / `pricey`
 * arrive already scored against this month's own range with the design's
 * thresholds (docs/API.md), and a second implementation of "cheap ≤ low + 28%
 * of the range" in the client is a second implementation that can disagree with
 * the colour of the cell the user just tapped. This maps it to a tone and a
 * sentence and nothing more.
 *
 * The tone → token mapping is four CSS lines rather than a shared helper: the
 * pill this project will eventually share (`Components/VerdictPill.vue`) is
 * being written in a parallel worktree, and duplicating four lines beats two
 * branches creating the same file. Flagged for the DRY pass.
 */
import { computed, onMounted, onUnmounted } from 'vue'
import { euro } from './format'
import { heatColour } from './heat'
import { dayLabel } from './month'

const props = defineProps({
  fare: { type: Object, required: true },
  min: { type: Number, required: true },
  max: { type: Number, required: true },
})

const emit = defineEmits(['close'])

const VERDICTS = {
  cheap: { tone: 'good', label: 'Cheap day — pounce' },
  mid: { tone: 'normal', label: 'About average' },
  pricey: { tone: 'warn', label: 'Pricey day — skip' },
}

const verdict = computed(() => VERDICTS[props.fare.verdict] ?? VERDICTS.mid)
const swatch = computed(() => heatColour(props.fare.price, props.min, props.max))

// Escape closes it, the same as tapping the backdrop. A sheet that can only be
// dismissed by pointing at it is a sheet a keyboard cannot get out of.
function onKeydown(event) {
  if (event.key === 'Escape') {
    emit('close')
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="backdrop" @click="$emit('close')"></div>

  <div class="sheet" role="dialog" aria-modal="true" :aria-label="dayLabel(fare.date, { withYear: true })">
    <div class="sheet__handle" aria-hidden="true"></div>

    <div class="sheet__head">
      <div>
        <p class="sheet__date">{{ dayLabel(fare.date, { withYear: true }) }}</p>
        <p class="sheet__price tabular">{{ euro(fare.price) }}</p>
      </div>

      <div class="sheet__swatch" :style="{ background: swatch }" aria-hidden="true"></div>
    </div>

    <p class="pill" :class="`pill--${verdict.tone}`">{{ verdict.label }}</p>
  </div>
</template>

<style scoped>
.backdrop {
  position: fixed;
  inset: 0;
  z-index: 20;

  /* Warm rather than neutral black, per the prototype: over the light theme's
     lilac background a grey scrim reads as a rendering artefact. */
  background: rgb(20 15 10 / 32%);
  animation: sheet-fade 0.25s ease both;
}

.sheet {
  position: fixed;
  inset-inline: 0;
  bottom: 0;
  z-index: 21;

  /* Centred on the same column as the shell and the tab bar, so on a laptop
     the sheet belongs to the phone rather than to the window. */
  max-width: var(--app-width);
  margin-inline: auto;

  padding: 10px 22px calc(30px + env(safe-area-inset-bottom));
  border-radius: 26px 26px 0 0;

  background: var(--panel);
  box-shadow: 0 -10px 40px rgb(0 0 0 / 20%);
  animation: sheet-rise 0.35s cubic-bezier(0.2, 0.8, 0.2, 1) both;
}

.sheet__handle {
  width: 38px;
  height: 4px;
  margin: 0 auto 16px;
  border-radius: 3px;
  background: var(--line);
}

.sheet__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.sheet__date {
  font-size: var(--text-lg);
  font-weight: 500;
  color: var(--muted);
}

.sheet__price {
  margin-top: 2px;
  font-family: var(--font-display);
  font-size: 32px;
  font-weight: 700;
  line-height: 1.1;
  color: var(--ink);
}

.sheet__swatch {
  width: 54px;
  height: 54px;
  border-radius: 14px;
}

.pill {
  display: inline-flex;
  align-items: center;
  margin-top: 14px;
  padding: 6px 12px;
  border-radius: var(--radius-pill);

  font-size: 12.5px;
  font-weight: 600;

  background: var(--tone-bg);
  color: var(--tone-ink);
}

.pill--good {
  --tone-bg: var(--good-bg);
  --tone-ink: var(--good-ink);
}

.pill--warn {
  --tone-bg: var(--warn-bg);
  --tone-ink: var(--warn-ink);
}

.pill--normal {
  --tone-bg: var(--neu-bg);
  --tone-ink: var(--neu-ink);
}

@keyframes sheet-rise {
  from {
    transform: translateY(100%);
  }

  to {
    transform: none;
  }
}

@keyframes sheet-fade {
  from {
    opacity: 0;
  }

  to {
    opacity: 1;
  }
}
</style>
