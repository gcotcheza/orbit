<script setup>
/*
 * "When is it cheap?" — one route, one month, a fare per day
 * (design/README.md §3).
 *
 * TWO DATE AXES LIVE IN THIS APP AND THIS SCREEN IS THE OTHER ONE. The route
 * detail's chart is dated by when we LOOKED; every date here is a date you
 * FLY (docs/API.md). Nothing on this screen is derived from an observation
 * date, which is why the calendar endpoint is the only one it reads.
 *
 * THE ROUTE CHIPS READ THE SHARED LIST. This screen fetched `/api/watchlist`
 * for itself while the store that now holds it was being written in a parallel
 * worktree; the swap was flagged for the DRY pass and is this file's part of
 * it. The chips are the only thing here that wants the watchlist — every fare
 * on the screen comes from the calendar endpoint below.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { http } from '@/lib/http'
import DaySheet from '@/Components/calendar/DaySheet.vue'
import HeatLegend from '@/Components/calendar/HeatLegend.vue'
import MonthGrid from '@/Components/calendar/MonthGrid.vue'
import MonthNav from '@/Components/calendar/MonthNav.vue'
import RouteChips from '@/Components/calendar/RouteChips.vue'
import { euro } from '@/lib/format'
import { addMonths, currentMonthKey, dayLabel, monthLabel } from '@/Components/calendar/month'
import { useWatchlistStore } from '@/stores/watchlist'

/*
 * How far the arrows go. The poller holds about six months of departures
 * (`orbit.poll.window_days`, 181 days), so anything past that is a guaranteed
 * empty grid: the bounds are the honest edge of what we know rather than an
 * arbitrary limit. The past is not offered at all — a fare you can no longer
 * buy is not a deal.
 *
 * SIX AND NOT SEVEN, even though 181 days usually reaches into a seventh
 * calendar month: that month holds a handful of days at most, and an arrow that
 * leads to two priced cells is a worse answer than one that stops.
 *
 * THE LAST MONTH IS SOMETIMES EMPTY, and on purpose. A window that opens on the
 * 1st of a short month closes inside the sixth one, so on a few mornings a year
 * the last reachable grid has nothing in it — and the screen already says so
 * ("No fares seen for this month yet"), because that is also what every month
 * of a brand-new route looks like. Stopping at five to guarantee a full grid
 * would hide a month of real fares on the other 95% of days.
 */
const FIRST_MONTH = currentMonthKey()
const LAST_MONTH = addMonths(FIRST_MONTH, 6)

const watchlist = useWatchlistStore()
const { routes, status: routesStatus } = storeToRefs(watchlist)

const code = ref(null)
const month = ref(FIRST_MONTH)

const payload = ref(null)
const loading = ref(true)
const monthFailed = ref(false)
const selected = ref(null)

/*
 * The day sheet's two hand-off templates — `{ aviasales, skyscanner }`, each
 * with a named date hole in it, exactly as the endpoint's `meta` sent them
 * (docs/API.md). Read here rather than in the sheet because the sheet is handed
 * a fare and not a response — and kept as the STRINGS THE SERVER SENT rather
 * than rebuilt from the route code, because the hosts, the path shapes and the
 * affiliate marker live in config/orbit.php and are not this screen's business.
 */
const booking = ref(null)

/** The watchlist has answered, one way or the other, and the screen can speak. */
const booted = computed(() => routesStatus.value !== 'loading')

/*
 * One "could not load" for the two requests this screen makes. They fail the
 * same way and the message covers both — and a screen that could not get the
 * watchlist has no route to ask the calendar endpoint about anyway.
 */
const failed = computed(() => monthFailed.value || routesStatus.value === 'failed')

const canPrev = computed(() => month.value > FIRST_MONTH)
const canNext = computed(() => month.value < LAST_MONTH)

// `min` is null for a month we hold no fares for, which is a 200 and not an
// error (docs/API.md) — it is what the arrows walk into at the edge of the
// poll window, and what a brand-new route looks like everywhere.
const hasFares = computed(() => payload.value?.min != null && payload.value?.max != null)

/*
 * The last request wins, not the last response.
 *
 * Tapping through four route chips fires four requests, and without this the
 * grid shows whichever the network happened to finish last. The token is
 * captured per call and compared before anything is written.
 */
let request = 0

/**
 * The month to OPEN a route on: the one its cheapest departure is in.
 *
 * THE SCREEN USED TO OPEN ON TODAY, ALWAYS, and that is a wrong answer often
 * enough to be the screen's biggest defect. "When is it cheap?" was answered
 * with the current month — which the poll window only half covers, because the
 * days before today are gone — while the route's actual cheapest day sat two
 * taps away in September, unmentioned. The banner at the foot of the grid says
 * "cheapest THIS month"; nothing said which month to be in.
 *
 * `cheapest.date` is a DEPARTURE date and the summary now carries it for every
 * route (docs/API.md), so this is a lookup rather than a request.
 *
 * CLAMPED INTO THE WINDOW THE ARROWS CAN REACH. A landing month outside
 * FIRST_MONTH..LAST_MONTH would be a grid with both arrows pointing back the
 * way it came, or one the `canPrev`/`canNext` bounds disagree with. The clamp
 * is written against those two constants rather than against a number, so it
 * follows the window when the window changes.
 */
function monthFor(routeCode) {
  const date = routes.value.find((route) => route.code === routeCode)?.cheapest?.date ?? null

  // No fare seen yet: the month we are in is the honest place to start.
  if (date === null) {
    return FIRST_MONTH
  }

  const key = date.slice(0, 7)

  if (key < FIRST_MONTH) {
    return FIRST_MONTH
  }

  return key > LAST_MONTH ? LAST_MONTH : key
}

