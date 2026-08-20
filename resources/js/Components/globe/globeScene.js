// All globe.gl/Three.js code lives in this file, kept out of Vue's reactivity (GlobeStage.vue calls its verbs; lib/geo.js supplies the numbers). Textures are served locally, not from a CDN (CSP), and
// every colour is read from tokens.css via token() rather than hard-coded (docs/BUSINESS-LOGIC.md §36).

// The Earth, as photographed. `night` is a candidate user setting, vendored here so switching to it
// is one argument, not one download (docs/BUSINESS-LOGIC.md §36).
export const TEXTURES = {
    day: '/globe/earth-blue-marble.jpg',
    night: '/globe/earth-night.jpg',
}

const BUMP_TEXTURE = '/globe/earth-topology.png'

// Probes WebGL support so the home screen can fall back to a plain list instead of drawing nothing;
// the probe canvas is discarded immediately (docs/BUSINESS-LOGIC.md §36).
export function hasWebgl() {
    try {
        const canvas = document.createElement('canvas')

        return Boolean(canvas.getContext('webgl2') ?? canvas.getContext('webgl'))
    } catch {
        // Some browsers throw rather than return null when the context is blocked by a privacy
        // setting. Same answer either way.
        return false
    }
}

/**
 * Build the scene inside `element`; globe.gl is imported dynamically since it's this app's largest
 * dependency and import failures reject cleanly (docs/BUSINESS-LOGIC.md §36).
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

        // Hairline arcs (design/README.md §1); stroke is per-datum so reduced-motion can reuse the
        // same layer at low opacity (docs/BUSINESS-LOGIC.md §36).
        .arcColor('color')
        .arcStroke('stroke')
        .arcAltitudeAutoScale(0.45)
        .arcDashLength('dashLength')
        .arcDashGap('dashGap')
        .arcDashAnimateTime('dashAnimateTime')
        // Arc transitions are disabled: a tween would visibly slide the old route's arc into the
        // new one's on every route change (docs/BUSINESS-LOGIC.md §36).
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

    // Rotate-drag only (design/README.md §1); zoom/pan would fight the camera choreography and yank
    // the view mid-gesture (docs/BUSINESS-LOGIC.md §36).
    const controls = globe.controls()
    controls.enableZoom = false
    controls.enablePan = false
    controls.autoRotate = false

    const canvas = globe.renderer().domElement

    // A lost WebGL context (common on backgrounded phones under memory pressure) is reported via
    // onContextLost so the screen can fall back to the list (docs/BUSINESS-LOGIC.md §36).
    const handleContextLost = (event) => {
        event.preventDefault()
        onContextLost?.()
    }

    canvas.addEventListener('webglcontextlost', handleContextLost)

    const scene = {
        // Element's box is the single source of size (set once in GlobeStage.vue's CSS); a zero box is skipped since a 0/0 aspect ratio produces an unrecoverable NaN camera matrix.
        // Why: docs/BUSINESS-LOGIC.md §36.
        resize() {
            const { clientWidth, clientHeight } = element

            if (clientWidth > 0 && clientHeight > 0) {
                globe.width(clientWidth).height(clientHeight)
            }
        },

        // Atmosphere colour is the only themed part of the globe; the Earth texture is a fixed
        // photograph, so nothing else should be re-tinted (docs/BUSINESS-LOGIC.md §36).
        applyTheme() {
            globe.atmosphereColor(token('--globe-atmosphere'))
        },

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

        // Reduced-motion view: draws every route at once, faintly, with no pulse/dash — an honest
        // static map instead of a film (docs/BUSINESS-LOGIC.md §36).
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

        // Called when the tab is hidden or the screen is KeepAlive-cached; globe.gl runs its own tweens outside requestAnimationFrame's throttling, so this stops them explicitly.
        // Why: docs/BUSINESS-LOGIC.md §36.
        pause() {
            globe.pauseAnimation()
        },

        resume() {
            globe.resumeAnimation()
        },

        destroy() {
            canvas.removeEventListener('webglcontextlost', handleContextLost)
            // DO NOT REMOVE: frees the WebGL context/textures/geometry, or the browser hits its
            // context limit after a few remounts and kills the oldest one.
            globe._destructor()
            element.replaceChildren()
        },
    }

    scene.applyTheme()
    scene.resize()

    return scene
}

/**
 * One arc datum. The two stroke widths are design/README.md §1's: 0.35 for the route being flown,
 * 0.16 for one that is merely on the list.
 */
function arcFor(route, { active, still = false }) {
    return {
        startLat: route.origin.lat,
        startLng: route.origin.lng,
        endLat: route.destination.lat,
        endLng: route.destination.lng,
        color: [token('--globe-arc-from'), token('--globe-arc-to')],
        stroke: active ? 0.35 : 0.16,
        // Solid line (dashLength=1) when reduced motion is on: a static dash pattern is just a
        // dotted line, an animated one is what reduced motion forbids (docs/BUSINESS-LOGIC.md §36).
        dashLength: still ? 1 : 0.5,
        dashGap: still ? 0 : 0.22,
        dashAnimateTime: still ? 0 : 2400,
    }
}

// globe.gl requires a function of progress to express a fade, the only reason this file parses a hex colour; the colour still comes from tokens.css, only the alpha is ours.
// Why: docs/BUSINESS-LOGIC.md §36.
function fadingRing(hex) {
    const { r, g, b } = toRgb(hex)

    return (t) => `rgba(${r}, ${g}, ${b}, ${(0.55 * (1 - t)).toFixed(2)})`
}

// Reads live rather than cached: `data-theme` can change at any moment (stores/theme.js), so this
// stays cheaply in sync with the stylesheet (docs/BUSINESS-LOGIC.md §36).
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
