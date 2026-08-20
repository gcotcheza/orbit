<script setup>
/*
 * One route: what it costs, what it usually costs, what we think of that (design/README.md §2).
 * Null fields render as "not yet"; `history[].date` is when we LOOKED (docs/BUSINESS-LOGIC.md §3).
 */
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { http } from '@/lib/http'
import { useWatchlistStore } from '@/stores/watchlist'
import AdviceCallout from '@/Components/route/AdviceCallout.vue'
import BookingCta from '@/Components/route/BookingCta.vue'
import DealScoreGauge from '@/Components/route/DealScoreGauge.vue'
import PriceHistoryChart from '@/Components/route/PriceHistoryChart.vue'
import { departureLabel, euro, hoursSince, seenLabel } from '@/lib/format'

// Case-normalised here (a display concern) so a bad shape is rejected locally instead of by a round
// trip that can only come back 404 (docs/BUSINESS-LOGIC.md §36).
const CODE_PATTERN = /^[A-Z]{3}-[A-Z]{3}$/

// 25s: several times a healthy fetch's own 2-3s, still short of anyone's patience. Giving up loses
// nothing — the writes behind it are upserts (docs/BUSINESS-LOGIC.md §36).
const LOOKUP_TIMEOUT_MS = 25_000

// 24h, matching the poll's own daily period — under it is an ordinary watched route; past it, a
// fare that survived a morning it should not have (docs/BUSINESS-LOGIC.md §36).
const SEEN_AFTER_HOURS = 24

/* Ends the WAIT, not the work: the server stores what it paid for either way. */
const LIVE_CHECK_TIMEOUT_MS = 30_000

const props = defineProps({
  id: { type: String, required: true },
})

const router = useRouter()
const watchlist = useWatchlistStore()

const detail = ref(null)

/** `meta`: `watched` + fare age. Null before the first answer and on any
 *  answer without one — both dependents fail closed. */
const meta = ref(null)

const loading = ref(true)
const notFound = ref(false)
const failed = ref(false)

/** The on-demand fetch is in flight. */
const checking = ref(false)

/** Why the failure state is on screen, when it is not the ordinary reason. */
const failedBody = ref('')

/** A refresh that could not be made, over data that is still worth showing. */
const refreshNotice = ref('')

/** The "Add to watchlist" button's own state. */
const watching = ref(false)
const watchError = ref('')

/** The live check is in flight — one SerpAPI search, and somebody is waiting. */
const checkingLive = ref(false)

/** Why no live answer arrived, in the reader's words rather than a status code. */
const liveError = ref('')

/** Separate from `meta.watched`: this is the answer to a button just
 *  pressed, not whether the route was already watched on open.
 *  Why: docs/BUSINESS-LOGIC.md §36. */
const justWatched = ref(false)

const code = computed(() => props.id.toUpperCase())

/** `=== false`, not falsy on purpose: `meta` is null when it carries none,
 *  and a screen that can't tell must not offer to add a route twice. */
const unwatched = computed(() => meta.value?.watched === false)

/** The day Orbit last got fares, for the "refresh did not happen" line.
 *  NOT `departureLabel` — this is a day WE LOOKED, not a day you FLY.
 *  Why: docs/BUSINESS-LOGIC.md §36. */
const lastChecked = computed(() => {
  const at = meta.value?.fares?.fetchedAt

  if (!at) {
    return null
  }

  const [year, month, day] = at.slice(0, 10).split('-').map(Number)

  return new Intl.DateTimeFormat('en-US', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    timeZone: 'UTC',
  }).format(new Date(Date.UTC(year, month - 1, day)))
})

// Statistics are null until the provider has some; the chart draws no reference line rather than
// one at zero.
const median = computed(() => detail.value?.stats?.median ?? null)

/** `cheapest.date` is a DEPARTURE date (day you fly), never derived from
 *  `history[].date` (day we looked). Null before the first poll.
 *  Why: docs/BUSINESS-LOGIC.md §36. */
const departure = computed(() => departureLabel(detail.value?.cheapest?.date ?? null))

/** `cheapest.foundAt` is a THIRD date (when the price was found) — distinct
 *  from looked-at and fly-at. Null unless there's an honest age to show.
 *  Why: docs/BUSINESS-LOGIC.md §36. */
