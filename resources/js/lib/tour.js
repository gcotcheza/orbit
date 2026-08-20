// The camera's fit/dive/fly/dwell sequence is data (design/README.md §1),
// not nested setTimeout callbacks, so it's testable as one pure function.
// Why: docs/BUSINESS-LOGIC.md §36.

/**
 * Every duration in the choreography, in milliseconds, from design/README.md §1.
 */
export const TIMING = {
    // 1. Fit: the whole route on screen, centred on the middle of its arc.
    fitMs: 900,
    fitAltitude: 2.4,

    // 2. Dive to the origin airport; delay measured from the fit STARTING
    // (not finishing), so the two moves overlap and the camera never stops.
    // Why: docs/BUSINESS-LOGIC.md §36.
    diveDelayMs: 1300,
    // First route's fit is instantaneous (nothing onscreen to move away from),
    // so the dive waits out its own beat instead of the fit's.
    // Why: docs/BUSINESS-LOGIC.md §36.
    instantDiveDelayMs: 1100,
    diveMs: 1700,
    diveAltitude: 0.42,

    // 3. The flight itself, again measured from the dive starting: the camera
    // holds over the origin for the remainder while the arc's dashes animate.
    flightDelayMs: 2500,
    flightMs: 3600,
}

/**
 * How long the camera sits on the destination (design/README.md §1
 * "Motion"); single source of these numbers until docs/PLAN.md's UI lands.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
export const DWELL_MS = {
    calm: 5400,
    balanced: 4400,
    lively: 3300,
}

export const DEFAULT_MOTION = 'balanced'

/**
 * Timetable for one route, oldest first; `at` is ms from sequence start.
 * Last entry `advance` hands off to the next route — non-touring callers ignore it.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * @param {{ instant?: boolean, motion?: keyof DWELL_MS }} options
 */
export function flightSequence({ instant = false, motion = DEFAULT_MOTION } = {}) {
    const dwellMs = DWELL_MS[motion] ?? DWELL_MS[DEFAULT_MOTION]

    const diveAt = instant ? TIMING.instantDiveDelayMs : TIMING.diveDelayMs
    const flyAt = diveAt + TIMING.flightDelayMs

    return [
        { step: 'fit', at: 0, durationMs: instant ? 0 : TIMING.fitMs, altitude: TIMING.fitAltitude },
        { step: 'dive', at: diveAt, durationMs: TIMING.diveMs, altitude: TIMING.diveAltitude },
        { step: 'fly', at: flyAt, durationMs: TIMING.flightMs },
        { step: 'advance', at: flyAt + TIMING.flightMs + dwellMs },
    ]
}

/**
 * Route after this one, wrapping at list end. Empty list answers 0, not NaN
 * — a timer scheduled before "nothing orbiting yet" can still land here.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
export function nextIndex(current, count) {
    if (count <= 0) {
        return 0
    }

    return (current + 1) % count
}
