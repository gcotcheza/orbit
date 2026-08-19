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
 *
 * =============================================================================
 * AND SINCE "LOOK BEFORE YOU WATCH", THIS SCREEN CAN BE ABOUT A ROUTE ORBIT HAS
 * NEVER PRICED
 * =============================================================================
 * The watch form's "Look up" navigates straight here with a pair that may have
 * no route row at all, so this screen owns the fetch: when the read comes back
 * 404, or comes back with fares older than the server's freshness window, it
 * asks `POST /api/routes/lookup` to price the pair NOW and adopts the answer,
 * which arrives in exactly the shape the read does (docs/API.md).
 *
 * THREE RULES IT FOLLOWS, and each of them is a way this could lie:
 *
 *   - A WATCHED ROUTE IS NEVER REFRESHED FROM HERE. It is polled every morning;
 *     stale fares on one are a poll to fix, not a provider call to make from
 *     somebody's phone. `meta.watched` is what that reads.
 *   - THE WAIT IS SHOWN, AND IT IS BOUNDED. "Checking current fares…" while it
 *     runs, an honest failure when it does not — never a spinner that outlives
 *     the request, which is why the POST carries its own timeout.
 *   - WHAT IS ALREADY ON SCREEN SURVIVES A FAILED REFRESH. A month-old price
 *     with a line saying when it was fetched is worth more than an error page
 *     that replaces it.
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

/*
 * The API constrains `{code}` to `[A-Z]{3}-[A-Z]{3}` at the router and answers
 * anything else with a 404 rather than a redirect (docs/API.md). A pasted link
 * in the wrong case is a normal thing for a human to produce, though, so the
 * case is normalised HERE — where it is a display concern — and a shape that
 * still does not match is answered locally instead of by a round trip that can
 * only come back 404.
 */
const CODE_PATTERN = /^[A-Z]{3}-[A-Z]{3}$/

/*
 * HOW LONG THE FETCH IS GIVEN BEFORE THIS SCREEN STOPS WAITING FOR IT.
 *
 * The server's own bound is the fare provider's timeouts — five seconds to
 * connect and fifteen to read, per calendar month of the six-month window,
 * which is minutes if Travelpayouts is hanging rather than answering. Nobody
 * watches a phone for minutes. Twenty-five seconds is several times the two or
 * three a healthy fetch takes and still inside anybody's patience.
 *
 * NOTHING IS LOST BY GIVING UP. The writes behind it are upserts, so a fetch
 * this screen has stopped waiting for still leaves its fares in the database
 * for the next visit — the timeout ends the WAIT, not the work.
 */
const LOOKUP_TIMEOUT_MS = 25_000

/*
 * HOW OLD THE CHEAPEST FARE HAS TO BE BEFORE THIS SCREEN MENTIONS ITS AGE.
 *
 * The day sheet prints "Seen …" under EVERY price, because that sheet is one
 * day and one number and the line is the second thing on it. This screen is a
 * headline fare, a gauge, a chart, a callout and a button, and a "Seen 2 hours
 * ago" on a route polled this morning would be one more grey line on a page
 * that already has several — noise that teaches the reader to skip the place
 * the important version of this message will appear.
 *
 * A DAY, BECAUSE THAT IS THE POLL'S OWN PERIOD (`orbit.poll.window_days` is the
 * window; the schedule is daily, docs/BUSINESS-LOGIC.md §13). Anything under it
 * is the ordinary state of a watched route and says nothing; past it, the fare
 * has survived a morning it should have been repriced in, and that IS worth a
 * line. It is the same 24 hours the server calls a route's fares fresh for
 * (`orbit.lookup.fresh_for_hours`) — deliberately the same number, arrived at
 * from the same fact, though this one is a display threshold and that one
 * decides whether to spend a provider call.
 */
const SEEN_AFTER_HOURS = 24

