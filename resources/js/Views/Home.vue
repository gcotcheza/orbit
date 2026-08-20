<script setup>
/*
 * Home — the Orbit globe (design/README.md §1).
 *
 * The signature screen: a photoreal Earth that tours the watchlist, a card for
 * whichever route the camera is on, and a rail to jump to another one.
 *
 * `name` must stay 'Home' — App.vue's <KeepAlive> matches on it to avoid rebuilding the WebGL scene.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * One request, every route — GET /api/watchlist carries arcs, card and rail
 * together (docs/API.md), so the tour never waits on the network.
 *
 * The list is shared (stores/watchlist.js), not fetched here — a paused route must not linger in this screen's tour.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * Keyed by route code, not index, so a reorder from another device doesn't
 * cut the camera to a different route.
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
 * Probed once — WebGL-less renders fine except the globe, which looks broken
 * rather than unsupported. A ref because GlobeStage can discover this later too.
 */
const globeAvailable = ref(hasWebgl())

/** Paused routes are in the payload with `active: false`; the tour skips them. */
const activeRoutes = computed(() => routes.value.filter((route) => route.active))

const activeRoute = computed(
  () => activeRoutes.value.find((route) => route.code === activeCode.value) ?? activeRoutes.value[0],
)

/**
 * "Good morning", by the phone's clock.
 *
 * Deliberately local time, not the owner's configured timezone — a greeting talks to whoever holds the phone.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
function currentGreeting() {
  const hour = new Date().getHours()

  if (hour < 12) {
    return 'Good morning'
  }

  return hour < 18 ? 'Good afternoon' : 'Good evening'
}

// Read before the first render, not in onMounted — the greeting is the
// largest text on the screen, so starting empty leaves a hole in frame one.
const greeting = ref(currentGreeting())

/*
 * A 401 is handled in lib/http.js (redirects to login); this screen only
 * decides where the camera starts and how to show "could not be reached".
 * Why: docs/BUSINESS-LOGIC.md §36.
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

// This screen is cached, not rebuilt, so it can be hours old — a stale
// "Good morning" at 6pm is a small wrongness that makes the app feel unattended.
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

      <!-- Alerts is the nearest real destination for this icon; the hash lands
           on #account, not the top of that screen (docs/BUSINESS-LOGIC.md §36). -->
      <RouterLink
        class="home__profile"
        :to="{ name: 'alerts', hash: '#account' }"
        aria-label="Your account and alert settings"
      >
        <svg width="19" height="19" viewBox="0 0 20 20" fill="none" aria-hidden="true">
          <circle cx="10" cy="6.5" r="3.2" stroke="var(--ink2)" stroke-width="1.5" />
          <path d="M4 16.5c0-3 2.7-5 6-5s6 2 6 5" stroke="var(--ink2)" stroke-width="1.5" stroke-linecap="round" />
        </svg>
      </RouterLink>
    </header>

    <!-- Loading: the screen's shape, held still — no spinner, since the globe
         itself is already the thing that moves once it arrives. -->
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

    <!-- Day one: the empty globe still draws, no placeholder card — it costs
         textures but no camera work (docs/BUSINESS-LOGIC.md §36). -->
    <div v-else-if="activeRoutes.length === 0" class="home__day1">
      <GlobeStage
        v-if="globeAvailable"
        class="home__day1-globe"
        :routes="[]"
        active-code=""
        @unavailable="globeAvailable = false"
      />

      <div class="home__notice" :class="{ 'home__notice--over': globeAvailable }">
        <h2 class="home__notice-title">Nothing orbiting yet</h2>
        <p class="home__quiet">Add a route and the globe starts touring it — Orbit prices it every morning.</p>

        <!-- A button, not a link inside a sentence — the one thing to do on this screen. -->
        <RouterLink class="home__cta" :to="{ name: 'watch' }">Add your first route</RouterLink>
      </div>
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

      <!-- No GPU, no globe — same information without the film: every watched
           route as the card the spotlight would have shown. -->
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
  /* The overlap that ties card to globe (design/README.md §1) — GlobeStage.vue's
     caption must clear exactly this token; see .stage__caption there for why. */
  position: relative;
  z-index: 4;
  margin: calc(-1 * var(--spotlight-overlap)) var(--gutter) 0;
}

.home__flat {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 14px var(--gutter) 0;
}

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
  /* The same overlap, so the screen does not shift when the real card lands. */
  margin-top: calc(-1 * var(--spotlight-overlap));
  border-radius: var(--radius-card);
  background: var(--card);
  box-shadow: var(--shadow);
}

/* No arcs or caption on an empty globe, so these overlays are hidden here
   rather than saying "0 routes orbiting" — scoped to this state only. */
.home__day1-globe :deep(.stage__chip),
.home__day1-globe :deep(.stage__caption) {
  display: none;
}

.home__notice {
  margin: 24px var(--gutter);
  padding: 22px 20px;
  border: 1px solid var(--line);
  border-radius: var(--radius-card);
  background: var(--card);
  box-shadow: var(--shadow);
}

/* Rides up over the globe's lower edge, matching the spotlight card's overlap.

   MUST STAY AFTER .home__notice: its shorthand `margin` would overwrite a
   margin-top declared before it. */
.home__notice--over {
  position: relative;
  z-index: 4;
  margin-top: calc(-1 * var(--spotlight-overlap));
}

.home__cta {
  display: block;
  width: 100%;
  height: 48px;
  margin-top: 16px;
  border-radius: 14px;

  font-family: var(--font-display);
  font-size: var(--text-xl);
  font-weight: 700;
  line-height: 48px;
  text-align: center;
  text-decoration: none;

  /* The accent, because in this app the accent means "an action" — the tab
     bar's + and the booking hand-off are the other two. */
  background: var(--accent);
  color: var(--on-solid);
  box-shadow: 0 6px 16px var(--accent-glow);
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
