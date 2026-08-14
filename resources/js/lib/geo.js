// =============================================================================
// The flight, as arithmetic
// =============================================================================
// Everything the globe's camera does between two airports is computed here, and
// NOTHING in this file knows what a globe, a canvas or a DOM node is. That is
// the point of it: the choreography in Components/globe is timers and WebGL
// calls, which can only be judged by looking at them, while the numbers those
// calls are fed are exactly the part that can be wrong in a way nobody notices
// — a plane that flies the long way round the planet, a heading that points
// backwards over the antimeridian, an altitude curve that dips below the
// surface. Those live here, with tests beside them (geo.test.js).
//
// CONVENTIONS. Angles in DEGREES at the boundary, because that is what the API
// hands us (`origin.lat` / `origin.lng`) and what globe.gl takes back;
// radians only ever exist inside a function. Points are `{ lat, lng }` — the
// API's spelling, so a route's endpoints can be passed straight in.
//
// The Earth is a sphere here. It is an ellipsoid in reality, and the difference
// over a 2 000 km European hop is a few kilometres of ground track — invisible
// at any altitude this camera flies, and not worth carrying WGS-84 for.
// =============================================================================

const RAD = Math.PI / 180

/**
 * The number of segments in a flight path.
 *
 * design/README.md §1: "the 72-point great-circle". Seventy-three points, then
 * — the fencepost is deliberate, so that both endpoints are ON the path and the
 * plane starts exactly over the origin airport rather than one segment past it.
 */
export const FLIGHT_SEGMENTS = 72

/**
 * The camera's altitude at eased progress `e` through a flight.
 *
 * design/README.md §1: `0.42 − 0.22·e + 0.4·sin(π·e)` — climb, cruise, and
 * descend DEEPER than the take-off altitude (0.20 at the end against 0.42 at
 * the start), which is what makes the landing read as arriving somewhere
 * rather than as the film simply stopping.
 */
export function flightAltitude(e) {
    return 0.42 - 0.22 * e + 0.4 * Math.sin(Math.PI * e)
}

/**
 * Ease-in-out quad, the flight's own clock.
 *
 * Real aircraft do not start at cruise speed and neither does this camera. The
 * curve is symmetric, so `progress` and `1 - progress` accelerate and brake by
 * the same amount.
 */
export function easeInOutQuad(t) {
    return t < 0.5 ? 2 * t * t : 1 - ((-2 * t + 2) ** 2) / 2
}

/**
 * The initial bearing from one point to another, in degrees clockwise from
 * north, normalised to [0, 360).
 *
 * This is what the plane glyph is rotated by, and it is a BEARING rather than
 * the angle of the line on screen: on a globe those two are the same thing only
 * because the camera is directly above the plane, looking down, with north up.
 * It changes continuously along a great circle — a flight to Lisbon leaves
 * Amsterdam pointing one way and arrives pointing another — which is why the
 * choreography recomputes it per frame from the CURRENT segment instead of
 * once from the endpoints.
 */
export function bearing(from, to) {
    const fromLat = from.lat * RAD
    const toLat = to.lat * RAD
    const deltaLng = shortestLngDelta(from.lng, to.lng) * RAD

    const y = Math.sin(deltaLng) * Math.cos(toLat)
    const x = Math.cos(fromLat) * Math.sin(toLat) - Math.sin(fromLat) * Math.cos(toLat) * Math.cos(deltaLng)

    return (Math.atan2(y, x) / RAD + 360) % 360
}

/**
 * The great-circle path between two airports, as `segments + 1` points.
 *
 * Spherical linear interpolation between the two position vectors — the same
 * shape the arc that globe.gl draws under the plane takes, which is the whole
 * reason the camera follows THIS and not a straight line in lat/lng space. A
 * straight line in lat/lng is a rhumb line: it looks fine on a map of Europe
 * and visibly leaves the drawn arc on anything longer.
 *
 * Two points, one degenerate case: an origin and destination at the same place
 * (or antipodal, where every path is a shortest path) has no defined direction
 * to interpolate along, so the path collapses to the endpoints and the flight
 * becomes a very short one rather than a NaN.
 */
