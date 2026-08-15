<script setup>
/*
 * =============================================================================
 * Home — the Orbit globe (design/README.md §1)
 * =============================================================================
 * The signature screen: a photoreal Earth that tours the watchlist, a card for
 * whichever route the camera is on, and a rail to jump to another one.
 *
 * `name` IS LOAD-BEARING and must stay 'Home': App.vue's <KeepAlive> matches on
 * it, and everything below — a WebGL context, ~2.5 MB of Earth texture and an
 * eleven-second camera sequence — is precisely what must not be rebuilt every
 * time somebody looks at the calendar and comes back. GlobeStage.vue pauses
 * itself while this screen is cached; see its onDeactivated.
 *
 * ONE REQUEST, EVERY ROUTE. `GET /api/watchlist` carries the arcs, the card and
 * the rail in a single payload (docs/API.md), so the tour never waits on the
 * network between routes.
 *
 * THE LIST IS THE SHARED ONE. This screen fetched the endpoint for itself until
 * the DRY pass, on the grounds that nothing else here needed it; the watch
 * screen writes to the same list, so a route paused there used to stay in this
 * screen's tour until the next reload. That is the moment stores/watchlist.js
 * earned its keep, and this file is now one of its three readers.
 *
 * THE TOUR IS KEYED BY ROUTE CODE, not by index: a reload that returns the
 * routes in a new order (the owner reordered them on another device) leaves the
 * camera where it was rather than cutting to whatever is now third.
 */
import { computed, onActivated, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { nextIndex } from '@/lib/tour'
import GlobeStage from '@/Components/globe/GlobeStage.vue'
import RouteRail from '@/Components/globe/RouteRail.vue'
import SpotlightCard from '@/Components/globe/SpotlightCard.vue'
import { hasWebgl } from '@/Components/globe/globeScene'
import { useWatchlistStore } from '@/stores/watchlist'

defineOptions({ name: 'Home' })

const watchlist = useWatchlistStore()
const { routes, status } = storeToRefs(watchlist)

const loading = computed(() => status.value === 'loading')
const failed = computed(() => status.value === 'failed')

const activeCode = ref(null)

/*
 * Probed once, before anything tries to draw: a browser without WebGL renders
 * the JavaScript perfectly and the globe not at all, which looks like a broken
 * app rather than an unsupported one. It is a ref because the stage can also
 * discover this the hard way — a chunk that will not load, a context lost under
 * memory pressure — and hand the screen the same answer later.
 */
const globeAvailable = ref(hasWebgl())

/** Paused routes are in the payload with `active: false`; the tour skips them. */
const activeRoutes = computed(() => routes.value.filter((route) => route.active))

const activeRoute = computed(
  () => activeRoutes.value.find((route) => route.code === activeCode.value) ?? activeRoutes.value[0],
)

/**
 * "Good morning", by the PHONE's clock.
 *
 * Deliberately the device's local time rather than the owner's configured
 * Europe/Amsterdam: this is a greeting, and it is talking to whoever is
 * holding the phone. Everything with a DATE on it — history, the calendar —
 * uses the server's timezone instead (docs/API.md).
 */
function currentGreeting() {
  const hour = new Date().getHours()

  if (hour < 12) {
    return 'Good morning'
  }

  return hour < 18 ? 'Good afternoon' : 'Good evening'
}

// Read before the first render, not in onMounted: the greeting is the largest
// text on the screen, and starting it empty means the first frame has a hole
// where the heading goes.
const greeting = ref(currentGreeting())

/*
 * The store says what went wrong and to whom (a 401 is handled in lib/http.js,
 * which sends the whole app to the login screen). What is left for this screen
 * is where the camera starts — and, when the list could not be reached, saying
 * so quietly with a way to try again rather than showing an empty planet.
 */
async function load() {
  await watchlist.refresh()

  activeCode.value = activeRoutes.value[0]?.code ?? null
}

/** The tour has finished dwelling on a route: move to the next one. */
function advance() {
  const current = activeRoutes.value.findIndex((route) => route.code === activeCode.value)

  activeCode.value = activeRoutes.value[nextIndex(current, activeRoutes.value.length)]?.code ?? null
}

onMounted(load)

// This screen is cached rather than rebuilt, so it can be hours old when the
// user comes back to it — and "Good morning" at six in the evening is the kind
// of small wrongness that makes an app feel unattended.
onActivated(() => {
  greeting.value = currentGreeting()
})
</script>

<template>
  <div class="home">
    <header class="home__header">
      <div>
        <p class="home__eyebrow">
          <span class="home__live"></span>
          Tracking live
        </p>
        <h1 class="home__greeting">{{ greeting }}</h1>
      </div>

      <!-- design/README.md §1 notes the prototype pointed this at an
           onboarding screen that does not exist. Alerts is the nearest real
           destination: it is where this app's per-person settings live. -->
      <RouterLink class="home__profile" :to="{ name: 'alerts' }" aria-label="Alerts and settings">
        <svg width="19" height="19" viewBox="0 0 20 20" fill="none" aria-hidden="true">
          <circle cx="10" cy="6.5" r="3.2" stroke="var(--ink2)" stroke-width="1.5" />
          <path d="M4 16.5c0-3 2.7-5 6-5s6 2 6 5" stroke="var(--ink2)" stroke-width="1.5" stroke-linecap="round" />
        </svg>
      </RouterLink>
    </header>

    <!-- Loading: the shape of the screen, held still. No spinner — the globe
         takes a moment to fetch its textures and a spinner would be replaced by
         a second thing that also moves. -->
    <div v-if="loading" class="home__skeleton" role="status">
      <div class="home__skeleton-globe"></div>
      <div class="home__skeleton-card"></div>
      <p class="home__quiet">Finding your routes…</p>
    </div>

    <div v-else-if="failed" class="home__notice">
      <h2 class="home__notice-title">Nothing to orbit right now</h2>
      <p class="home__quiet">Your watchlist could not be reached. It is probably a moment's connection.</p>
      <button class="home__retry" type="button" @click="load">Try again</button>
    </div>

    <div v-else-if="activeRoutes.length === 0" class="home__notice">
      <h2 class="home__notice-title">Nothing orbiting yet</h2>
      <p class="home__quiet">
        Add a route on the
        <RouterLink class="home__link" :to="{ name: 'watch' }">Watch</RouterLink>
        tab and the globe will start touring it.
      </p>
    </div>

    <template v-else>
      <template v-if="globeAvailable">
        <GlobeStage
          :routes="activeRoutes"
          :active-code="activeRoute.code"
          @advance="advance"
          @unavailable="globeAvailable = false"
        />

        <div class="home__spotlight">
          <SpotlightCard :route="activeRoute" />
        </div>

        <RouteRail :routes="activeRoutes" :active-code="activeRoute.code" @select="activeCode = $event" />
      </template>

      <!-- No GPU, no globe. The screen is the same information without the
           film: every watched route, as the card the spotlight would have
           shown, each one a link into its detail. -->
      <div v-else class="home__flat">
        <p class="home__quiet">Your browser cannot draw the globe, so here is the whole watchlist instead.</p>

        <SpotlightCard v-for="route in activeRoutes" :key="route.code" :route="route" />
      </div>
    </template>
  </div>
</template>

<style scoped>
.home {
  padding-bottom: 8px;
}

.home__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 4px var(--gutter) 6px;
}

