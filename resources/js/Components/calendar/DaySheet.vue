<script setup>
/*
 * The bottom sheet a tapped day opens (design/README.md §3). THE VERDICT IS THE API'S, not
 * recomputed here, and both actions are links (docs/BUSINESS-LOGIC.md §36).
 */
import { computed, onMounted, onUnmounted } from 'vue'
import { RouterLink } from 'vue-router'
import { euro, seenLabel, withDateTokens } from '@/lib/format'
import { heatColour } from './heat'
import { dayLabel } from './month'

const props = defineProps({
  fare: { type: Object, required: true },
  min: { type: Number, required: true },
  max: { type: Number, required: true },
  // The route the month belongs to, for the detail link.
  code: { type: String, required: true },
  /*
   * The two hand-off templates from the endpoint's `meta`: hosts, paths, casing and the marker
   * are the SERVER's. Null-tolerant, so an older build's response still opens a sheet.
   */
  booking: { type: Object, default: null },

  /*
   * Beside the grid rather than over it (Views/Calendar.vue, >=1024). Nothing is covered, so there
   * is no backdrop to dismiss and no dialog to be modal.
   */
  docked: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

const VERDICTS = {
  cheap: { tone: 'good', label: 'Cheap day — pounce' },
  mid: { tone: 'normal', label: 'About average' },
  pricey: { tone: 'warn', label: 'Pricey day — skip' },
}

const verdict = computed(() => VERDICTS[props.fare.verdict] ?? VERDICTS.mid)
const swatch = computed(() => heatColour(props.fare.price, props.min, props.max))

/*
 * The day that was TAPPED, spliced into each template. String surgery and not a `Date`: the
 * API's dates are calendar days, and `new Date(...)` re-reads them in the viewer's zone.
 */
const aviasalesUrl = computed(() => withDateTokens(props.booking?.aviasales, props.fare.date))
const skyscannerUrl = computed(() => withDateTokens(props.booking?.skyscanner, props.fare.date))

/**
 * "Seen 3 hours ago" — how old THIS day's price is. Per-DAY, not per-month, because one grid
 * can mix a fare found an hour ago with one found last Thursday. Null draws no line.
 */
const seen = computed(() => seenLabel(props.fare.foundAt))

// Escape closes it, the same as tapping the backdrop. A sheet that can only be dismissed by
// pointing at it is a sheet a keyboard cannot get out of.
function onKeydown(event) {
  if (event.key === 'Escape') {
    emit('close')
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div v-if="!docked" class="backdrop" @click="$emit('close')"></div>

  <div
    class="sheet"
    :class="{ 'sheet--docked': docked }"
    :role="docked ? 'region' : 'dialog'"
    :aria-modal="docked ? null : 'true'"
    :aria-label="dayLabel(fare.date, { withYear: true })"
  >
    <div class="sheet__handle" aria-hidden="true"></div>

    <div class="sheet__head">
      <div>
        <p class="sheet__date">{{ dayLabel(fare.date, { withYear: true }) }}</p>
        <p class="sheet__price tabular">{{ euro(fare.price) }}</p>
        <!-- Absent, not empty, when Orbit does not know how old the price is —
             a fabricated age is worse than no line. -->
        <p v-if="seen" class="sheet__seen">Seen {{ seen }}</p>
      </div>

      <!-- THE SWATCH SAYS WHAT IT IS OF: without the grid in front of you, the colour is
           unguessable (docs/BUSINESS-LOGIC.md §36). -->
      <div class="sheet__heat">
        <div class="sheet__swatch" :style="{ background: swatch }" aria-hidden="true"></div>
        <p class="sheet__swatch-label">Price vs month</p>
      </div>
    </div>

    <p class="pill" :class="`pill--${verdict.tone}`">{{ verdict.label }}</p>

    <!-- A SIBLING of the backdrop, so no tap here has to stop a propagation. The way out gets its
         own row, above the pair. -->
    <RouterLink class="action action--quiet action--wide" :to="{ name: 'route-detail', params: { id: code } }">
      Route details
    </RouterLink>

    <!-- THE TWO HAND-OFFS, AS A PAIR — the same shape and order as BookingCta.vue, because they are
         one decision on two screens (docs/BUSINESS-LOGIC.md §36). -->
    <div class="actions">
      <a
        v-if="skyscannerUrl"
        class="action action--quiet compare"
        :href="skyscannerUrl"
        target="_blank"
        rel="noopener"
      >
        <span>Compare on Skyscanner</span>
      </a>

      <a v-if="aviasalesUrl" class="action action--solid" :href="aviasalesUrl" target="_blank" rel="noopener">
        <span>See this fare on Aviasales</span>
        <!-- Stroked from the style block, on the accent fill — the same arrow
             BookingCta uses, for the same reason. -->
        <svg width="15" height="15" viewBox="0 0 17 17" fill="none" aria-hidden="true">
          <path d="M5 12L12 5M12 5H6M12 5v6" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </a>
    </div>

    <!-- Word for word BookingCta's, duplicated rather than shared: what they have in common is the
         SENTENCE, not the layout. Change it in both. -->
    <p v-if="aviasalesUrl" class="disclaimer">
      Prices come from recent searches — the booking site shows live availability.
    </p>
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
  max-width: var(--shell-max);
  margin-inline: auto;

  padding: 10px 22px calc(30px + env(safe-area-inset-bottom));
  border-radius: 26px 26px 0 0;

  background: var(--panel);
  box-shadow: 0 -10px 40px rgb(0 0 0 / 20%);
  animation: sheet-rise 0.35s cubic-bezier(0.2, 0.8, 0.2, 1) both;
}

/*
 * A card in the pane: no viewport pinning, no slide-up, and the grab handle goes with the gesture
 * it belonged to. The recipe is the design canvas's `.day` (docs/DESKTOP-LAYOUT-PLAN.md).
 */
.sheet--docked {
  position: static;
  max-width: none;
  margin-inline: 0;
  padding: 20px;
  border: 1px solid var(--line);
  border-radius: 26px;
  box-shadow: var(--shadow);
  animation: none;
}

.sheet--docked .sheet__handle {
  display: none;
}

/* The panel is a column roughly a fifth of the window wide; the pair does not fit side by side. */
.sheet--docked .actions {
  flex-direction: column;
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

/* A qualifier on the price above it, drawn to be read second — the same muted ink and size the
   disclaimer uses. */
.sheet__seen {
  margin-top: 4px;
  font-size: var(--text-sm);
  color: var(--muted);
}

/* The square and its caption as one column, right-aligned with the swatch it
   labels — the head is a two-column flex and this is the second column. */
.sheet__heat {
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 5px;
}

.sheet__swatch {
  width: 54px;
  height: 54px;
  border-radius: 14px;
}

/* The same quiet pair as `.sheet__seen` and the disclaimer, because it is the
   same kind of line: true, necessary, and not the thing on the screen. */
.sheet__swatch-label {
  font-size: var(--text-xs);
  color: var(--muted);
  white-space: nowrap;
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

/* The booking pair in BookingCta.vue's proportions, at the bottom of a sheet that is already where
   a thumb reaches. */
.actions {
  display: flex;
  gap: 10px;
  margin-top: 10px;
}

.action {
  flex: 4;
  min-width: 0;

  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;

  /* A FLOOR AND NOT A HEIGHT: above the 44px a finger needs, and a minimum, so a two-line label
     grows the button rather than spilling. */
  min-height: 48px;
  padding: 7px 11px;
  border-radius: var(--radius-chip);

  font-family: var(--font-display);
  font-size: var(--text-lg);
  font-weight: 700;
  line-height: 1.2;
  text-align: center;
  text-decoration: none;
}

/* The way into the route, on its own line above the pair. */
.action--wide {
  width: 100%;
  margin-top: 18px;
}

/* The design's INACTIVE chip — card on panel with a hairline — this app's vocabulary for "an action
   that is not the loud one". */
.action--quiet {
  background: var(--card);
  color: var(--ink);
  border: 1px solid var(--line);
}

/* One step quieter again than the route link: this is a check on the number, in the same ink
   BookingCta.vue gives its Skyscanner button. */
.compare {
  color: var(--ink2);
}

/* The accent, which in this app means "an action", and the same glow the
   design puts under the active chip and the tab bar's + button. */
.action--solid {
  flex: 6;
  background: var(--accent);
  color: var(--on-solid);
  box-shadow: 0 6px 16px var(--accent-glow);
}

.action--solid path {
  stroke: var(--on-solid);
}

.disclaimer {
  margin-top: 10px;
  text-align: center;
  font-size: var(--text-sm);
  color: var(--muted);
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