/*
 * HOW LONG THE LIVE CHECK IS GIVEN.
 *
 * The server's own worst case is longer — SerpAPI's free quota probe and the
 * search itself are 5 s to connect and 20 s to read apiece
 * (`orbit.serpapi.timeout`) — and nobody watches a phone for the better part of
 * a minute. Thirty seconds is several times what a healthy check takes and
 * still inside anybody's patience.
 *
 * AND NOTHING IS LOST BY GIVING UP, exactly as with the lookup above: the
 * server stores the answer it paid for whether or not this screen is still
 * waiting, so the next view shows it. The timeout ends the WAIT, not the work —
 * which matters more here than anywhere else in the app, because the work cost
 * one of 250 searches a month.
 */
const LIVE_CHECK_TIMEOUT_MS = 30_000

const props = defineProps({
  id: { type: String, required: true },
})

const router = useRouter()
const watchlist = useWatchlistStore()

const detail = ref(null)

/** The route's `meta` — `watched` and how old the fares are. Null before the
 *  first answer, and null on any answer that carries none: the two things it
 *  drives both fail closed. */
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

/**
 * This screen is what put the route on the list, just now.
 *
 * A SEPARATE FLAG FROM `meta.watched`, and the difference is the point: a route
 * that was ALREADY watched when this screen opened says nothing at all about it
 * — that screen is exactly the screen it has always been, with no strip and
 * nothing moved. This one is the answer to a button somebody pressed a second
 * ago, and a button that vanishes without a word is a button people press twice.
 */
const justWatched = ref(false)

const code = computed(() => props.id.toUpperCase())

/**
 * Whether to offer the watchlist, and it is `=== false` rather than falsy on
 * purpose: `meta` is null on an answer that carries none, and a screen that
 * cannot tell whether a route is watched must not offer to add it twice.
 */
const unwatched = computed(() => meta.value?.watched === false)

/**
 * The day Orbit last got fares for this route, for the line that admits a
 * refresh did not happen.
 *
 * NOT `departureLabel`, though it would print the same three words. That
 * function names a DAY YOU FLY and this is a day WE LOOKED — the two axes this
 * screen is most careful about (docs/API.md) — and a shared formatter would be
 * the one place the distinction stopped being visible. `fetchedAt` is the one
 * timestamp in the API and it arrives in the owner's own timezone, so the
 * calendar day is its first ten characters: no parsing, and no chance of
 * printing yesterday.
 */
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

// Statistics are null until the provider has some; the chart draws no
// reference line rather than one at zero.
const median = computed(() => detail.value?.stats?.median ?? null)

/**
 * THE DAY THE BIG NUMBER IS FOR.
 *
 * The headline fare had no date on it, and a fare without one is not something
 * anybody can act on: €75 could be this Friday or eleven weeks out, and the two
 * are different answers to "should I book". `cheapest` is a DEPARTURE date
 * (docs/API.md) — the day you fly — which is why it is labelled as one here and
 * why nothing on this screen derives it from `history[].date`, the day we
 * looked. Null before the first poll, and then no line is printed at all.
 */
const departure = computed(() => departureLabel(detail.value?.cheapest?.date ?? null))

/**
 * "Seen 4 days ago" — but only once that is worth saying.
 *
 * A THIRD DATE, AND THIS SCREEN IS ALREADY THE CAREFUL ONE ABOUT THE OTHER TWO.
 * `history[].date` is when we LOOKED and `cheapest.date` is when you FLY;
 * `cheapest.foundAt` is when the PRICE WAS FOUND, which is neither. Orbit's
 * fares come from a cache of other people's searches, so the big number at the
 * top of this screen can be days old — €36 shown against a live €56 is what
 * started this — and the one place that matters most is directly above a button
 * that leaves for a booking site.
 *
 * NULL WHEN THERE IS NOTHING HONEST TO SAY, and that covers three separate
 * cases which all deserve the same silence: no fare yet, no `foundAt` on the
 * one there is (an old row, or a provider that will not say), and a fare young
 * enough that its age is not news. None of them may render as a reassurance.
 */
