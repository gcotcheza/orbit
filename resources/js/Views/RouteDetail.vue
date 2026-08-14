<script setup>
/*
 * One route: what it costs, what it usually costs, what we think of that
 * (design/README.md §2). No tab bar — see this route's meta in
 * resources/js/router/index.js.
 *
 * NULL IS NOT ZERO, AND THIS IS THE SCREEN THAT PROVES IT. A route added this
 * morning comes back with `price.current: null`, `stats: null`, `score: 0` and
 * `confident: false` (docs/API.md). Rendering that as a €0 fare against a €0
 * usual, with a damning red gauge, would be the app inventing a deal — so
 * every block below asks whether its own field exists and says "not yet" rather
 * than drawing a confident nothing.
 *
 * THE DATES HERE ARE OBSERVATION DATES. `history[].date` is the day we LOOKED.
 * The days you FLY live on the calendar screen. Nothing on this screen may be
 * derived from one and labelled as the other.
 */
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { http } from '@/lib/http'
import AdviceCallout from '@/Components/route/AdviceCallout.vue'
import BookingCta from '@/Components/route/BookingCta.vue'
import DealScoreGauge from '@/Components/route/DealScoreGauge.vue'
import PriceHistoryChart from '@/Components/route/PriceHistoryChart.vue'
import { euro } from '@/Components/route/format'

/*
 * The API constrains `{code}` to `[A-Z]{3}-[A-Z]{3}` at the router and answers
 * anything else with a 404 rather than a redirect (docs/API.md). A pasted link
 * in the wrong case is a normal thing for a human to produce, though, so the
 * case is normalised HERE — where it is a display concern — and a shape that
 * still does not match is answered locally instead of by a round trip that can
 * only come back 404.
 */
const CODE_PATTERN = /^[A-Z]{3}-[A-Z]{3}$/

const props = defineProps({
  id: { type: String, required: true },
})

const router = useRouter()

const detail = ref(null)
const loading = ref(true)
const notFound = ref(false)
const failed = ref(false)

const code = computed(() => props.id.toUpperCase())

// Statistics are null until the provider has some; the chart draws no
// reference line rather than one at zero.
const median = computed(() => detail.value?.stats?.median ?? null)

/**
 * The line under the price.
 *
 * `pctBelow` IS SIGNED: −14 means fourteen percent ABOVE the usual price
 * (docs/API.md), so the sentence flips rather than printing "−14% below".
 */
const caption = computed(() => {
  const price = detail.value?.price

  if (!price || price.current === null) {
    return 'No fare seen for this route yet.'
  }

  if (price.usual === null) {
    return 'No usual price for this route yet.'
  }

  if (price.pctBelow === null) {
    return `Usually ${euro(price.usual)}.`
  }

  if (price.pctBelow === 0) {
    return `Right at its usual ${euro(price.usual)}`
  }

  const direction = price.pctBelow > 0 ? 'below' : 'above'

  return `${Math.abs(price.pctBelow)}% ${direction} its usual ${euro(price.usual)}`
})

// The last request wins, not the last response: navigating detail → detail
// keeps this component mounted and only changes the prop.
let request = 0

async function load() {
  const mine = (request += 1)

  loading.value = true
  notFound.value = false
  failed.value = false

  if (!CODE_PATTERN.test(code.value)) {
    notFound.value = true
    loading.value = false

    return
  }

  try {
    const { data } = await http.get(`/api/routes/${code.value}`)

    if (mine !== request) {
      return
    }

    detail.value = data.data
  } catch (error) {
    if (mine !== request) {
      return
    }

    detail.value = null

    if (error.response?.status === 404) {
      notFound.value = true
    } else {
      console.error('Could not load the route.', error)
      failed.value = true
    }
  } finally {
    if (mine === request) {
      loading.value = false
    }
  }
}

watch(code, load, { immediate: true })

/**
 * Back to wherever this was opened from — the globe home, the watchlist, the
 * spotlight card.
 *
 * `history.state.back` is vue-router's own record of whether there IS a
 * previous entry in THIS app. Calling `router.back()` without checking would,
 * for someone who opened a shared link straight into a route, walk them out of
 * the app and back to whatever they were reading before it.
 */
function goBack() {
  if (window.history.state?.back) {
    router.back()

    return
  }

  router.push({ name: 'home' })
}
</script>

