// =============================================================================
// The globe, as a thing with knobs on
// =============================================================================
// Everything in this app that knows globe.gl exists is in this file. It is not
// a component and it holds no reactive state: it takes a DOM element, builds
// the WebGL scene design/README.md §1 specifies, and hands back an object with
// a handful of verbs on it. GlobeStage.vue does the choreography by calling
// those verbs; lib/geo.js works out the numbers to call them with.
//
// WHY THE SPLIT. globe.gl is a chained-setter API over Three.js with its own
// render loop and its own tweens — the exact opposite of Vue's model, and
// pointing a `watch` at it directly produces code where a reactive dependency
// and a WebGL side effect are the same line and neither can be read. Keeping
// the seam here also means the day this is swapped for a different renderer,
// the screen above it does not change.
//
// THE TEXTURES ARE OURS, SERVED FROM public/globe/. The design prototype pulls
// them from unpkg; deploy/nginx/flights-ghiecode.conf sends
// `default-src 'self'` and `img-src 'self' data: blob:`, so a CDN texture is a
// blocked request and a black marble. They are committed, three files, ~2.5 MB
// — see the PR body — and they are the only images this app has.
//
// NOTHING HERE HARD-CODES A COLOUR. Every one is read out of tokens.css at the
// moment it is applied, through token(), because a hex in this file would be
// the one colour in Orbit that the light theme could not reach.
// =============================================================================

/**
 * The Earth, as photographed.
 *
 * `night` is the prototype's "Night lights" tweak (design/README.md §Known
 * gaps) and is a candidate user setting; it is vendored and named here so that
 * switching to it is one argument rather than one download.
 */
export const TEXTURES = {
    day: '/globe/earth-blue-marble.jpg',
    night: '/globe/earth-night.jpg',
}

const BUMP_TEXTURE = '/globe/earth-topology.png'

/**
 * Is there a GPU at the other end of this?
 *
 * A phone with WebGL disabled, a locked-down browser, a machine whose driver
 * has been blocklisted: all of them run the JavaScript perfectly and then draw
 * nothing at all, which reads as a broken app rather than as an unsupported
 * one. The home screen probes FIRST and renders a plain list instead — the
 * globe is the signature of this app, not the content of it.
 *
 * The probe canvas is thrown away immediately; it is a capability question, not
 * a rendering one.
 */
export function hasWebgl() {
    try {
        const canvas = document.createElement('canvas')

        return Boolean(canvas.getContext('webgl2') ?? canvas.getContext('webgl'))
    } catch {
        // Some browsers throw rather than return null when the context is
        // blocked by a privacy setting. Same answer either way.
        return false
    }
}

/**
 * Build the scene inside `element`.
 *
 * globe.gl is imported HERE, dynamically, for two reasons: it carries Three.js
 * with it and is by far the largest thing this app ships, so it must not sit in
 * the entry chunk; and an import that fails — a stale service-worker cache, a
 * flaky connection on a train — becomes a rejected promise the caller can fall
 * back from instead of a blank screen.
 *
 * @param {HTMLElement} element
 * @param {{ onContextLost?: () => void }} options
 */