const seen = computed(() => {
  const foundAt = detail.value?.cheapest?.foundAt ?? null
  const age = hoursSince(foundAt)

  return age === null || age < SEEN_AFTER_HOURS ? null : seenLabel(foundAt)
})

/**
 * ⚠ WHETHER THE HEADLINE FARE HAS PROBABLY ALREADY GONE.
 *
 * THE SERVER'S JUDGEMENT, NOT THIS SCREEN'S. `cheapest.mayBeGone` is true when
 * the fare is over `orbit.live_check.stale_after_hours` old AND at least
 * `under_usual_percent` below what the route usually costs (docs/API.md) — the
 * combination that put DUS→VCE on this screen at €36, "Seen 3 days ago", when
 * the live market was about $150 and nothing was on sale anywhere near it.
 *
 * `=== true` RATHER THAN TRUTHY, like `unwatched` above: an answer from an
 * older build carries no such field, and a screen that cannot tell must draw
 * the ordinary state rather than accuse a fare of being stale.
 *
 * IT IS NOT RECOMPUTED HERE FROM `foundAt` AND `pctBelow`, though both are on
 * this page. Two thresholds in a Vue component is two thresholds to retune, and
 * the one that would go on being applied after the config changed is this one.
 */
const mayBeGone = computed(() => detail.value?.cheapest?.mayBeGone === true)

/** Google's answer, when Orbit has one inside its cooldown (docs/API.md). */
const live = computed(() => meta.value?.liveCheck ?? null)

/**
 * The cheapest seat GOOGLE could find, or null when it had no opinion.
 *
 * NULL IS NOT A FAILURE AND IT IS NOT PERMISSION EITHER. Google publishes its
 * price insights only for routes it has enough history on, so a thin pair comes
 * back checked and silent — in which case this screen says so and the cached
 * fare stays exactly as demoted as it was. What must never happen is a live
 * label over a cached number.
 */
const livePrice = computed(() => live.value?.lowest ?? null)

/** "just now", "2 hours ago" — how long ago Orbit asked. */
const liveWhen = computed(() => (live.value === null ? null : seenLabel(live.value.checkedAt)))

/**
 * THE BIG NUMBER, and the whole point of the feature is which one it is.
 *
 * A live answer takes the headline because it is the only figure on this screen
 * that somebody can act on: it came from Google minutes ago, not from a cache of
 * other people's searches. Orbit's own price does not disappear — it moves down
 * the block into `cachedLine`, with its age on it, where it is context for the
 * live number rather than a competing claim.
 */
const headline = computed(() => livePrice.value ?? detail.value?.price?.current ?? null)

/** The headline is Orbit's own, and it is one of the ones worth doubting. */
const demoted = computed(() => mayBeGone.value && livePrice.value === null)

/**
 * Google could not find the fare Orbit is showing.
 *
 * A STRICT COMPARISON AND NOT A THRESHOLD, deliberately: this is not a judgement
 * about how far apart the two numbers are, it is the fact that the live market
 * is dearer than the price on the page. €36 against a live €150 is the case that
 * started this; €76 against €80 is the same sentence in miniature and deserves
 * the same restraint. The only thing it changes is how loudly the page's last
 * control speaks — see the note on the hand-off.
 */
const liveIsDearer = computed(() => {
  const cached = detail.value?.price?.current ?? null

  return livePrice.value !== null && cached !== null && livePrice.value > cached
})

/**
 * The plain-language version of the demotion, next to the number it is about.
 *
 * "SEEN 3 DAYS AGO — MAY BE GONE" rather than a warning triangle or a tooltip.
 * The reader is standing in front of a fare deciding whether to go and buy it,
 * and the sentence has to say the two things that decide it: how old the price
 * is, and that it might not be there. The age is always known here — the
 * server's rule requires a `foundAt` to demote at all — and the fallback exists
 * so that a demotion can never render as styling with no explanation.
 */
