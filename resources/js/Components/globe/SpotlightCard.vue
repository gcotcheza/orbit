<script setup>
/*
 * The card under the globe: what the camera is currently flying, in words.
 *
 * design/README.md §1 — route code, city, country, the fare and how it compares
 * to usual, then a verdict pill, a fortnight's sparkline and a chevron into the
 * route detail.
 *
 * IT IS A LINK, not a button with a router.push in it. The whole card opens
 * /route/AMS-LIS, and a real <a> is what makes that long-pressable, openable in
 * a new tab, and announced as a link rather than as a mystery.
 *
 * THE DAY-1 CASE IS NOT AN ERROR. A route added this morning has
 * `price.current: null`, no statistics and `confident: false` (docs/API.md).
 * The card then says what it actually knows — "No fare yet" and how long we
 * have been watching — instead of drawing €0 and a damning verdict. The same
 * note appears for any route with under a fortnight of history, which is
 * docs/PLAN.md's day-1 honesty rule.
 */
import { computed } from 'vue'
import PriceSparkline from '@/Components/PriceSparkline.vue'
import VerdictPill from '@/Components/VerdictPill.vue'
import { euros, usualPriceLabel } from './format'

const props = defineProps({
  /** One `GET /api/watchlist` row. */
  route: { type: Object, required: true },
})

/** docs/API.md: under a fortnight of our own observations is worth saying. */
const TRACKING_NOTE_DAYS = 14

const price = computed(() => euros(props.route.price.current))

const comparison = computed(() => usualPriceLabel(props.route.price.pctBelow))

const trackingNote = computed(() => {
  const days = props.route.trackingDays

  if (days >= TRACKING_NOTE_DAYS) {
    return null
  }

  return days === 1 ? 'tracking 1 day' : `tracking ${days} days`
})
</script>

<template>
  <RouterLink class="spotlight rise-in" :to="{ name: 'route-detail', params: { id: route.code } }">
    <div class="spotlight__head">
      <div>
        <p class="spotlight__code">{{ route.origin.iata }} → {{ route.destination.iata }}</p>
        <h2 class="spotlight__city">{{ route.destination.city }}</h2>
        <p class="spotlight__country">{{ route.destination.country }}</p>
      </div>

      <div class="spotlight__money">
        <p class="spotlight__price tabular" :class="{ 'spotlight__price--empty': price === null }">
          {{ price ?? 'No fare yet' }}
        </p>
        <p v-if="comparison" class="spotlight__usual">{{ comparison }}</p>
        <p v-if="trackingNote" class="spotlight__tracking">{{ trackingNote }}</p>
      </div>
    </div>

    <div class="spotlight__foot">
      <VerdictPill :label="route.verdict.label" :tone="route.verdict.tone" />

      <div class="spotlight__trend">
        <PriceSparkline :values="route.sparkline" :tone="route.verdict.tone" />

        <svg width="17" height="17" viewBox="0 0 18 18" fill="none" aria-hidden="true">
          <path d="M6 4l5 5-5 5" stroke="var(--muted)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </div>
    </div>
  </RouterLink>
</template>

<style scoped>
.spotlight {
  /* No margins: WHERE the card sits is the screen's business — the home screen
     rides it up over the globe's lower edge (design/README.md §1), and the
     WebGL fallback stacks a column of them. */
  display: block;
  padding: 16px 17px;
  border: 1px solid var(--line);
  border-radius: var(--radius-card);

  background: var(--card);
  box-shadow: var(--shadow);
  text-decoration: none;
}

.spotlight__head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
}

.spotlight__code {
  font-family: var(--font-display);
  font-size: var(--text-xs);
  font-weight: 700;
  letter-spacing: 0.13em;
  color: var(--accent-ink);
}

.spotlight__city {
  margin-top: 4px;
  font-family: var(--font-display);
  font-size: var(--text-2xl);
  font-weight: 700;
  letter-spacing: -0.01em;
  color: var(--ink);
}

.spotlight__country {
  margin-top: 1px;
  font-size: var(--text-md);
  color: var(--muted);
}

.spotlight__money {
  text-align: right;
  /* The city name gets the leftovers; the price never wraps. */
  flex-shrink: 0;
}

.spotlight__price {
  font-family: var(--font-display);
  font-size: var(--text-3xl);
  font-weight: 700;
  line-height: 1;
  color: var(--ink);
}

/* "No fare yet" is a sentence, not a number: it gets sentence-sized type and a
   quieter colour rather than the 27 px a price would have had. */
.spotlight__price--empty {
  font-size: var(--text-xl);
  color: var(--muted);
}

.spotlight__usual {
  margin-top: 4px;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--ink2);
}

.spotlight__tracking {
  margin-top: 2px;
  font-size: var(--text-sm);
  color: var(--muted);
}

.spotlight__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-top: 14px;
}

.spotlight__trend {
  display: flex;
  align-items: center;
  gap: 11px;
  flex-shrink: 0;
}
</style>
