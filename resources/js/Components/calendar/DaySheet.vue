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
 *
 * =============================================================================
 * AND THE SHEET NOW SAYS HOW OLD ITS PRICE IS
 * =============================================================================
 * `foundAt` is when the fare was FOUND, which is neither of this app's two
 * usual date axes. Orbit's prices are Travelpayouts' cache of other people's
 * searches, so the number in this sheet can be days old — the owner caught it
 * showing €36 where the live cheapest was €56. The sheet was the worst place
 * for that silence: it is the screen with a booking link on it, and a big
 * confident number over a "Book this day" button is the app implying a fare is
 * on sale right now.
 *
 * UNDER THE PRICE AND DELIBERATELY QUIET. It is a qualifier on the number
 * above it, not a fact of its own — it should be read second and only by
 * somebody who is about to act. NOTHING AT ALL when `foundAt` is null, which is
 * what an old row or a provider that will not say produces: a made-up "Seen just
 * now" would be worse than the silence this replaced.
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
   * The two hand-off templates, each with a named date hole in it, straight
   * from the calendar endpoint's `meta` (docs/API.md): `{ aviasales, skyscanner
   * }`. The hosts, the path shapes, the casing and the affiliate marker are all
   * the SERVER's — App\Application\Routes\BookingLink and config('orbit.booking')
   * own them. Filling a hole is the only part of that this component knows, and
   * the hole is named after its DATE FORMAT rather than after a site, so even
   * that much is arithmetic rather than knowledge about Aviasales.
   *
   * Null-tolerant rather than required: a response from an older build has no
   * templates, and the honest answer to that is a sheet with one action on it
   * rather than no sheet at all.
   */
  booking: { type: Object, default: null },
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
 * The day that was TAPPED, spliced into each template — the only thing the
 * server leaves to the client. `withDateTokens` is string surgery and not a
 * `Date`, for the reason month.js opens with: the API's dates are calendar days
 * with no time and no zone, and routing one through `new Date(...)` re-reads it
 * in the viewer's own timezone, which books the 14th for anybody west of
 * London.
 */
const aviasalesUrl = computed(() => withDateTokens(props.booking?.aviasales, props.fare.date))
const skyscannerUrl = computed(() => withDateTokens(props.booking?.skyscanner, props.fare.date))

/**
 * "Seen 3 hours ago" — how old THIS day's price is.
 *
 * Per-DAY and not per-month, because that is how the cache behind it works: one
 * grid can mix a fare found an hour ago with one found last Thursday, and the
 * only number that matters is the one belonging to the day in front of the
 * reader. Null when the API sent none, and then no line is drawn at all.
 */
const seen = computed(() => seenLabel(props.fare.foundAt))

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
        <!-- Absent, not empty, when Orbit does not know how old the price is —
             a fabricated age is worse than no line. -->
        <p v-if="seen" class="sheet__seen">Seen {{ seen }}</p>
      </div>

      <!--
        THE SWATCH, WITH THE ONE THING IT WAS MISSING: what it is of.

        It is a 54 px square of a colour whose whole meaning is a comparison —
        where this day's fare sits between the cheapest and dearest day of THIS
        month, on the same ramp the grid behind the sheet is painted from
        (heat.js). Without the grid in front of you that is unguessable, and the
        UX pass read it as decoration. Four words fix it.

        THE SQUARE STAYS `aria-hidden`; the caption is what a screen reader gets,
        which is right — the colour is a restatement of the verdict pill below,
        and the pill says it in words already.
      -->
      <div class="sheet__heat">
        <div class="sheet__swatch" :style="{ background: swatch }" aria-hidden="true"></div>
        <p class="sheet__swatch-label">Price vs month</p>
      </div>
    </div>

    <p class="pill" :class="`pill--${verdict.tone}`">{{ verdict.label }}</p>

    <!--
      Inside the sheet, which is a SIBLING of the backdrop and not a child of it:
      a tap that lands on any action cannot also be a tap on the backdrop, so
      nothing here has to stop a propagation.

      THE WAY OUT, ON ITS OWN LINE. It shared a row with the booking hand-off
      until the two hand-offs became a pair of their own (below) — and it is the
      one control here that is not about buying anything, so a row to itself is
      the honest place for it. Above the pair rather than below: the conclusion
      of this sheet is the fare, and the conclusion belongs nearest the thumb.
    -->
    <RouterLink class="action action--quiet action--wide" :to="{ name: 'route-detail', params: { id: code } }">
      Route details
    </RouterLink>

    <!--
      THE TWO HAND-OFFS, AS A PAIR — the same shape and the same order as
      Components/route/BookingCta.vue, because they are the same decision on two
      screens and the owner should not have to learn it twice. Skyscanner on the
      left as the check, Aviasales on the right with the accent and the wider
      share.

      AVIASALES IS THE LOUD ONE BECAUSE THAT IS WHERE THIS PRICE CAME FROM.
      Orbit's fares are Travelpayouts' — Aviasales' — cache, and this sheet used
      to send people to Skyscanner, which had never seen many of them (€29 here
      against €68 there). "See this fare" rather than "Book this day" because
      neither site is promising a seat and this one is showing a price that may
      be days old.

      AND SKYSCANNER STOPPED BEING A LINE OF TEXT. It was a 12 px centred link
      under the row, in the same grey as the disclaimer beneath it, which on a
      phone does not read as something that can be pressed at all. Same
      correction as BookingCta's, made here so the two sheets agree.
    -->
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

    <!-- Word for word BookingCta's, and duplicated rather than shared on
         purpose: that component is a full-width 54px button and this is half of
         a compact pair, so what they have in common is the SENTENCE and not the
         layout. It says where the number came from and who has the real one,
         which is what somebody about to leave the app for a cached fare needs —
         and it MERGED the old "we don't sell tickets" line rather than being
         added under it, because two greyed sentences read as small print and
         small print is not read. If this copy changes, change it there too. -->
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

/* A qualifier on the price above it, drawn to be read second: the muted ink and
   the small size are the same pair the disclaimer uses, because they are the
   same kind of statement — true, necessary, and not the thing on the screen. */
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

/* The booking pair, in BookingCta.vue's proportions: the check on the left, the
   search Orbit's own number came from on the right with six-tenths of the line.
   They sit at the bottom of a sheet that is already at the bottom of the screen,
   which is the part of a phone a thumb reaches without moving. */
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

  /* A FLOOR AND NOT A HEIGHT. Above the 44 px a finger needs, and now a minimum
     rather than a fixed box: the labels name the site they lead to and take two
     lines to do it on a narrow phone (see BookingCta.vue for why they are not
     shortened instead), so the button grows rather than spilling. */
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

/* The design's INACTIVE chip — card on panel with a hairline — which is this
   app's vocabulary for "an action that is not the loud one". Both the route link
   and the Skyscanner check wear it. */
.action--quiet {
  background: var(--card);
  color: var(--ink);
  border: 1px solid var(--line);
}

/* One step quieter again than the route link above it: this is a check on the
   number, not a way out of the sheet. The same ink BookingCta.vue gives its own
   Skyscanner button, so the pair reads in the same order on both screens. */
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