const goneLabel = computed(() =>
  seen.value === null ? 'This fare may already be gone' : `Seen ${seen.value} — may be gone`,
)

/**
 * Orbit's own fare, once Google's has taken the headline.
 *
 * IT IS KEPT, AND IT IS KEPT HONEST. Hiding the cached price the moment a live
 * one arrives would leave the reader unable to see the disagreement they just
 * paid a metered search to find out about — "Orbit said €36, Google says €150"
 * is the whole answer, and half of it is this line.
 */
const cachedLine = computed(() => {
  const cached = euro(detail.value?.price?.current ?? null)

  if (livePrice.value === null || cached === null) {
    return null
  }

  return seen.value === null
    ? `Orbit’s cached fare ${cached}`
    : `Orbit’s cached fare ${cached}, seen ${seen.value}`
})

/**
 * What Google says this route normally goes for.
 *
 * A SECOND "USUAL" ON A SCREEN THAT ALREADY HAS ONE, deliberately: Orbit's is
 * computed from the fares it has watched, Google's from a market Orbit cannot
 * see. A reader deciding whether €36 was ever real is better served by both
 * than by either. Absent when Google published no band, which is common.
 */
const liveTypical = computed(() => {
  const low = euro(live.value?.typicalLow ?? null)
  const high = euro(live.value?.typicalHigh ?? null)

  return low === null || high === null ? null : `Google’s typical ${low}–${high}`
})

/**
 * The line under the price.
 *
 * `pctBelow` IS SIGNED: −14 means fourteen percent ABOVE the usual price
 * (docs/API.md), so the sentence flips rather than printing "−14% below".
 *
 * SILENT UNDER A LIVE HEADLINE. This sentence is Orbit's opinion of Orbit's
 * cached fare, and printed under Google's number it would read as an opinion
 * about that one — "42% below its usual €62" under a live €150. When there is a
 * live price the cached fare states itself, once, in `cachedLine`.
 */
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

  /*
   * NOT CONFIDENT, SO NO PERCENTAGE — and this is the same honesty rule the
   * gauge already follows one element to the right.
   *
   * `confident: false` means Orbit is not expressing an opinion (docs/API.md):
   * either it has no statistics, or it has under a week of this route's own
   * prices, in which case the statistics are computed from a handful of fares
   * it collected itself and the current price IS the median. "36% below its
   * usual €99" stated in bold under a dash-filled gauge is that placeholder
   * arithmetic read out as a finding — the screen drawing a confident number
   * while the ring beside it says it has nothing to say. The usual price is
   * still shown, because it is a fact; what is dropped is the comparison drawn
   * from it.
   */
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

