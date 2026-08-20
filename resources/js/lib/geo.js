// Pure geometry: nothing here knows what a globe, canvas or DOM node is, which is what lets
// the numbers be tested in isolation. Degrees at the boundary, sphere model.

const RAD = Math.PI / 180

/**
 * Segments in a flight path (design/README.md §1: "the 72-point great-circle" = 73 points).
 * Fencepost is deliberate: both endpoints land ON the path.
 */
export const FLIGHT_SEGMENTS = 72

/**
 * Camera altitude at eased progress `e` (design/README.md §1). Descends DEEPER than the take-off
 * altitude (0.20 end vs 0.42 start) so landing reads as arriving, not just stopping.
 */
export function flightAltitude(e) {
    return 0.42 - 0.22 * e + 0.4 * Math.sin(Math.PI * e)
}

/**
 * Ease-in-out quad: real aircraft don't start at cruise speed. Symmetric curve, so progress and
 * 1-progress accelerate/brake by the same amount.
 */
export function easeInOutQuad(t) {
    return t < 0.5 ? 2 * t * t : 1 - ((-2 * t + 2) ** 2) / 2
}
/**
 * Initial bearing (deg clockwise from north) — a BEARING, not the on-screen line angle. It
 * changes along a great circle, so the choreography recomputes it per frame.
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
 * Great-circle path via slerp — matches globe.gl's drawn arc, unlike a straight lat/lng line.
 * The degenerate case collapses to the endpoints rather than NaN (docs/BUSINESS-LOGIC.md §36).
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
 * True midpoint of the path, not the average of the two lat/lngs — the average is wrong by
 * hemispheres crossing the antimeridian (AMS→NRT would centre on the Atlantic).
 */
export function pathMidpoint(path) {
    return path[Math.floor(path.length / 2)]
}

/**
 * Camera position and heading at raw progress `t`; easing lives here so every caller shares one
 * curve. Longitude uses the shortest delta, not a naive lerp (docs/BUSINESS-LOGIC.md §36).
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
 * A longitude folded back into (-180, 180], which is the range globe.gl and the API both speak.
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
    // Interpolated vectors drift off the unit sphere by rounding error; asin() of a magnitude over
    // 1 is NaN, and one NaN frame is a camera that never comes back.
    const length = Math.hypot(x, y, z) || 1

    return {
        lat: Math.asin(clamp(z / length, -1, 1)) / RAD,
        lng: Math.atan2(y / length, x / length) / RAD,
    }
}

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value))
}