/**
 * Pick a route, and land on the month worth looking at for it.
 *
 * Both refs are written in the same tick, so the watcher below fires once and
 * one request goes out — not one for the route and a second for the month.
 */
function select(next) {
  code.value = next
  month.value = next === null ? FIRST_MONTH : monthFor(next)
}

async function loadRoutes() {
  await watchlist.refresh()

  // The first chip, and the month its cheapest day is in.
  select(routes.value[0]?.code ?? null)

  // Nothing to ask the calendar endpoint about — either the list is empty or
  // it could not be fetched — so the load that would have cleared this flag is
  // never made.
  if (code.value === null) {
    loading.value = false
  }
}

async function loadMonth() {
  if (code.value === null) {
    return
  }

  const mine = (request += 1)

  loading.value = true
  monthFailed.value = false
  // The sheet belongs to the month that is on its way out.
  selected.value = null

  try {
    const { data } = await http.get(`/api/routes/${code.value}/calendar`, {
      params: { month: month.value },
    })

    if (mine !== request) {
      return
    }

    payload.value = data.data
    // Inside the same guard as `payload`, so a late response cannot leave the
    // sheet booking one route's days against another route's link.
    booking.value = data.meta?.booking ?? null
  } catch (error) {
    if (mine !== request) {
      return
    }

    console.error('Could not load the calendar.', error)
    monthFailed.value = true
    payload.value = null
    booking.value = null
  } finally {
    if (mine === request) {
      loading.value = false
    }
  }
}

// Fires on the code the watchlist load sets, so the first month is fetched by
// the same path every later one is.
watch([code, month], loadMonth)

onMounted(loadRoutes)
</script>

<template>
  <section class="calendar rise-in">
    <header class="calendar__head">
      <div>
        <h1 class="calendar__title">When is it cheap?</h1>
        <p class="calendar__subtitle">Cheapest fare per day · {{ monthLabel(month) }}</p>
      </div>

      <MonthNav
        :month="month"
        :can-prev="canPrev"
        :can-next="canNext"
        @prev="month = addMonths(month, -1)"
        @next="month = addMonths(month, 1)"
      />
    </header>

    <RouteChips v-if="routes.length" :routes="routes" :active="code" @pick="select" />

    <p v-if="booted && !routes.length && !failed" class="calendar__note">
      Nothing on your watchlist yet — add a route and its cheapest days show up here.
    </p>

    <p v-else-if="failed" class="calendar__note">
      Could not load this month. Check the connection and try again.
    </p>

    <div v-else-if="loading" class="skeleton" aria-hidden="true"></div>

    <template v-else-if="payload">
      <MonthGrid
        :month="month"
        :days="payload.days"
        :min="payload.min"
        :max="payload.max"
        @pick="selected = $event"
      />

      <HeatLegend v-if="hasFares" :min="payload.min" :max="payload.max" />

      <p v-else class="calendar__note calendar__note--centred">No fares seen for this month yet.</p>

      <p v-if="payload.cheapest" class="banner">
        <svg class="banner__star" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">
          <path d="M8 1.5l1.8 4 4.4.4-3.3 2.9 1 4.3L8 11l-3.9 2.1 1-4.3L1.8 5.9l4.4-.4z" />
        </svg>
        Cheapest this month: {{ dayLabel(payload.cheapest.date) }} · {{ euro(payload.cheapest.price) }}
      </p>
    </template>

    <!-- TELEPORTED, and not for tidiness. This screen's root carries `rise-in`,
         and an element with a transform is the containing block for its
         fixed-position descendants — so a sheet rendered in place would be
         pinned to the scrolling column rather than to the viewport for as long
         as that animation is live. Out at the body it is simply fixed. -->
    <Teleport to="body">
      <DaySheet
        v-if="selected && hasFares"
        :fare="selected"
        :min="payload.min"
        :max="payload.max"
        :code="code"
        :booking="booking"
        @close="selected = null"
      />
    </Teleport>
  </section>
</template>

<style scoped>
.calendar {
  padding: 4px var(--gutter) 24px;
}

.calendar__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin: 8px 2px 4px;
}

.calendar__title {
  font-family: var(--font-display);
  font-size: 25px;
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--ink);
}

.calendar__subtitle {
  margin-top: 2px;
  font-size: var(--text-lg);
  color: var(--muted);
}

.calendar__note {
  margin-top: 20px;
  font-size: var(--text-lg);
  color: var(--muted);
}

.calendar__note--centred {
  margin-top: 16px;
  text-align: center;
}

/* Sized to the grid card it stands in for, so the screen does not jump when
   the month lands. */
.skeleton {
  height: 320px;
  border-radius: var(--radius-card);
  background: var(--card);
  border: 1px solid var(--line);
  opacity: 0.55;
  animation: skeleton-breathe 1.4s ease-in-out infinite;
}

@keyframes skeleton-breathe {
  50% {
    opacity: 0.28;
  }
}

.banner {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 14px;
  padding: 12px 14px;
  border-radius: 14px;

  font-size: var(--text-lg);
  font-weight: 600;

  background: var(--good-bg);
  color: var(--good-ink);
}

/* The fill is set HERE and not as a `fill="var(--good)"` attribute: var() is a
   CSS value, and a presentation attribute that carries one is honoured by some
   browsers and dropped as invalid by others — which would paint the star
   black. Every tokenised SVG colour in this branch goes through CSS. */
.banner__star {
  flex-shrink: 0;
  fill: var(--good);
}
</style>
