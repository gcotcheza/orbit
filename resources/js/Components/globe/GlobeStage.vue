<script setup>
/*
 * The globe viewport and the film it plays (design/README.md §1). Numbers live in lib/geo.js,
 * timings in lib/tour.js, globe.gl behind globeScene.js; what is left here is LIFECYCLE.
 */
import { computed, onActivated, onBeforeUnmount, onDeactivated, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { FLIGHT_SEGMENTS, flightPose, greatCirclePoints, pathMidpoint } from '@/lib/geo'
import { TIMING, flightSequence } from '@/lib/tour'
import { createGlobeScene } from './globeScene'
import PlaneGlyph from './PlaneGlyph.vue'
import { useThemeStore } from '@/stores/theme'

const props = defineProps({
  /** Every ACTIVE watchlist route, in the owner's order. */
  routes: { type: Array, required: true },
  /** The one being toured, by `code`. */
  activeCode: { type: String, required: true },
})

const emit = defineEmits([
  // The dwell is over: the parent owns which route is next, because it also owns the spotlight card
  // and the rail that have to agree with it.
  'advance',
  // There is no globe and there is not going to be one — WebGL is missing, the chunk failed to
  // load, or the context was lost. The parent draws a list.
  'unavailable',
])

const viewport = ref(null)
const flying = ref(false)
const bearing = ref(0)

const { theme } = storeToRefs(useThemeStore())

const activeRoute = computed(
  () => props.routes.find((route) => route.code === props.activeCode) ?? props.routes[0],
)

const caption = computed(() => {
  const route = activeRoute.value

  return route
    ? `${route.origin.iata} → ${route.destination.iata} · ${route.destination.city}`
    : ''
})

const orbitingLabel = computed(
  () => `${props.routes.length} ${props.routes.length === 1 ? 'route' : 'routes'} orbiting`,
)

/*
 * Reduced motion means NO FLIGHT at all — a still globe with every arc drawn faintly, not a
 * faster tour. Watched rather than read once, because it is an OS switch.
 */
const stillness = window.matchMedia('(prefers-reduced-motion: reduce)')
const reducedMotion = ref(stillness.matches)
const onStillnessChange = (event) => {
  reducedMotion.value = event.matches
}

/*
 * Saying that the camera is driving itself — the one thing this screen never told anybody.
 * Deliberately ABSENT when there is no film to describe (docs/BUSINESS-LOGIC.md §36).
 */
const touring = computed(() => props.routes.length > 0 && !reducedMotion.value)

// --- The scene and its cancellation ----------------------------------------

let scene = null
let token = 0
let timers = []
let frame = 0
let path = []
let paused = false
let disposed = false
let resizeObserver = null

/**
 * Abandon whatever the camera was doing. Bumping the token FIRST is what makes this safe: a
 * setTimeout already in the task queue cannot be cleared, but it re-checks the token.
 */
function cancel() {
  token += 1

  timers.forEach(clearTimeout)
  timers = []

  if (frame) {
    cancelAnimationFrame(frame)
    frame = 0
  }

  flying.value = false
}

/**
 * Play the sequence for the current route. `instant` skips the opening move, for the two cases
 * with nothing on screen to move away from: first build, and coming back from hidden.
 */
function play({ instant = false } = {}) {
  cancel()

  const route = activeRoute.value

  if (!scene || !route || paused) {
    return
  }

  const mine = token

  path = greatCirclePoints(route.origin, route.destination, FLIGHT_SEGMENTS)

  if (reducedMotion.value) {
    scene.showAllRoutes(props.routes, route.code)
    // A cut, not a move, and no auto-advance: a screen that rearranges itself every eleven seconds
    // is exactly what was being asked to stop.
    scene.pointOfView({ ...pathMidpoint(path), altitude: TIMING.fitAltitude }, 0)

    return
  }

  scene.showRoute(route)

  for (const step of flightSequence({ instant })) {
    if (step.at === 0) {
      // Run the opening frame NOW rather than one task later: a zero-delay timer still
      // yields, and that is a visible flash of the default camera position.
      runStep(step, route, mine)

      continue
    }

    timers.push(setTimeout(() => runStep(step, route, mine), step.at))
  }
}

/*
 * ⚠ NO CAMERA BIAS HERE, and it was measured rather than assumed — aiming off-subject breaks
 * the dive and detaches the plane glyph from its arc (docs/BUSINESS-LOGIC.md §36).
 */
function runStep(step, route, mine) {
  if (mine !== token || !scene) {
    return
  }

  switch (step.step) {
    case 'fit':
      scene.pointOfView({ ...pathMidpoint(path), altitude: step.altitude }, step.durationMs)
      break

    case 'dive':
      scene.pointOfView({ lat: route.origin.lat, lng: route.origin.lng, altitude: step.altitude }, step.durationMs)
      break

    case 'fly':
      flyArc(step.durationMs, mine)
      break

    case 'advance':
      emit('advance')
      break
  }
}

/**
 * Fly the arc: one camera position per frame, straight from the pure maths. Transition 0 —
 * asking globe.gl to tween towards 200-odd positions would fight this loop.
 */
function flyArc(durationMs, mine) {
  const start = performance.now()

  flying.value = true

  const tick = (now) => {
    if (mine !== token || !scene) {
      return
    }

    const t = Math.min(1, (now - start) / durationMs)
    const pose = flightPose(path, t)

    scene.pointOfView({ lat: pose.lat, lng: pose.lng, altitude: pose.altitude }, 0)
    bearing.value = pose.bearing

    if (t < 1) {
      frame = requestAnimationFrame(tick)

      return
    }

    frame = 0
    flying.value = false
  }

  frame = requestAnimationFrame(tick)
}

// --- Not being looked at ----------------------------------------------------
// `paused` is a latch, not a counter: the three ways overlap and must not need two resumes.

function pause() {
  if (paused) {
    return
  }

  paused = true
  cancel()
  scene?.pause()
}

function resume() {
  if (!paused) {
    return
  }

  paused = false
  scene?.resume()
  // Restart the film rather than resume it mid-flight: the user has just arrived, and the
  // interesting part is the take-off.
  play({ instant: true })
}

const onVisibilityChange = () => {
  if (document.hidden) {
    pause()
  } else {
    resume()
  }
}

// --- Lifecycle --------------------------------------------------------------

onMounted(async () => {
  document.addEventListener('visibilitychange', onVisibilityChange)
  stillness.addEventListener('change', onStillnessChange)

  try {
    scene = await createGlobeScene(viewport.value, { onContextLost: () => emit('unavailable') })
  } catch (error) {
    // The chunk carries Three.js and is the biggest thing this app downloads; on a bad connection
    // it is the most likely thing to fail. Report it and let the screen draw its list.
    console.error('The globe could not be built.', error)
    emit('unavailable')

    return
  }

  if (disposed) {
    // Unmounted while the import was in flight. Nothing above ran, so nothing above cleaned up.
    scene.destroy()
    scene = null

    return
  }

  resizeObserver = new ResizeObserver(() => scene?.resize())
  resizeObserver.observe(viewport.value)

  if (paused) {
    // Hidden or navigated away while the import was in flight. The scene is born asleep,
    // and a render loop nobody is looking at is somebody's battery.
    scene.pause()

    return
  }

  play({ instant: true })
})

onActivated(resume)
onDeactivated(pause)

onBeforeUnmount(() => {
  disposed = true

  cancel()
  document.removeEventListener('visibilitychange', onVisibilityChange)
  stillness.removeEventListener('change', onStillnessChange)
  resizeObserver?.disconnect()
  scene?.destroy()
  scene = null
})

// A new route: replay the whole sequence for it, opening move included.
watch(() => props.activeCode, () => play())

// The atmosphere is the one part of the globe that is themed (tokens.css).
watch(theme, () => scene?.applyTheme())

// Turning stillness on mid-flight has to stop the flight, not wait for it.
watch(reducedMotion, () => play({ instant: true }))
</script>

<template>
  <div class="stage">
    <!-- Nothing in here is reachable or meaningful to a screen reader: the
         route it is drawing is written out in the caption below it and in the
         spotlight card underneath. -->
    <div ref="viewport" class="stage__globe" aria-hidden="true"></div>

    <p class="stage__chip">
      <span class="stage__chip-dot"></span>
      {{ orbitingLabel }}
    </p>

    <!-- Why the planet keeps moving, and the one gesture that is the viewer's.
         See `touring` for what it is answering. -->
    <p v-if="touring" class="stage__hint">Auto-touring · drag to spin</p>

    <PlaneGlyph v-show="flying" :bearing="bearing" />

    <!-- The text is in a span of its own so that the scrim behind it is the
         SHAPE OF THE WORDS rather than a bar across the whole stage — see the
         style block. -->
    <p class="stage__caption"><span class="stage__caption-text">{{ caption }}</span></p>
  </div>
</template>

<style scoped>
/* 360 px, from design/README.md §1, written once: globeScene.js sizes the
   renderer from this element's own box rather than from a number of its own. */
.stage {
  position: relative;
  width: 100%;
  height: 360px;
}

.stage__globe {
  width: 100%;
  height: 100%;
  overflow: hidden;
  /* The app's own background shows through; the globe is lit by its texture,
     not by a panel behind it. */
  background: transparent;
}

.stage__chip {
  position: absolute;
  left: var(--gutter);
  top: 8px;

  display: inline-flex;
  align-items: center;
  gap: 7px;

  padding: 6px 11px;
  border: 1px solid var(--line);
  border-radius: var(--radius-pill);

  font-size: var(--text-sm);
  font-weight: 600;
  color: var(--ink2);

  background: var(--card);
  box-shadow: var(--shadow);
}

.stage__chip-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--accent);
}

