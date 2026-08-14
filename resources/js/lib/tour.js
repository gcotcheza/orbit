// =============================================================================
// The auto-tour's timetable
// =============================================================================
// The home screen's camera does the same four things for every route — fit the
// globe, dive to the origin, fly the arc, then sit still long enough for the
// user to read the card — and WHEN each of those happens is the difference
// between a tour that feels like a film and one that feels like a slideshow.
// The timings come from design/README.md §1 and they live here, as data,
// separately from the code that drives WebGL with them.
//
// WHY A SCHEDULE RATHER THAN A CHAIN OF setTimeout CALLBACKS. The obvious shape
// is `setTimeout(dive, 1300)` nested inside `setTimeout(fly, 2500)`, which is
// what the design prototype does. It works, and it cannot be tested, inspected
// or reasoned about without running it: the only way to find out that a flight
// begins 3 800 ms in is to read three nested closures and add up. flightSequence()
// returns the whole timetable as an array of `{ step, at }` — one pure function,
// exercised by tour.test.js — and the component's job shrinks to setting one
// timer per entry and cancelling them all under a sequence token.
// =============================================================================

/**
 * Every duration in the choreography, in milliseconds, from design/README.md §1.
 */
export const TIMING = {
    // 1. Fit: the whole route on screen, centred on the middle of its arc.
    fitMs: 900,
    fitAltitude: 2.4,

    // 2. Dive to the origin airport. The delay is measured from the fit
    // STARTING, not from it finishing, so the two moves overlap slightly and
    // the camera never actually stops.
    diveDelayMs: 1300,
    // On the very first route the fit is instantaneous (there is nothing on
    // screen yet to move away from), so the dive waits out its own beat instead
    // of the fit's.
    instantDiveDelayMs: 1100,
    diveMs: 1700,
    diveAltitude: 0.42,

    // 3. The flight itself, again measured from the dive starting: the camera
    // holds over the origin for the remainder while the arc's dashes animate.
    flightDelayMs: 2500,
    flightMs: 3600,
}

/**
 * How long the camera sits on the destination before the tour moves on.
 *
 * design/README.md §1 lists this as the prototype's "Motion" tweak, and
 * docs/PLAN.md has the user-facing setting arriving with the settings screen.
 * Until it does, the tour asks for `DEFAULT_MOTION` and the other two are
 * reachable by passing a name — there is no second copy of these numbers to
 * keep in step when that setting lands.
 */
export const DWELL_MS = {
    calm: 5400,
    balanced: 4400,
    lively: 3300,
}

export const DEFAULT_MOTION = 'balanced'

/**
 * The timetable for one route, oldest first.
 *
 * `at` is milliseconds from the moment the sequence starts; `durationMs` is how
 * long that camera move itself takes. The last entry is not a camera move at
 * all — `advance` is the tour handing over to the next route, and a caller that
 * is not touring (a single route, or a user who has just tapped a chip) simply
 * ignores it.
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
 * The route the tour shows after this one, wrapping at the end of the list.
 *
 * An empty list answers 0 rather than NaN: the home screen renders its "nothing
 * orbiting yet" state in that case and never starts a tour, but a timer that
 * has already been scheduled can still land here on the way out.
 */
export function nextIndex(current, count) {
    if (count <= 0) {
        return 0
    }

    return (current + 1) % count
}