// The last request wins, not the last response: navigating detail → detail
// keeps this component mounted and only changes the prop.
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

    /*
     * STALE, AND NOBODY IS POLLING IT. A route on the watchlist is priced every
     * morning, so old fares there are a broken poll rather than something a
     * screen should spend provider calls fixing; a route somebody looked up in
     * March and came back to today has nothing else that will ever refresh it.
     */
    if (!meta.value?.fares?.fresh && unwatched.value) {
      await lookUp(mine)
    }
  } catch (error) {
    if (mine !== request) {
      return
    }

    /*
     * A 404 IS NOT A DEAD END ANY MORE. Orbit has no route row for this pair —
     * which is the ordinary state of a pair somebody has just typed into the
     * watch screen's box — so the next question is whether it can price it,
     * and that is what the lookup asks. An invalid pair (an origin Orbit does
     * not fly from, an airport it has never heard of) is refused there, with a
     * sentence.
     */
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

/**
 * Ask Orbit to price this pair now.
 *
 * @param {number} mine the request token this belongs to — see `load`
 */
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

/**
 * What went wrong, in the place the person is looking.
 *
 * THE ORDER OF THESE BRANCHES IS THE JUDGEMENT. A 422 means the pair itself is
 * not a route Orbit can price, which is the "no such route" answer however it
 * arrived. Anything else, when there is already a price on screen, leaves that
 * price alone and says the refresh failed — replacing readable fares from last
 * week with an error page is a screen punishing somebody for revisiting it.
 */
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
 * Go and ask Google what this route costs right now.
 *
 * =============================================================================
 * THE ONE BUTTON IN THIS APP THAT SPENDS A THIRD-PARTY BUDGET ON PURPOSE
 * =============================================================================
 * One tap is at most one SerpAPI search out of a free plan's 250 A MONTH, so
 * everything about this function is arranged so that a person cannot spend it
 * twice by accident: the button is disabled while the request is in flight, it
 * is not drawn at all once an answer exists (the server serves the same one for
 * `orbit.live_check.cooldown_hours` anyway, free), and there is no automatic
 * path to here — no watcher, no mounted hook, no retry. It is a tap or it does
 * not happen.
 *
 * IT ADOPTS A WHOLE DETAIL DOCUMENT, exactly as the lookup does, because the
 * server answers with one (App\Http\Controllers\RouteController). The headline
 * swaps because the data under it changed, not because this function reached
 * into the screen.
 *
 * THE REQUEST TOKEN IS READ, NOT ISSUED. `load()` owns the counter; this is a
 * second request against the document that is already on screen, so it takes
 * the token as it stands and drops its own answer if a navigation has happened
 * since — the same last-request-wins rule the lookup follows.
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

/**
 * Why there is no live answer, said in a way that does not sound like a fault.
 *
 * THE 503 IS THE IMPORTANT ONE AND IT IS NOT AN ERROR. It means the SerpAPI
 * quota is at or below the reserve — fifty searches Orbit will not spend on a
 * screen, because a future alert may need them (config/orbit.php, `serpapi`) —
 * or that this box has no key at all, which is the DEFAULT state of the app.
 * Neither is something the reader did or can fix, so the sentence says what is
 * true: the check is being held back, and the price they are looking at is
 * still Orbit's cached one. The server sends its own wording; it is preferred
 * over anything hard-coded here for the same reason the lookup prefers it.
 *
 * NOTHING HERE TOUCHES THE PRICE ON SCREEN. A failed check leaves the cached
 * fare exactly as it was — demoted if it was demoted — because the alternative
 * is a screen that punishes somebody for asking a question.
 */
function describeLiveFailure(error) {
  const status = error.response?.status
  const said = error.response?.data?.message

  if (status === 503) {
    return said || 'Orbit is holding its remaining live checks in reserve. This is still its cached price.'
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

/**
 * Start watching the route being shown.
 *
 * THE SAME WRITE THE ADD FORM MAKES, through the same store, which is what
 * keeps the globe's tour and the watch list in step with a route added from
 * here — Home is kept alive between navigations and would otherwise not learn
 * about it until a reload.
 */
async function watchRoute() {
  if (watching.value || detail.value === null) {
    return
  }

  watching.value = true
  watchError.value = ''

  try {
    await watchlist.add(detail.value.origin.iata, detail.value.destination.iata)

    // The server's answer is the row, and the store has it. What changes HERE
    // is only which of the two states this strip is in.
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

    <!--
      A ROUTE ORBIT IS PRICING RIGHT NOW, with nothing yet to draw underneath.
      Not the skeleton above it: a skeleton says "this is arriving", and what is
      actually happening is a fare provider being asked six or seven questions
      about six months of departures. That takes a second or three and is worth
      saying out loud, because the alternative — a pulsing grey page — is
      indistinguishable from a broken screen.

      `role="status"`: it is progress, not an alarm.
    -->
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
      <!-- The server's own sentence when there is one — "Orbit does not know an
           airport with that code", "Orbit only tracks departures from AMS, EIN
           or DUS" — which says which HALF of the pair is the problem. -->
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

      <!--
        =====================================================================
        THE WATCHLIST STRIP — only ever on a route that is NOT watched
        =====================================================================
        A watched route's detail screen is exactly the screen it always was:
        no strip, nothing moved, nothing to explain. This is what a route
        somebody has just LOOKED UP gets instead — the one thing there is to
        decide about it, in the place they are deciding it, rather than a
        trip back to the watch screen to type the pair in a second time.

        UNDER THE HEADER AND ABOVE THE PRICE, deliberately. It is a fact about
        the ROUTE ("Orbit is not tracking this"), not about the deal, and it
        must not compete with the Book button at the bottom of the page — the
        one conclusion this screen is allowed to have.
      -->
      <div v-if="unwatched" class="watch">
        <p class="watch__text">Not on your watch list — Orbit is not pricing this every morning.</p>
        <button class="watch__action" type="button" :disabled="watching" @click="watchRoute">
          {{ watching ? 'Adding…' : 'Watch this route' }}
        </button>
      </div>

      <!-- The other half of the same strip: the answer to the button that was
           just here, and ONLY that. A route that was already watched when this
           screen opened gets no strip at all. -->
      <p v-else-if="justWatched" class="watch watch--on">
        On your watch list — Orbit prices it every morning from now on.
      </p>

      <p v-if="watchError" class="detail__notice" role="alert">{{ watchError }}</p>

      <!--
        A REFRESH THAT DID NOT HAPPEN, over prices that did. The fares below
        are real and were real when they were fetched; what this says is that
        they are not today's and that Orbit knows it. `role="status"` — the
        screen still works, so this is not an alert.
      -->
      <p v-if="refreshNotice" class="detail__notice detail__notice--quiet" role="status">{{ refreshNotice }}</p>

      <!-- And the same fetch while it is still running, on a screen that
           already has something to show. It cannot take the page over: what is
           underneath is readable and last week's fares are worth reading while
           this week's arrive. -->
      <p v-if="checking" class="detail__notice detail__notice--quiet" role="status">Checking current fares…</p>

      <div class="price">
        <div>
          <!--
            THE BIG NUMBER, AND WHICH NUMBER IT IS, IS THE FEATURE.
            `headline` is Google's live figure when there is one and Orbit's
            cached fare otherwise; `price--gone` is the demotion — a fare over
            two days old and well under its usual price, drawn quieter than
            the app's most confident element because it is the app's least
            certain claim. See the script, and config/orbit.php `live_check`.
          -->
          <p class="price__value tabular" :class="{ 'price__value--gone': demoted }">
            {{ headline === null ? '—' : euro(headline) }}
          </p>

          <!-- WHOSE NUMBER IT IS, directly under it, because "€150" and "€150,
               from Google, a minute ago" are different claims and only one of
               them is worth having spent a metered search on. -->
          <p v-if="livePrice !== null" class="price__live">Live on Google · checked {{ liveWhen }}</p>

          <!-- "Cheapest departure", spelled out, because this screen's other
               dates are the days we LOOKED and the two must never be read for
               each other. -->
          <p v-if="departure" class="price__when">Cheapest departure · {{ departure }}</p>

          <!-- THE DEMOTION'S OWN SENTENCE. It replaces the plain "Seen …" line
               rather than joining it: two grey lines about the same fare's age
               is how a page teaches people to skip both. -->
          <p v-if="demoted" class="price__gone">{{ goneLabel }}</p>
          <!-- Next to the departure line because it is a qualifier on THAT
               fare, and only past a day old — see SEEN_AFTER_HOURS. Absent
               rather than reassuring when the age is unknown. -->
          <p v-else-if="seen && livePrice === null" class="price__seen">Seen {{ seen }}</p>

          <p v-if="liveTypical" class="price__typical">{{ liveTypical }}</p>

          <!-- Orbit's own fare, once Google's has the headline: the other half
               of a disagreement somebody paid a search to find out about. -->
          <p v-if="cachedLine" class="price__cached">{{ cachedLine }}</p>

          <p v-if="caption" class="price__caption">{{ caption }}</p>
        </div>

        <DealScoreGauge :score="detail.score" :confident="detail.confident" />
      </div>

      <!--
        =====================================================================
        "CHECK LIVE PRICE" — the way out of a cache that may be days old
        =====================================================================
        Under the price and above everything else, because it is about THAT
        number and nothing further down the page. It is not drawn at all
        without a fare to check, and it is replaced by its own answer once
        there is one — the server serves that answer free for six hours
        (`orbit.live_check.cooldown_hours`), so a second tap would buy nothing
        and a button offering it would be a lie about what it costs.

        AN OUTLINE CONTROL, NOT AN ACCENT ONE. The accent belongs to the two
        things this screen concludes with — the watch strip and the booking
        hand-off — and a second glowing button beside a fare would read as the
        thing to press. This is a question, not the conclusion.
      -->
      <div v-if="detail.cheapest" class="live">
        <button v-if="live === null" class="live__action" type="button" :disabled="checkingLive" @click="checkLivePrice">
          {{ checkingLive ? 'Asking Google…' : 'Check live price' }}
        </button>

        <!-- Google was asked and had nothing to say — a real answer on a thin
             route, and one that confirms NOTHING. The cached fare stays exactly
             as demoted as it was. -->
        <p v-else-if="livePrice === null" class="live__note">
          Google had no live price for this date. This is still Orbit’s cached fare.
        </p>

        <p v-else class="live__note">Orbit keeps this live answer for a few hours.</p>

        <!-- `role="status"` and not `alert`: nothing is broken, and the price
             above is exactly what it was before the question was asked. -->
        <p v-if="liveError" class="live__error" role="status">{{ liveError }}</p>
      </div>

      <PriceHistoryChart
        :history="detail.history"
        :median="median"
        :tone="detail.verdict.tone"
        :tracking-days="detail.trackingDays"
      />

      <!-- `confident` is passed for the GLYPH and not for the words: the
           sentence already says "not enough data yet", and the tick that used to
           sit beside it said the opposite. See AdviceCallout.vue. -->
      <AdviceCallout
        :title="detail.advice.title"
        :body="detail.advice.body"
        :tone="detail.advice.tone"
        :confident="detail.confident"
      />

      <!-- A CALLOUT THAT SAYS "WAIT" ABOVE A BUTTON THAT SHOUTS "BOOK" is the
           app arguing with itself, and the button wins because it is the
           loudest thing on the screen. When the advice is a warning the
           hand-off goes quiet — still there, still one tap, no longer the
           conclusion of the page. Read off `advice.tone`, which is
           `verdict.tone` (docs/API.md), so the button cannot disagree with the
           sentence above it. -->
      <!-- AVIASALES IS THE PRIMARY HAND-OFF and Skyscanner the second opinion:
           Orbit's prices come out of Aviasales' cache, and pointing at a
           different meta-search is how this screen came to show €29 for a fare
           Skyscanner listed at €68. See App\Application\Routes\BookingLink. -->
      <!-- AND THE SAME RULE FOR A FARE THAT MAY BE GONE. A demoted headline
           over a glowing "See this fare" is the page arguing with itself in the
           other direction: it has just said the price above might not exist,
           and the loudest thing on the screen would be sending somebody off to
           buy it. The hand-off stays — one tap, same place, and the booking
           site is precisely where the truth is — it simply stops being the
           conclusion.

           AND ONCE GOOGLE HAS SAID THE FARE IS DEARER THAN ORBIT THINKS, the
           same applies with evidence behind it rather than a suspicion: the
           callout above is still reasoning about the cached number, and putting
           the accent under it would be the page's loudest element endorsing the
           one figure the screen has just disproved. -->
      <BookingCta
        :aviasales-url="detail.booking.aviasales"
        :skyscanner-url="detail.booking.skyscanner"
        :variant="detail.advice.tone === 'warn' || demoted || liveIsDearer ? 'secondary' : 'primary'"
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

/* Quieter than the departure line it qualifies, and the same muted ink the day
   sheet gives the same sentence. It appears only on fares over a day old, so it
   is a line the reader meets rarely and should notice when they do. */
.price__seen {
  margin-top: 3px;
  font-size: var(--text-md);
  color: var(--muted);
}

/*
 * ⚠ THE DEMOTION, AND IT IS DELIBERATELY A STEP DOWN IN THE HIERARCHY RATHER
 * THAN A DECORATION ON THE SAME NUMBER.
 *
 * The 42 px display face is the loudest thing on the screen and it is what a
 * reader takes away — the small print under it was already true and was already
 * being skipped. So a fare that is over two days old AND well under its usual
 * price is drawn at 32 px in the muted ink instead: still perfectly legible,
 * still the price, no longer the app's most confident statement. The label
 * below says why, in words.
 *
 * NOT STRUCK THROUGH AND NOT GREYED TO ILLEGIBILITY. Orbit does not know that
 * this fare is gone — it knows the fare is the kind that usually is — and a
 * strike-through would be a claim it cannot support in the other direction.
 */
.price__value--gone {
  font-size: 32px;
  color: var(--muted);
}

/* WHOSE PRICE THE HEADLINE IS, when it is not Orbit's. The `good` ink is the
   palette's "this has been verified" colour and this is the only place on the
   screen where a number has been. */
.price__live {
  margin-top: 7px;

  font-size: var(--text-md);
  font-weight: 700;
  color: var(--good-ink);
}

/* The demotion's sentence: a small pill rather than another grey line, because
   the whole failure it is fixing is a reader skipping the grey lines. It
   replaces `.price__seen` rather than joining it. */
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

/* Google's own band, and Orbit's own fare, under a live headline. Both are
   evidence rather than claims, and they are drawn as quietly as the freshness
   line they sit with. */
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

/* --- "Check live price" -------------------------------------------------
   Directly under the price it is about, and drawn as an OUTLINE control. The
   accent on this screen belongs to the two things it concludes with — the
   watch strip at the top and the booking hand-off at the bottom — and a third
   filled button next to a fare would read as the thing to press. This is a
   question. */

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

/* The answer's own line — "Orbit keeps this live answer for a few hours", or
   Google having had nothing to say. Quiet: the number above it is the news. */
.live__note {
  font-size: var(--text-md);
  color: var(--muted);
}

/* A check that could not be made, over a price that is still perfectly
   readable. The app's quiet colours rather than the warning ones: nothing
   broke, and the reader is not being asked to do anything. */
.live__error {
  margin-top: 8px;
  padding: 9px 11px;

  border: 1px solid var(--line);
  border-radius: var(--radius-chip);

  font-size: var(--text-md);
  color: var(--muted);
}

/* The gauge is pushed to the far edge rather than the price block being
   stretched, so a long caption wraps under the price instead of shoving the
   ring off the screen. */
.price :deep(.gauge) {
  margin-left: auto;
}

/* --- The watchlist strip ------------------------------------------------
   A hairline row rather than a card: the boarding passes on the watch screen
   are cards, and a card here would read as a second route on a screen about
   one. It sits between the header and the price, so it is passed on the way
   down the page rather than being the page. */

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

/* The accent, and the only accent-filled control on this screen apart from
   the Book button at the very bottom — which is 400 px further down and is
   the conclusion rather than the offer. */
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

/* --- States ----------------------------------------------------------- */

/* Orbit asking the provider, with nothing on screen yet. Centred and short:
   it is a sentence somebody reads once, for two seconds, and it must not look
   like an error while it does. */
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

/* The server's own sentence about WHICH half of the pair it could not make
   sense of. Quieter than the body above it: that one says what happened, this
   one says why, and only somebody who wants the why reads on. */
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