/* Under the chip and quieter than it: the chip is a fact about the watchlist,
   this is a note about the camera. It is over the Earth like the caption is, so
   it takes the same scrim — a hint nobody can read is not a hint. */
.stage__hint {
  position: absolute;
  left: var(--gutter);
  top: 44px;

  padding: 3px 9px;
  border-radius: var(--radius-pill);
  /* A drag that starts on this text still has to reach the globe underneath. */
  pointer-events: none;

  font-size: var(--text-xs);
  font-weight: 500;
  letter-spacing: 0.04em;
  color: var(--ink2);

  background: var(--globe-scrim);
}

/*
 * THE CAPTION SITS ABOVE THE SPOTLIGHT CARD, and the arithmetic is the whole fix — every
 * pixel of it used to be painted underneath the card (docs/BUSINESS-LOGIC.md §36).
 */
.stage__caption {
  position: absolute;
  inset-inline: 0;
  bottom: calc(6px + var(--spotlight-overlap) + 8px);
  /* Kept, and now load-bearing for a second reason: the caption has moved onto
     the globe's own rectangle, and a drag that starts on this text still has
     to reach the globe's rotate controls underneath it. */
  pointer-events: none;

  display: flex;
  justify-content: center;
}

/*
 * A SCRIM, BECAUSE A HALO WAS NOT ENOUGH: this text is over the EARTH, so there is no known
 * background at all. On the span, not the `<p>`, or it is a bar (docs/BUSINESS-LOGIC.md §36).
 */
.stage__caption-text {
  padding: 3px 12px;
  border-radius: var(--radius-pill);

  font-family: var(--font-display);
  font-size: var(--text-md);
  font-weight: 600;
  letter-spacing: 0.12em;
  color: var(--ink2);

  background: var(--globe-scrim);
}

/*
 * A PHONE TURNED SIDEWAYS: the stage is the only thing that can give up height without losing
 * information. `max-height` keeps the rule off laptops (docs/BUSINESS-LOGIC.md §36).
 */
@media (orientation: landscape) and (max-height: 560px) {
  .stage {
    height: 40vh;
  }
}
</style>
