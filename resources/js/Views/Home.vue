<script setup>
/*
 * Home — the Orbit globe (design/README.md §1). `name` must stay 'Home' for App.vue's
 * <KeepAlive>; the list is shared and the tour is keyed by route code (docs/BUSINESS-LOGIC.md §36).
 */
import { computed, onActivated, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute, useRouter } from 'vue-router'
import { nextIndex } from '@/lib/tour'
import GlobeStage from '@/Components/globe/GlobeStage.vue'
import HomeHeader from '@/Components/HomeHeader.vue'
import RouteRail from '@/Components/globe/RouteRail.vue'
import RouteRows from '@/Components/RouteRows.vue'
import SpotlightCard from '@/Components/globe/SpotlightCard.vue'
import RouteDetailPanel from '@/Components/route/RouteDetailPanel.vue'
import { hasWebgl } from '@/Components/globe/globeScene'
import { useLayout } from '@/lib/layout'
import { useWatchlistStore } from '@/stores/watchlist'

defineOptions({ name: 'Home' })

const route = useRoute()
const router = useRouter()
const { isPhone, isDesktop } = useLayout()

const watchlist = useWatchlistStore()
const { routes, status } = storeToRefs(watchlist)

const loading = computed(() => status.value === 'loading')
const failed = computed(() => status.value === 'failed')

const activeCode = ref(null)

/*
 * Probed once — WebGL-less renders fine except the globe, which looks broken rather than
 * unsupported. A ref because GlobeStage can discover this later too.
 */
const globeAvailable = ref(hasWebgl())

/** Paused routes are in the payload with `active: false`; the tour skips them. */
const activeRoutes = computed(() => routes.value.filter((one) => one.active))

const activeRoute = computed(
  () => activeRoutes.value.find((one) => one.code === activeCode.value) ?? activeRoutes.value[0],
)

/*
 * The pane's route, deep-linkable as `/?route=AMS-LIS`. A code that is not on the watchlist falls
 * back to the tour's own, so the rows, the globe and the panel cannot disagree.
 */
const selected = computed(() => {
  const asked = typeof route.query.route === 'string' ? route.query.route.toUpperCase() : null

  return activeRoutes.value.find((one) => one.code === asked) ?? activeRoute.value
})

/** A row or a chip was picked: the query moves, the screen does not. */
function select(code) {
  activeCode.value = code
  router.replace({ query: { ...route.query, route: code } })
}

/* A link naming a route nobody is watching opens the tour's own; the address bar has to say so, or
   it goes on offering a route that is not on screen. */
watch([selected, () => route.query.route], ([chosen, asked]) => {
  if (chosen && asked && asked !== chosen.code) {
    router.replace({ query: { ...route.query, route: chosen.code } })
  }
})

/**
 * "Good morning", by the phone's clock. Deliberately local time, not the owner's configured
 * timezone — a greeting talks to whoever holds the phone (docs/BUSINESS-LOGIC.md §36).
 */
function currentGreeting() {
  const hour = new Date().getHours()

  if (hour < 12) {
    return 'Good morning'
  }

  return hour < 18 ? 'Good afternoon' : 'Good evening'
}

// Read before the first render, not in onMounted — the greeting is the largest text on the screen,
// so starting empty leaves a hole in frame one.
const greeting = ref(currentGreeting())

/*
 * A 401 is handled in lib/http.js (redirects to login); this screen only decides where the camera
 * starts and how to show "could not be reached" (docs/BUSINESS-LOGIC.md §36).
 */
async function load() {
  await watchlist.refresh()

  activeCode.value = activeRoutes.value[0]?.code ?? null
}

/** The tour has finished dwelling on a route: move to the next one. */
function advance() {
  const current = activeRoutes.value.findIndex((one) => one.code === activeCode.value)

  activeCode.value = activeRoutes.value[nextIndex(current, activeRoutes.value.length)]?.code ?? null
}

onMounted(load)

// This screen is cached, not rebuilt, so it can be hours old — a stale "Good morning" at 6pm is a
// small wrongness that makes the app feel unattended.
onActivated(() => {
  greeting.value = currentGreeting()
})
</script>