const seen = computed(() => {
  const foundAt = detail.value?.cheapest?.foundAt ?? null
  const age = hoursSince(foundAt)

  return age === null || age < SEEN_AFTER_HOURS ? null : seenLabel(foundAt)
})

/** ⚠ The SERVER's judgement, never recomputed here — `=== true` because
 *  an older build's answer carries no such field. */
const mayBeGone = computed(() => detail.value?.cheapest?.mayBeGone === true)

/** Google's answer, when Orbit has one inside its cooldown (docs/API.md). */
const live = computed(() => meta.value?.liveCheck ?? null)

/** ⚠ Null is "Google had no opinion" — never reassurance, never a fallback. */
const livePrice = computed(() => live.value?.lowest ?? null)

/** "just now", "2 hours ago" — how long ago Orbit asked. */
const liveWhen = computed(() => (live.value === null ? null : seenLabel(live.value.checkedAt)))

/** The headline is Orbit's own, and it is one of the ones worth doubting. */
const demoted = computed(() => mayBeGone.value && livePrice.value === null)

/** ⚠ `cheapest` — the fare demotion/live-check reference (docs/API.md's
 *  `price.current`). Pill, callout and number must stay about ONE fare. */
const judgedFare = computed(() => detail.value?.cheapest?.price ?? null)

/** Google's live figure when there is one; Orbit's own fare otherwise. */
const headline = computed(
  () => livePrice.value ?? (demoted.value ? judgedFare.value : detail.value?.price?.current) ?? null,
)

const goneLabel = computed(() =>
  seen.value === null ? 'This fare may already be gone' : `Seen ${seen.value} — may be gone`,
)

/* Orbit's own fare stays on the page once Google's takes the headline: the
   disagreement is what the search was paid for. */
const cachedLine = computed(() => {
  const cached = euro(judgedFare.value)

  if (livePrice.value === null || cached === null) {
    return null
  }

  return seen.value === null
    ? `Orbit’s cached fare ${cached}`
    : `Orbit’s cached fare ${cached}, seen ${seen.value}`
})

/* Google's "usual", from a market Orbit cannot see. Often absent. */
const liveTypical = computed(() => {
  const low = euro(live.value?.typicalLow ?? null)
  const high = euro(live.value?.typicalHigh ?? null)

  return low === null || high === null ? null : `Google’s typical ${low}–${high}`
})

/** `pctBelow` IS SIGNED (docs/API.md): negative means ABOVE usual. Silent
 *  under a live headline — it would misread as an opinion of Google's.
 *  Why: docs/BUSINESS-LOGIC.md §36. */