export function greatCirclePoints(from, to, segments = FLIGHT_SEGMENTS) {
    const a = toVector(from)
    const b = toVector(to)

    const dot = clamp(a[0] * b[0] + a[1] * b[1] + a[2] * b[2], -1, 1)
    const omega = Math.acos(dot)

    if (!(omega > 1e-6) || Math.abs(omega - Math.PI) < 1e-6) {
        return [{ lat: from.lat, lng: from.lng }, { lat: to.lat, lng: to.lng }]
    }

    const sinOmega = Math.sin(omega)
    const path = []

    for (let i = 0; i <= segments; i++) {
        const t = i / segments
        const wa = Math.sin((1 - t) * omega) / sinOmega
        const wb = Math.sin(t * omega) / sinOmega

        path.push(toPoint([
            wa * a[0] + wb * b[0],
            wa * a[1] + wb * b[1],
            wa * a[2] + wb * b[2],
        ]))
    }

    return path
}

/**
 * The point the fitted globe is centred on — the true middle of the path.
 *
 * Not the average of the two latitudes and longitudes. That is what the design
 * prototype used and it agrees with this to a fraction of a degree for a hop
 * across Europe, but it is wrong by hemispheres for anything crossing the
 * antimeridian (AMS→NRT would centre the camera on the Atlantic). Reading the
 * middle of a path we have already computed is both correct everywhere and one
 * line.
 */
export function pathMidpoint(path) {
    return path[Math.floor(path.length / 2)]
}

/**
 * Where the camera is, and which way the plane points, at raw progress `t`
 * through the flight.
 *
 * `t` is 0..1 of WALL CLOCK time; the easing is applied in here so that every
 * caller gets the same curve, and so that the altitude and the position can
 * never be computed from two different notions of "how far along we are".
 *
 * Longitude is interpolated through the shortest delta rather than numerically:
 * a segment stepping from 179.6° to −179.7° is 0.7° of flying, not 359.3°, and
 * a naive lerp puts the camera on the far side of the planet for one frame.
 */
export function flightPose(path, t) {
    const e = easeInOutQuad(clamp(t, 0, 1))

    const last = path.length - 1
    const scaled = e * last
    const index = Math.min(last - 1, Math.floor(scaled))
    const within = scaled - index

    const a = path[index]
    const b = path[index + 1]
    const deltaLng = shortestLngDelta(a.lng, b.lng)

    return {
        lat: a.lat + (b.lat - a.lat) * within,
        lng: normaliseLng(a.lng + deltaLng * within),
        altitude: flightAltitude(e),
        bearing: bearing(a, b),
    }
}

/**
 * The signed difference between two longitudes, the short way round: (-180, 180].
 */
function shortestLngDelta(fromLng, toLng) {
    let delta = (toLng - fromLng) % 360

    if (delta > 180) {
        delta -= 360
    }

    if (delta <= -180) {
        delta += 360
    }

    return delta
}

/**
 * A longitude folded back into (-180, 180], which is the range globe.gl and the
 * API both speak.
 */
function normaliseLng(lng) {
    return shortestLngDelta(0, lng)
}

function toVector({ lat, lng }) {
    const latRad = lat * RAD
    const lngRad = lng * RAD

    return [
        Math.cos(latRad) * Math.cos(lngRad),
        Math.cos(latRad) * Math.sin(lngRad),
        Math.sin(latRad),
    ]
}

function toPoint([x, y, z]) {
    // Interpolated vectors drift off the unit sphere by a rounding error's
    // worth; asin() of a magnitude fractionally over 1 is NaN, and one NaN
    // frame is a camera that never comes back.
    const length = Math.hypot(x, y, z) || 1

    return {
        lat: Math.asin(clamp(z / length, -1, 1)) / RAD,
        lng: Math.atan2(y / length, x / length) / RAD,
    }
}

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value))
}