<template>
  <div v-if="isPhone" class="home">
    <HomeHeader :greeting="greeting" profile />

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

        <SpotlightCard v-for="one in activeRoutes" :key="one.code" :route="one" />
      </div>
    </template>
  </div>
  <!-- 768px and up: the frame's landing page. The phone branch above is untouched, which is what
       keeps its baselines at zero (docs/DESKTOP-LAYOUT-PLAN.md). -->
  <div v-else class="home home--wide">
    <section class="home__master">
      <!-- No profile button: the rail carries the account link at these widths, and two links of
           the same name to the same place is one too many. -->
      <HomeHeader :greeting="greeting" />

      <template v-if="!loading && !failed && activeRoutes.length > 0">
        <!-- Full-width rows in the master pane; the chip strip is what fits a single pane. -->
        <template v-if="isDesktop">
          <div class="home__rows-head">
            <h2 class="home__rows-title">Fly to a route</h2>
            <p class="home__rows-count">{{ activeRoutes.length }} watched</p>
          </div>

          <RouteRows
            :routes="activeRoutes"
            :active="selected.code"
            label="Watched routes"
            @select="select"
          />
        </template>

        <RouteRail v-else :routes="activeRoutes" :active-code="selected.code" @select="select" />
      </template>
    </section>

    <section class="home__pane">
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

      <template v-else-if="activeRoutes.length === 0">
        <GlobeStage
          v-if="globeAvailable"
          class="home__stage home__day1-globe"
          :routes="[]"
          active-code=""
          @unavailable="globeAvailable = false"
        />

        <div class="home__notice">
          <h2 class="home__notice-title">Nothing orbiting yet</h2>
          <p class="home__quiet">Add a route and the globe starts touring it — Orbit prices it every morning.</p>

          <RouterLink class="home__cta" :to="{ name: 'watch' }">Add your first route</RouterLink>
        </div>
      </template>

      <template v-else>
        <!-- No @advance: the pane's route is the one that was chosen, and a globe that toured off
             it every eleven seconds would argue with the panel below. -->
        <GlobeStage
          v-if="globeAvailable"
          class="home__stage"
          :routes="activeRoutes"
          :active-code="selected.code"
          @unavailable="globeAvailable = false"
        />

        <div class="home__panel">
          <RouteDetailPanel :code="selected.code" embedded />
        </div>
      </template>
    </section>
  </div>
</template>

<style scoped>
.home {
  padding-bottom: 8px;
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

/* Rides up over the globe's lower edge. MUST STAY AFTER .home__notice: its shorthand `margin` would
   overwrite a margin-top above it. */
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

/* --- 768px and up: the master-detail landing ----------------------------
   Nothing below this line is reachable on a phone, on its side or otherwise; both
   halves of the query are lib/layout.js's (docs/DESKTOP-LAYOUT-PLAN.md). */

@media (min-width: 768px) and (min-height: 600px) {
  .home--wide {
    display: flex;
    flex-direction: column;
    height: 100%;
    padding-bottom: 0;
  }

  .home__master {
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    min-height: 0;
  }

  .home--wide .home__header {
    padding: 20px 22px 8px;
  }

  .home__pane {
    flex: 1;
    min-width: 0;
    min-height: 0;
    display: flex;
    flex-direction: column;
  }

  /*
   * A share of the pane rather than 360px: bigger screen, bigger globe. The panel under it is the
   * phone's single column and always wants more height than is left, so it scrolls instead.
   */
  .home__pane .home__stage {
    flex: 0 0 auto;
    height: 45%;
    min-height: 280px;
    border-top: 1px solid var(--line);
  }

  .home__panel {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 18px 28px 24px;
    border-top: 1px solid var(--line);
  }
}

@media (min-width: 1024px) and (min-height: 600px) {
  .home--wide {
    flex-direction: row;
  }

  .home__master {
    flex: 0 0 var(--master-width);
    gap: 14px;
    padding: 22px 18px 18px;
    overflow-y: auto;

    background: var(--panel);
    border-right: 1px solid var(--line);
  }

  .home--wide .home__header {
    padding: 0;
  }

  .home__rows-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 2px 0;
  }

  .home__rows-title {
    font-family: var(--font-display);
    font-size: var(--text-xl);
    font-weight: 600;
    color: var(--ink);
  }

  .home__rows-count {
    font-size: var(--text-md);
    font-weight: 500;
    color: var(--muted);
  }

  /*
   * The globe takes the pane's leftover height now that the detail below it is two columns wide
   * enough to have any (docs/DESKTOP-LAYOUT-PLAN.md phase 2). The chip strip's line goes with it.
   */
  .home__pane .home__stage {
    flex: 1 1 0;
    height: auto;
    min-height: 280px;
    border-top: 0;
  }

  .home__panel {
    flex: 0 0 auto;
    overflow: visible;

    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    align-items: start;
    gap: 0 28px;
  }

  /* A skeleton or a "no such route" is one thing, not a pair of columns. */
  .home__panel > :deep(:not(.detail__group)) {
    grid-column: 1 / -1;
  }

  .home__panel :deep(.detail__group) {
    display: block;
    min-width: 0;
  }

  .home__panel :deep(.detail__group--chart),
  .home__panel :deep(.detail__group--booking) {
    grid-column: 2;
  }

  /* Both columns start on the same line; the phone's 18px is its gap from the price above it. */
  .home__panel :deep(.chart-card) {
    margin-top: 0;
  }
}
</style>