export async function createGlobeScene(element, { onContextLost } = {}) {
    const { default: Globe } = await import('globe.gl')

    const globe = new Globe(element, { animateIn: false })
        .backgroundColor('rgba(0,0,0,0)')
        .globeImageUrl(TEXTURES.day)
        .bumpImageUrl(BUMP_TEXTURE)
        .showAtmosphere(true)
        .atmosphereAltitude(0.22)

        // Hairline arcs, "like satellite-route imagery" (design/README.md §1).
        // The stroke is per-datum so that the reduced-motion view can draw the
        // whole watchlist faintly with the same layer.
        .arcColor('color')
        .arcStroke('stroke')
        .arcAltitudeAutoScale(0.45)
        .arcDashLength('dashLength')
        .arcDashGap('dashGap')
        .arcDashAnimateTime('dashAnimateTime')
        // Arcs are replaced wholesale on every route change, and a transition
        // would animate the old route's arc into the new one's — two airports
        // sliding across the planet, which is not what a change of route is.
        .arcsTransitionDuration(0)

        .pointColor('color')
        .pointAltitude(0.015)
        .pointRadius(0.42)
        .pointsTransitionDuration(0)

        .ringColor('color')
        .ringMaxRadius(3)
        .ringPropagationSpeed(1.5)
        .ringRepeatPeriod(1100)
        .ringAltitude(0.016)

    // Rotate-drag only (design/README.md §1). Zoom and pan would fight the
    // camera choreography for control of the same three numbers, and the tour
    // would yank the view back mid-gesture.
    const controls = globe.controls()
    controls.enableZoom = false
    controls.enablePan = false
    controls.autoRotate = false

    const canvas = globe.renderer().domElement

    // A lost context is a globe that has stopped existing while the page is
    // still up — a backgrounded tab on a phone under memory pressure is the
    // common cause. Telling the screen lets it fall back to the list rather
    // than leave a hole where the Earth was.
    const handleContextLost = (event) => {
        event.preventDefault()
        onContextLost?.()
    }

    canvas.addEventListener('webglcontextlost', handleContextLost)

    const scene = {
        /**
         * Fit the renderer to the element it is drawing into.
         *
         * The element's own box is the single source of the size — the 360 px
         * from design/README.md §1 is written once, in GlobeStage.vue's CSS,
         * and never as a number in here. A zero box (the screen is display:none,
         * or has not been laid out yet) is ignored rather than passed on: a
         * camera with a 0/0 aspect ratio is a matrix full of NaN that does not
         * recover when the element comes back.
         */
        resize() {
            const { clientWidth, clientHeight } = element

            if (clientWidth > 0 && clientHeight > 0) {
                globe.width(clientWidth).height(clientHeight)
            }
        },

        /**
         * Re-read the atmosphere colour from the stylesheet.
         *
         * The only part of the globe that changes with the theme: the Earth
         * texture is the same photograph either way, so a re-tinted arc would
         * stop matching it (tokens.css says the same thing, from the other
         * side).
         */
        applyTheme() {
            globe.atmosphereColor(token('--globe-atmosphere'))
        },

        /**
         * Draw one route: its arc, both airports, and a pulse over the
         * destination.
         */
        showRoute(route) {
            globe
                .arcsData([arcFor(route, { active: true })])
                .pointsData([
                    { lat: route.origin.lat, lng: route.origin.lng, color: token('--globe-origin') },
                    { lat: route.destination.lat, lng: route.destination.lng, color: token('--globe-destination') },
                ])
                .ringsData([{
                    lat: route.destination.lat,
                    lng: route.destination.lng,
                    color: fadingRing(token('--globe-destination')),
                }])
        },

        /**
         * Draw every route at once, faintly, with no pulse and no dash
         * animation — the reduced-motion view of the same watchlist.
         *
         * Nothing here moves, so all of it can be on screen: the arcs stop
         * being a film and become a map, which is the honest static answer to
         * "what am I watching".
         */
        showAllRoutes(routes, activeCode) {
            globe
                .arcsData(routes.map((route) => arcFor(route, { active: route.code === activeCode, still: true })))
                .pointsData(routes.flatMap((route) => [
                    { lat: route.origin.lat, lng: route.origin.lng, color: token('--globe-origin') },
                    { lat: route.destination.lat, lng: route.destination.lng, color: token('--globe-destination') },
                ]))
                .ringsData([])
        },

        /** Move the camera. `ms` of 0 is a cut rather than a move. */
        pointOfView(pov, ms = 0) {
            globe.pointOfView(pov, ms)
        },

        /**
         * Stop and start the render loop.
         *
         * Called when the tab is hidden or the screen is cached by KeepAlive.
         * requestAnimationFrame is already throttled in a hidden tab, but the
         * globe is also running dash and ring tweens on its own clock, and a
         * phone should not be asked to keep a planet warm for a screen nobody
         * is looking at.
         */
        pause() {
            globe.pauseAnimation()
        },

        resume() {
            globe.resumeAnimation()
        },

        destroy() {
            canvas.removeEventListener('webglcontextlost', handleContextLost)
            // Frees the WebGL context, the textures and the geometry. Without
            // it a browser hits its context limit after a few remounts and
            // starts silently killing the oldest one.
            globe._destructor()
            element.replaceChildren()
        },
    }

    scene.applyTheme()
    scene.resize()

    return scene
}

/**
 * One arc datum. The two stroke widths are design/README.md §1's: 0.35 for the
 * route being flown, 0.16 for one that is merely on the list.
 */
function arcFor(route, { active, still = false }) {
    return {
        startLat: route.origin.lat,
        startLng: route.origin.lng,
        endLat: route.destination.lat,
        endLng: route.destination.lng,
        color: [token('--globe-arc-from'), token('--globe-arc-to')],
        stroke: active ? 0.35 : 0.16,
        // A solid line when nothing is allowed to move: dashes that do not
        // travel are just a dotted line, and dashes that do are the thing
        // reduced motion is asking us not to do.
        dashLength: still ? 1 : 0.5,
        dashGap: still ? 0 : 0.22,
        dashAnimateTime: still ? 0 : 2400,
    }
}

/**
 * The destination pulse: the token colour, fading out as the ring expands.
 *
 * globe.gl asks for a FUNCTION of the ring's own progress here, which is the
 * only way to express a fade — and the only reason this file parses a hex at
 * all. The colour still comes from tokens.css; only the alpha is ours.
 */
function fadingRing(hex) {
    const { r, g, b } = toRgb(hex)

    return (t) => `rgba(${r}, ${g}, ${b}, ${(0.55 * (1 - t)).toFixed(2)})`
}

/**
 * Read a design token off the document.
 *
 * Live rather than cached: `data-theme` can change at any moment (stores/theme.js)
 * and this is the cheapest possible way of always agreeing with the stylesheet.
 */
function token(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim()
}

/** `#ffd166` → `{ r: 255, g: 209, b: 102 }`. Three-digit hex included. */
function toRgb(hex) {
    const value = hex.replace('#', '')
    const full = value.length === 3 ? value.replace(/./g, (c) => c + c) : value
    const int = Number.parseInt(full, 16)

    return { r: (int >> 16) & 255, g: (int >> 8) & 255, b: int & 255 }
}