.home__eyebrow {
  display: flex;
  align-items: center;
  gap: 7px;

  font-size: var(--text-sm);
  font-weight: 700;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--muted);
}

.home__live {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--good);
  animation: orbit-pulse 2.2s infinite;
}

.home__greeting {
  margin-top: 3px;
  font-family: var(--font-display);
  font-size: var(--text-2xl);
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--ink);
}

.home__profile {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;

  width: 40px;
  height: 40px;
  border: 1px solid var(--line);
  border-radius: 50%;

  background: var(--card);
  box-shadow: var(--shadow);
}

.home__spotlight {
  /* The overlap that ties the card to the globe (design/README.md §1). */
  position: relative;
  z-index: 4;
  margin: -30px var(--gutter) 0;
}

.home__flat {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 14px var(--gutter) 0;
}

/* --- The three quiet states ---------------------------------------------- */

.home__skeleton {
  padding: 0 var(--gutter);
}

/* The globe's own footprint, held as a soft disc: the screen does not jump when
   the real one arrives. Lit from the same upper-left the Earth texture is. */
.home__skeleton-globe {
  width: min(320px, 86%);
  aspect-ratio: 1;
  margin: 20px auto;
  border-radius: 50%;
  background: radial-gradient(circle at 38% 32%, var(--card2), transparent 72%);
}

.home__skeleton-card {
  height: 108px;
  margin-top: -30px;
  border-radius: var(--radius-card);
  background: var(--card);
  box-shadow: var(--shadow);
}

.home__notice {
  margin: 24px var(--gutter);
  padding: 22px 20px;
  border: 1px solid var(--line);
  border-radius: var(--radius-card);
  background: var(--card);
  box-shadow: var(--shadow);
}

.home__notice-title {
  font-family: var(--font-display);
  font-size: var(--text-xl);
  font-weight: 700;
  color: var(--ink);
  margin-bottom: 6px;
}

.home__quiet {
  font-size: var(--text-lg);
  color: var(--muted);
}

.home__skeleton .home__quiet {
  margin-top: 14px;
  text-align: center;
}

.home__link {
  color: var(--accent-ink);
  font-weight: 600;
}

.home__retry {
  margin-top: 14px;
  padding: 9px 16px;
  border-radius: var(--radius-chip);
  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--ink);
  background: var(--card2);
  border: 1px solid var(--line);
}
</style>
