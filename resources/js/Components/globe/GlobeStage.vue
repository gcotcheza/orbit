<script setup>
/*
 * =============================================================================
 * The globe viewport, and the film it plays
 * =============================================================================
 * 360 px of Earth with three overlays on it, and the camera choreography from
 * design/README.md §1: fit the route, dive to the origin airport, fly the
 * great circle like an aeroplane, land, dwell, and ask for the next route.
 *
 * WHAT IS WHERE. The numbers are in lib/geo.js (where the flight is), the
 * timings are in lib/tour.js (when each move happens), and globe.gl is behind
 * globeScene.js (how it is drawn). What is left here — and it is the only part
 * that genuinely needs a component — is LIFECYCLE: one timer per step under a
 * cancellation token, a requestAnimationFrame loop for the flight itself, and
 * the four different ways this screen can stop being looked at.
 *
 * CANCELLATION IS THE WHOLE PROBLEM. A sequence is eleven seconds long and
 * anything can happen inside it: the user taps another chip, switches to the
 * calendar (KeepAlive deactivates this without unmounting it), backgrounds the
 * browser, or leaves. Every one of those has to stop four timers and a frame
 * loop AND make sure the callbacks already queued behind them do nothing when
 * they fire. `token` is that guarantee: every scheduled callback captures the
 * value it was scheduled under and returns immediately if it is no longer the
 * current one. It is the design prototype's `_seq`, kept because it is right.
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
  // The dwell is over: the parent owns which route is next, because it also
  // owns the spotlight card and the rail that have to agree with it.
  'advance',
  // There is no globe and there is not going to be one — WebGL is missing, the
  // chunk failed to load, or the context was lost. The parent draws a list.
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
 * Somebody who has asked their phone to stop moving things has asked this
 * screen too. Reduced motion does not mean a faster tour or a shorter one: it
 * means no flight at all — a still globe with every watched arc drawn faintly,
 * which is the same information laid out as a map instead of as a film. The
 * chips and the card keep working, so the screen is not degraded, only quiet.
 *
 * Watched rather than read once: it is an OS switch, and somebody who turns it
 * on because an app is making them queasy should not have to reload.
 */
const stillness = window.matchMedia('(prefers-reduced-motion: reduce)')
const reducedMotion = ref(stillness.matches)
const onStillnessChange = (event) => {
  reducedMotion.value = event.matches
}

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
 * Abandon whatever the camera was doing.
 *
 * Bumping the token first is what makes this safe: a setTimeout that has
 * already fired and is sitting in the task queue cannot be cleared, and it will
 * check the token before it touches anything.
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
 * Play the sequence for the current route.
 *
 * `instant` skips the opening move, for the two cases where there is nothing on
 * screen to move away from: the first route after the globe is built, and the
 * first one after the screen comes back from being hidden.
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
    // A cut, not a move, and no auto-advance: a screen that rearranges itself
    // every eleven seconds is exactly what was being asked to stop.
    scene.pointOfView({ ...pathMidpoint(path), altitude: TIMING.fitAltitude }, 0)

    return
  }

  scene.showRoute(route)

  for (const step of flightSequence({ instant })) {
    if (step.at === 0) {
      // Run the opening frame NOW rather than one task later. A zero-delay
      // timer still yields, and yielding here is a visible flash of the
      // globe's default camera position before the fit takes hold.
      runStep(step, route, mine)

      continue
    }

    timers.push(setTimeout(() => runStep(step, route, mine), step.at))
  }
}

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
 * Fly the arc: one camera position per frame, straight from the pure maths.
 *
 * The camera is moved with a transition of 0 — it is BEING animated, so asking
 * globe.gl to tween towards each of 200-odd positions would fight this loop and
 * lag behind it.
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
// Three ways in and out of this state, all with the same answer. `paused` is a
// latch rather than a counter because they overlap: switching to another tab in
// the app AND then backgrounding the browser must not need two resumes.

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
  // Restart the film rather than resume it mid-flight: the user has just
  // arrived, and the interesting part is the take-off.
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
    // The chunk carries Three.js and is the biggest thing this app downloads;
    // on a bad connection it is the most likely thing to fail. Report it and
    // let the screen draw its list.
    console.error('The globe could not be built.', error)
    emit('unavailable')

    return
  }

  if (disposed) {
    // Unmounted while the import was in flight. Nothing above ran, so nothing
    // above cleaned up.
    scene.destroy()
    scene = null

    return
  }

  resizeObserver = new ResizeObserver(() => scene?.resize())
  resizeObserver.observe(viewport.value)

  if (paused) {
    // Hidden or navigated away from while the import was in flight. The scene
    // is born asleep: pause() ran before there was anything to pause, and a
    // render loop nobody is looking at is somebody's battery.
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

    <PlaneGlyph v-show="flying" :bearing="bearing" />

    <p class="stage__caption">{{ caption }}</p>
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

/*
 * THE CAPTION SITS ABOVE THE SPOTLIGHT CARD, and the arithmetic is the whole
 * fix. design/README.md §1 asks for two things that are incompatible as
 * written: a caption pinned to the bottom of a 360px stage (`bottom: 6px` in
 * the prototype's own markup) and a card that climbs 30px over that same
 * bottom edge, opaque and at `z-index: 4`. Every pixel of the caption was
 * therefore painted underneath the card — `elementFromPoint` at its centre
 * returned `.spotlight`, and the text was in the DOM, in the accessibility
 * tree, and visible to nobody. Only a browser could see that; jsdom has no
 * layout engine, so every test that existed was green.
 *
 * The design's 6px stays as the caption's own breathing room; what is added is
 * the card's overlap plus a gap, which puts the caption in the strip of stage
 * the card does not reach — where design/screenshots/01 draws it, under the
 * globe and above the card. Raising the caption's z-index instead would put
 * this text ON the card's rounded top edge, over the route code, which is not
 * the screen anybody drew.
 */
.stage__caption {
  position: absolute;
  inset-inline: 0;
  bottom: calc(6px + var(--spotlight-overlap) + 8px);
  /* Kept, and now load-bearing for a second reason: the caption has moved onto
     the globe's own rectangle, and a drag that starts on this text still has
     to reach the globe's rotate controls underneath it. */
  pointer-events: none;

  text-align: center;
  font-family: var(--font-display);
  font-size: var(--text-md);
  font-weight: 600;
  letter-spacing: 0.12em;
  color: var(--muted);
  /* A halo in the page colour, because this text is now over the EARTH rather
     than over the strip of background below it, and a photographic texture is
     not a backdrop this palette gets to choose: `--muted` reads over the
     Atlantic and vanishes over the Sahara. Two soft shadows rather than one
     offset — the glyphs need backing on every side, not underneath.
     (The design's own screenshot has clear space here because its camera was
     parked at the fitted altitude; ours is mid-flight most of the time.) */
  text-shadow: 0 0 4px var(--bg), 0 0 9px var(--bg);
}
</style>