<template>
  <section class="detail rise-in">
    <header class="detail__bar">
      <button class="detail__back" @click="goBack">
        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
          <path d="M11 4l-5 5 5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        Back
      </button>
    </header>

    <div v-if="loading" class="skeleton" aria-hidden="true">
      <div class="skeleton__line skeleton__line--title"></div>
      <div class="skeleton__line skeleton__line--sub"></div>
      <div class="skeleton__block skeleton__block--price"></div>
      <div class="skeleton__block skeleton__block--chart"></div>
    </div>

    <div v-else-if="notFound" class="empty">
      <h1 class="empty__title">No such route</h1>
      <p class="empty__body">
        We are not tracking <span class="empty__code">{{ code }}</span>. Check the code, or pick a route from the watchlist.
      </p>
      <button class="empty__action" @click="goBack">Go back</button>
    </div>

    <div v-else-if="failed" class="empty">
      <h1 class="empty__title">Could not load this route</h1>
      <p class="empty__body">The connection dropped, or the server is having a moment.</p>
      <button class="empty__action" @click="load">Try again</button>
    </div>

    <template v-else-if="detail">
      <div class="detail__head">
        <h1 class="detail__code">{{ detail.origin.iata }} → {{ detail.destination.iata }}</h1>
        <p class="detail__where">{{ detail.destination.city }}, {{ detail.destination.country }}</p>
      </div>

      <div class="price">
        <div>
          <p class="price__value tabular">{{ detail.price.current === null ? '—' : euro(detail.price.current) }}</p>
          <p class="price__caption">{{ caption }}</p>
        </div>

        <DealScoreGauge :score="detail.score" :confident="detail.confident" />
      </div>

      <PriceHistoryChart
        :history="detail.history"
        :median="median"
        :tone="detail.verdict.tone"
        :tracking-days="detail.trackingDays"
      />

      <AdviceCallout :title="detail.advice.title" :body="detail.advice.body" :tone="detail.advice.tone" />

      <BookingCta :url="detail.bookingUrl" />
    </template>
  </section>
</template>

<style scoped>
.detail {
  padding: 4px var(--gutter) 34px;
}

.detail__bar {
  display: flex;
  align-items: center;
  height: 44px;
}

.detail__back {
  display: flex;
  align-items: center;
  gap: 6px;

  font-size: var(--text-xl);
  font-weight: 600;
  color: var(--ink);
}

.detail__head {
  margin: 6px 2px 4px;
}

.detail__code {
  font-family: var(--font-display);
  font-size: 30px;
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--ink);
}

.detail__where {
  margin-top: 2px;
  font-size: 14px;
  color: var(--muted);
}

.price {
  display: flex;
  align-items: flex-end;
  gap: 14px;
  margin: 16px 2px 4px;
}

.price__value {
  font-family: var(--font-display);
  font-size: 42px;
  font-weight: 700;
  line-height: 1;
  color: var(--ink);
}

.price__caption {
  margin-top: 6px;
  font-size: var(--text-lg);
  color: var(--ink2);
}

/* The gauge is pushed to the far edge rather than the price block being
   stretched, so a long caption wraps under the price instead of shoving the
   ring off the screen. */
.price :deep(.gauge) {
  margin-left: auto;
}

/* --- States ----------------------------------------------------------- */

.empty {
  margin-top: 40px;
  text-align: center;
}

.empty__title {
  font-family: var(--font-display);
  font-size: var(--text-2xl);
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--ink);
}

.empty__body {
  margin: 8px auto 0;
  max-width: 32ch;
  font-size: var(--text-lg);
  color: var(--muted);
}

.empty__code {
  font-family: var(--font-display);
  font-weight: 700;
  color: var(--ink2);
}

.empty__action {
  margin-top: 20px;
  padding: 11px 20px;
  border-radius: var(--radius-chip);

  font-family: var(--font-display);
  font-size: var(--text-lg);
  font-weight: 700;

  background: var(--card);
  border: 1px solid var(--line);
  color: var(--ink);
}

/* Shaped like the screen it stands in for, so the content does not jump into
   place when it lands. */
.skeleton {
  animation: skeleton-breathe 1.4s ease-in-out infinite;
}

.skeleton__line,
.skeleton__block {
  background: var(--card);
  border-radius: var(--radius-chip);
}

.skeleton__line--title {
  width: 62%;
  height: 32px;
  margin: 8px 2px 0;
}

.skeleton__line--sub {
  width: 38%;
  height: 14px;
  margin: 8px 2px 0;
}

.skeleton__block--price {
  height: 64px;
  margin-top: 18px;
}

.skeleton__block--chart {
  height: 216px;
  margin-top: 18px;
  border-radius: 20px;
}

@keyframes skeleton-breathe {
  50% {
    opacity: 0.45;
  }
}
</style>
