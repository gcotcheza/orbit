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
 * THIS PILL IS NOT `Components/VerdictPill.vue`, and the DRY pass left it that
 * way deliberately. The shared one is a dotted, tone-coloured chip that labels
 * something else on the screen — a card's verdict, a row's status — and it is
 * built to sit inline beside that thing. This is the sheet's own headline: no
 * dot, a size of its own, and the only thing in its half of the sheet. Making
 * one component serve both would mean a boolean for the dot and a third size
 * for one caller, which is more machinery than the four token pairs below.
 * What they DO share is the tone vocabulary, and that lives in tokens.css.
 *
 * THE TWO ACTIONS ARE NOT THE MOCKUP'S. design/README.md §3's sheet carried
 * "Set alert" and "View fares", and this component shipped without them on
 * purpose: neither had anywhere to go, and a control that does nothing is worse
 * than an absent one. They have somewhere to go now — the route's own screen,
 * and the booking hand-off aimed at THIS day rather than at the route's
 * cheapest — so the sheet stops being a dead end. The labels say where they
 * actually land instead of inheriting names for features that do not exist.
 *
 * BOTH ARE LINKS, and the outward one is a link for exactly the reasons
 * Components/route/BookingCta.vue gives: it leaves the app, so it has to be
 * long-pressable, copyable and announced as a link — with `rel="noopener"` and
 * deliberately WITHOUT `noreferrer`, which is what carries the affiliate
 * attribution. The inward one is a RouterLink rather than a button that pushes,
 * so that the pair are the same kind of thing to a screen reader and to a
 * long-press, and so the route detail is a real URL here as it is everywhere
 * else in this app.
 */
import { computed, onMounted, onUnmounted } from 'vue'
import { RouterLink } from 'vue-router'
import { euro } from '@/lib/format'
import { heatColour } from './heat'
import { dayLabel } from './month'

const props = defineProps({
  fare: { type: Object, required: true },
  min: { type: Number, required: true },
  max: { type: Number, required: true },
  // The route the month belongs to, for the detail link.
  code: { type: String, required: true },
  /*
   * The Skyscanner deep link with a `{date}` hole in it, straight from the
   * calendar endpoint's `meta` (docs/API.md). The host, the path shape and the
   * lower-cased codes are the SERVER's — App\Application\Routes\BookingLink and
   * config('orbit.booking') own them, and config/orbit.php says out loud that
   * they may one day point at a different affiliate. Filling a hole is the only
   * part of that this component is allowed to know.
   *
   * Null-tolerant rather than required: a response from an older build has no
   * template, and the honest answer to that is a sheet with one action on it
   * rather than no sheet at all.
   */
  bookingUrlTemplate: { type: String, default: null },
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
 * `2026-09-15` → `260915`, which is the only thing the template leaves to the
 * client. STRING SURGERY AND NOT A `Date`, for the reason month.js opens with:
 * the API's dates are calendar days with no time and no zone, and routing one
 * through `new Date(...).toLocaleDateString()` re-reads it in the viewer's own
 * timezone — which books the 14th for anybody west of London.
 */
const bookingUrl = computed(() =>
  props.bookingUrlTemplate === null
    ? null
    : props.bookingUrlTemplate.replace('{date}', props.fare.date.slice(2).replaceAll('-', '')),
)

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

    <!-- Inside the sheet, which is a SIBLING of the backdrop and not a child of
         it: a tap that lands on either action cannot also be a tap on the
         backdrop, so nothing here has to stop a propagation. -->
    <div class="actions">
      <RouterLink class="action action--quiet" :to="{ name: 'route-detail', params: { id: code } }">
        Route details
      </RouterLink>

      <a v-if="bookingUrl" class="action action--solid" :href="bookingUrl" target="_blank" rel="noopener">
        Book this day
        <!-- Stroked from the style block, on the accent fill — the same arrow
             BookingCta uses, for the same reason. -->
        <svg width="15" height="15" viewBox="0 0 17 17" fill="none" aria-hidden="true">
          <path d="M5 12L12 5M12 5H6M12 5v6" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </a>
    </div>

    <!-- Word for word BookingCta's, and duplicated rather than shared on
         purpose: that component is a full-width 54px checkout button and this
         is half of a compact pair, so what they have in common is the SENTENCE
         and not the layout. The line exists because the button looks like a
         checkout, and the two places we say it must not be able to disagree —
         if this copy changes, change it there too. -->
    <p v-if="bookingUrl" class="disclaimer">
      We don't sell tickets — we hand you off to the airline or an OTA.
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

/* Side by side and equal, because neither is the obvious next step: "where is
   this route going" and "buy this day" are two different intentions and the
   sheet does not know which one brought the user here. They sit at the bottom
   of a sheet that is already at the bottom of the screen, which is the part of
   a phone a thumb reaches without moving. */
.actions {
  display: flex;
  gap: 10px;
  margin-top: 18px;
}

.action {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;

  /* Above the 44px a finger needs, and short enough that two of them plus the
     disclaimer do not push the fare off a small screen. */
  height: 48px;
  border-radius: var(--radius-chip);

  font-family: var(--font-display);
  font-size: var(--text-lg);
  font-weight: 700;
  text-decoration: none;
  /* "Route details" is two words on a 320px screen otherwise. */
  white-space: nowrap;
}

/* The design's INACTIVE chip — card on panel with a hairline — which is this
   app's vocabulary for "a second action that is not the loud one". */
.action--quiet {
  background: var(--card);
  color: var(--ink);
  border: 1px solid var(--line);
}

/* The accent, which in this app means "an action", and the same glow the
   design puts under the active chip and the tab bar's + button. */
.action--solid {
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