const caption = computed(() => {
  const price = detail.value?.price

  if (livePrice.value !== null) {
    return null
  }

  if (!price || price.current === null) {
    return 'No fare seen for this route yet.'
  }

  if (price.usual === null) {
    return 'No usual price for this route yet.'
  }

  /* NOT CONFIDENT, SO NO PERCENTAGE — `confident: false` means Orbit has no
     opinion yet; the usual price still shows, the comparison doesn't.
     Why: docs/BUSINESS-LOGIC.md §36. */
  if (detail.value?.confident === false) {
    return `Usual ${euro(price.usual)} · still learning`
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

// The last request wins, not the last response: navigating detail → detail keeps this component
// mounted and only changes the prop.
let request = 0

async function load() {
  const mine = (request += 1)

  loading.value = true
  checking.value = false
  notFound.value = false
  failed.value = false
  failedBody.value = ''
  refreshNotice.value = ''
  watchError.value = ''
  justWatched.value = false
  checkingLive.value = false
  liveError.value = ''

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

    adopt(data)
    loading.value = false

    // Refresh only when STALE AND UNWATCHED: a watched route's poll will fix stale fares; an
    // unwatched one has nothing else that ever will (docs/BUSINESS-LOGIC.md §36).
    if (!meta.value?.fares?.fresh && unwatched.value) {
      await lookUp(mine)
    }
  } catch (error) {
    if (mine !== request) {
      return
    }

    // 404 means no route row yet, not a dead end: try pricing it via lookup. An invalid pair is
    // refused there instead, with its own message (docs/BUSINESS-LOGIC.md §36).
    if (error.response?.status === 404) {
      loading.value = false

      await lookUp(mine)

      return
    }

    detail.value = null
    meta.value = null
    loading.value = false

    console.error('Could not load the route.', error)
    failed.value = true
  }
}

/** @param {number} mine the request token this belongs to — see `load` */
async function lookUp(mine) {
  const [origin, destination] = code.value.split('-')

  checking.value = true

  try {
    const { data } = await http.post(
      '/api/routes/lookup',
      { origin, destination },
      { timeout: LOOKUP_TIMEOUT_MS },
    )

    if (mine !== request) {
      return
    }

    adopt(data)
  } catch (error) {
    if (mine !== request) {
      return
    }

    describeLookupFailure(error)
  } finally {
    if (mine === request) {
      checking.value = false
    }
  }
}

/** THE ORDER OF THESE BRANCHES IS THE JUDGEMENT — 422 means unpriceable,
 *  a price already on screen says "refresh failed" without replacing it.
 *  Why: docs/BUSINESS-LOGIC.md §36. */
function describeLookupFailure(error) {
  const status = error.response?.status

  if (status === 422) {
    // The server's own sentence, per field — App\Http\Requests\RoutePairRequest.
    failedBody.value = Object.values(error.response.data?.errors ?? {})[0]?.[0] ?? ''
    notFound.value = true

    return
  }

  if (detail.value !== null) {
    const since = lastChecked.value === null ? '' : ` from ${lastChecked.value}`

    refreshNotice.value = status === 429
      ? `Orbit has looked up a lot of routes just now — these are its fares${since}.`
      : `Could not check today’s fares. These are the ones Orbit already had${since}.`

    return
  }

  if (status === 429) {
    failedBody.value = 'Orbit has looked up a lot of routes in the last few minutes. Give it a minute and try again.'
    failed.value = true

    return
  }

  console.error('Could not look this route up.', error)
  failed.value = true
}

/**
 * ⚠ One tap spends one SerpAPI search out of 250 a MONTH. Nothing here is automatic: no watcher, no
 * mounted hook, no retry — it is a tap or it is not.
 */
async function checkLivePrice() {
  if (checkingLive.value || !detail.value?.cheapest) {
    return
  }

  const mine = request

  checkingLive.value = true
  liveError.value = ''

  try {
    const { data } = await http.post(
      `/api/routes/${code.value}/live-price`,
      null,
      { timeout: LIVE_CHECK_TIMEOUT_MS },
    )

    if (mine !== request) {
      return
    }

    adopt(data)
  } catch (error) {
    if (mine !== request) {
      return
    }

    liveError.value = describeLiveFailure(error)
  } finally {
    if (mine === request) {
      checkingLive.value = false
    }
  }
}

/** Prefers the server's own sentence: a 503 could be budget-reserved or an
 *  unreachable Google, and only the server knows which. */
function describeLiveFailure(error) {
  const status = error.response?.status
  const said = error.response?.data?.message

  if (status === 503) {
    return said || 'Orbit could not check this fare just now. This is still its cached price.'
  }

  if (status === 429) {
    return 'That is a lot of live checks in one go. Give it a minute and try again.'
  }

  if (status === 409) {
    return said || 'Orbit has no fare on this route to check.'
  }

  console.error('Could not check the live price.', error)

  return 'Could not reach Google just now. This is still Orbit’s cached price.'
}

/** Take a detail document — from either endpoint, they are the same shape. */
function adopt(payload) {
  detail.value = payload.data
  meta.value = payload.meta ?? null
}

/** Uses the same store write the add form makes, so Home's globe/tour stays
 *  in step — Home stays mounted between navigations, not reloaded.
 *  Why: docs/BUSINESS-LOGIC.md §36. */
async function watchRoute() {
  if (watching.value || detail.value === null) {
    return
  }

  watching.value = true
  watchError.value = ''

  try {
    await watchlist.add(detail.value.origin.iata, detail.value.destination.iata)

    // The server's answer is the row, and the store has it. What changes HERE is only which of the
    // two states this strip is in.
    meta.value = { ...meta.value, watched: true }
    justWatched.value = true
  } catch (failure) {
    watchError.value = failure.response?.status === 422
      ? 'Orbit is already watching this route.'
      : 'Could not add this route to your watch list.'

    console.error('Could not watch a route from its detail screen.', failure)
  } finally {
    watching.value = false
  }
}

watch(code, load, { immediate: true })

/** Checks `history.state.back` first — else a shared-link visitor with no
 *  prior entry gets walked out of the app by router.back().
 *  Why: docs/BUSINESS-LOGIC.md §36. */
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

    <!-- Distinct from the skeleton above: an active provider call (a few seconds),
         not generic loading — worth saying out loud (docs/BUSINESS-LOGIC.md §36). -->
    <div v-else-if="checking && detail === null" class="checking" role="status">
      <span class="checking__spinner" aria-hidden="true"></span>
      <p class="checking__title">Checking current fares…</p>
      <p class="checking__body">
        Orbit has not priced <span class="empty__code">{{ code }}</span> before. This takes a moment.
      </p>
    </div>

    <div v-else-if="notFound" class="empty">
      <h1 class="empty__title">No such route</h1>
      <p class="empty__body">
        Orbit could not look up <span class="empty__code">{{ code }}</span>. Check the code, or pick a route from the
        watchlist.
      </p>
      <!-- The server's own sentence, when there is one — says which HALF of the
           pair (origin vs destination) is the problem. -->
      <p v-if="failedBody" class="empty__why">{{ failedBody }}</p>
      <button class="empty__action" @click="goBack">Go back</button>
    </div>

    <div v-else-if="failed" class="empty">
      <h1 class="empty__title">Could not load this route</h1>
      <p class="empty__body">{{ failedBody || 'The connection dropped, or the server is having a moment.' }}</p>
      <button class="empty__action" @click="load">Try again</button>
    </div>

    <template v-else-if="detail">
      <div class="detail__head">
        <h1 class="detail__code">{{ detail.origin.iata }} → {{ detail.destination.iata }}</h1>
        <p class="detail__where">{{ detail.destination.city }}, {{ detail.destination.country }}</p>
      </div>

      <!-- Watchlist strip: only when NOT watched, between header and price so
           it never competes with the Book button below (docs/BUSINESS-LOGIC.md §36). -->
      <div v-if="unwatched" class="watch">
        <p class="watch__text">Not on your watch list — Orbit is not pricing this every morning.</p>
        <button class="watch__action" type="button" :disabled="watching" @click="watchRoute">
          {{ watching ? 'Adding…' : 'Watch this route' }}
        </button>
      </div>

      <!-- The other half of the strip: answers the button just pressed. A route
           already watched on open gets no strip at all. -->
      <p v-else-if="justWatched" class="watch watch--on">
        On your watch list — Orbit prices it every morning from now on.
      </p>

      <p v-if="watchError" class="detail__notice" role="alert">{{ watchError }}</p>

      <!-- Refresh failed, not the data: fares shown are real, just not today's.
           `role="status"`, not `alert` — the screen still works. -->
      <p v-if="refreshNotice" class="detail__notice detail__notice--quiet" role="status">{{ refreshNotice }}</p>

      <!-- Same fetch, already-populated screen: shown quietly, must not take
           the page over — last week's fares stay readable while it runs. -->
      <p v-if="checking" class="detail__notice detail__notice--quiet" role="status">Checking current fares…</p>

      <div class="price">
        <div>
          <!-- `aria-live` because this number is swapped in place by the button
               below: a screen reader has to be told the headline changed. -->
          <p
            class="price__value tabular"
            :class="{ 'price__value--gone': demoted }"
            aria-live="polite"
          >
            {{ headline === null ? '—' : euro(headline) }}
          </p>

          <!-- Whose number it is: "€150" and "€150, from Google" are different
               claims, and only one of them cost a metered search. -->
          <p v-if="livePrice !== null" class="price__live">Live on Google · checked {{ liveWhen }}</p>

          <!-- Spelled out because this screen's other dates are when we LOOKED —
               the two must never be read for each other. -->
          <p v-if="departure" class="price__when">Cheapest departure · {{ departure }}</p>

          <!-- Replaces the plain "Seen …" line rather than joining it. -->
          <p v-if="demoted" class="price__gone">{{ goneLabel }}</p>
          <!-- Qualifies the departure line; only past a day old (SEEN_AFTER_HOURS).
               Absent, not reassuring, when age is unknown. -->
          <p v-else-if="seen && livePrice === null" class="price__seen">Seen {{ seen }}</p>

          <p v-if="liveTypical" class="price__typical">{{ liveTypical }}</p>

          <p v-if="cachedLine" class="price__cached">{{ cachedLine }}</p>

          <p v-if="caption" class="price__caption">{{ caption }}</p>
        </div>

        <DealScoreGauge :score="detail.score" :confident="detail.confident" />
      </div>

      <!-- Shown only when there's a fare to check; replaced by its answer,
           which the server serves free for six hours — a second tap buys nothing. -->
      <div v-if="detail.cheapest" class="live">
        <button v-if="live === null" class="live__action" type="button" :disabled="checkingLive" @click="checkLivePrice">
          {{ checkingLive ? 'Asking Google…' : 'Check live price' }}
        </button>

        <!-- ⚠ Asked and silent, which confirms NOTHING. -->
        <p v-else-if="livePrice === null" class="live__note">
          Google had no live price for this date. This is still Orbit’s cached fare.
        </p>

        <p v-else class="live__note">Orbit keeps this live answer for a few hours.</p>

        <!-- `status` and not `alert`: nothing is broken. -->
        <p v-if="liveError" class="live__error" role="status">{{ liveError }}</p>
      </div>

      <PriceHistoryChart
        :history="detail.history"
        :median="median"
        :tone="detail.verdict.tone"
        :tracking-days="detail.trackingDays"
      />

      <!-- `confident` is for the GLYPH only, not the words. See AdviceCallout.vue.
           Why: docs/BUSINESS-LOGIC.md §36. -->
      <AdviceCallout
        :title="detail.advice.title"
        :body="detail.advice.body"
        :tone="detail.advice.tone"
        :confident="detail.confident"
      />

      <!-- ⚠ Tone is the SERVER's alone — already accounts for a maybe-gone fare
           and a contradicting live price; add no conditions here (docs/BUSINESS-LOGIC.md §36). -->
      <BookingCta
        :aviasales-url="detail.booking.aviasales"
        :skyscanner-url="detail.booking.skyscanner"
        :variant="detail.advice.tone === 'warn' ? 'secondary' : 'primary'"
      />
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

/* Between the fare and the comparison, and quieter than both: it is the fare's
   own label rather than a second fact about it. */
.price__when {
  margin-top: 7px;
  font-size: var(--text-md);
  font-weight: 600;
  color: var(--accent-ink);
}

/* Quieter than the departure line it qualifies; same muted ink the day sheet
   uses. Rare (fares over a day old), so noticeable when it appears. */
.price__seen {
  margin-top: 3px;
  font-size: var(--text-md);
  color: var(--muted);
}

/* ⚠ A step DOWN the hierarchy, not a decoration. Never struck through —
   Orbit does not know the fare is gone. */
.price__value--gone {
  font-size: 32px;
  color: var(--muted);
}

/* The `good` ink is the palette's "verified" colour, and this is the only
   number on the screen that has been. */
.price__live {
  margin-top: 7px;

  font-size: var(--text-md);
  font-weight: 700;
  color: var(--good-ink);
}

/* A pill rather than another grey line: skipped grey lines are the failure
   this whole feature is fixing. */
.price__gone {
  display: inline-block;
  margin-top: 5px;
  padding: 3px 8px;

  border-radius: var(--radius-chip);
  background: var(--warn-bg);

  font-size: var(--text-md);
  font-weight: 600;
  color: var(--warn-ink);
}

/* Evidence under a live headline, drawn as quietly as the freshness line. */
.price__typical,
.price__cached {
  margin-top: 4px;
  font-size: var(--text-md);
  color: var(--muted);
}

.price__caption {
  margin-top: 6px;
  font-size: var(--text-lg);
  color: var(--ink2);
}

/* The gap above the comparison is already carried by the departure line when
   there is one — or by the freshness line, when that is showing. */
.price__when + .price__caption,
.price__seen + .price__caption {
  margin-top: 3px;
}

/* Outline control: the accent colour belongs to the watch strip and the
   booking button, not this — a question, not the conclusion. */

.live {
  margin: 12px 2px 0;
}

.live__action {
  width: 100%;
  min-height: 42px;
  padding: 10px 14px;

  border: 1px solid var(--line);
  border-radius: var(--radius-chip);
  background: var(--card);

  font-family: var(--font-display);
  font-size: var(--text-lg);
  font-weight: 700;
  color: var(--accent-ink);
}

.live__action:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

/* Quiet: the number above it is the news. */
.live__note {
  font-size: var(--text-md);
  color: var(--muted);
}

/* Quiet colours, not warning ones: nothing broke. */
.live__error {
  margin-top: 8px;
  padding: 9px 11px;

  border: 1px solid var(--line);
  border-radius: var(--radius-chip);

  font-size: var(--text-md);
  color: var(--muted);
}

/* Pushed to the far edge rather than stretching the price block, so a long
   caption wraps under the price instead of shoving the ring off screen. */
.price :deep(.gauge) {
  margin-left: auto;
}

/* A hairline row, not a card — boarding-pass cards on the watch screen
   would make this read as a second route rather than a strip about one. */

.watch {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;

  margin: 12px 2px 0;
  padding: 11px 13px;

  border: 1px solid var(--line);
  border-radius: var(--radius-chip);
  background: var(--card);

  font-size: var(--text-lg);
  color: var(--ink2);
}

.watch__text {
  min-width: 0;
}

/* The only accent-filled control besides the Book button far below, which
   is the conclusion rather than the offer. */
.watch__action {
  flex-shrink: 0;
  padding: 9px 14px;
  border-radius: var(--radius-chip);

  font-family: var(--font-display);
  font-size: var(--text-md);
  font-weight: 700;

  color: var(--on-solid);
  background: var(--accent);
  box-shadow: 0 6px 16px var(--accent-glow);
}

.watch__action:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

/* Once it IS watched: the same box with nothing to press, so the strip does
   not jump or vanish under the thumb that just used it. */
.watch--on {
  color: var(--muted);
  background: transparent;
  border-style: dashed;
}

.detail__notice {
  margin: 12px 2px 0;
  padding: 10px 12px;
  border-radius: var(--radius-chip);

  font-size: var(--text-lg);
  color: var(--warn-ink);
  background: var(--warn-bg);
}

/* Nothing went wrong that the person can act on — the screen still has fares
   on it — so this one keeps the app's own quiet colours. */
.detail__notice--quiet {
  color: var(--muted);
  background: var(--card);
  border: 1px solid var(--line);
}

/* Nothing on screen yet. Centred and short — read once, for two seconds,
   and must not look like an error. */
.checking {
  margin-top: 44px;
  text-align: center;
}

.checking__title {
  margin-top: 16px;

  font-family: var(--font-display);
  font-size: var(--text-xl);
  font-weight: 700;
  color: var(--ink);
}

.checking__body {
  margin: 8px auto 0;
  max-width: 30ch;

  font-size: var(--text-lg);
  color: var(--muted);
}

/* A ring with a quarter of it in the accent colour. `currentColor` for the
   track so the two are the same hue in both themes. */
.checking__spinner {
  display: inline-block;
  width: 26px;
  height: 26px;

  border: 2.5px solid var(--line);
  border-top-color: var(--accent);
  border-radius: 50%;

  animation: checking-spin 0.8s linear infinite;
}

@keyframes checking-spin {
  to {
    transform: rotate(360deg);
  }
}

/* Somebody who has asked their phone not to animate things gets a ring that
   simply sits there — the sentence under it is what carries the meaning. */
@media (prefers-reduced-motion: reduce) {
  .checking__spinner {
    animation: none;
  }
}

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

/* The server's own sentence about which half of the pair was the problem.
   Quieter than the body above: that says what happened, this says why. */
.empty__why {
  margin: 10px auto 0;
  max-width: 32ch;

  font-size: var(--text-md);
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
