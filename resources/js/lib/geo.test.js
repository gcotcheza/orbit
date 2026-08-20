// Flight-arithmetic checks: values anyone can check by pointing, plus the
// antimeridian, which no on-screen glance would catch.
//
// Why: docs/BUSINESS-LOGIC.md §36.
import { describe, expect, it } from 'vitest'
import {
    FLIGHT_SEGMENTS,
    bearing,
    easeInOutQuad,
    flightAltitude,
    flightPose,
    greatCirclePoints,
    pathMidpoint,
} from './geo'

const AMS = { lat: 52.3105, lng: 4.7683 }
const LIS = { lat: 38.7742, lng: -9.1342 }

describe('bearing', () => {
    it('answers in compass degrees from the equator', () => {
        const origin = { lat: 0, lng: 0 }

        expect(bearing(origin, { lat: 10, lng: 0 })).toBeCloseTo(0, 6)
        expect(bearing(origin, { lat: 0, lng: 10 })).toBeCloseTo(90, 6)
        expect(bearing(origin, { lat: -10, lng: 0 })).toBeCloseTo(180, 6)
        expect(bearing(origin, { lat: 0, lng: -10 })).toBeCloseTo(270, 6)
    })

    it('points Amsterdam at Lisbon, south-west', () => {
        // 220.93° — quoted, not recomputed: a test that reruns the same
        // formula only proves the machine is deterministic.
        // Why: docs/BUSINESS-LOGIC.md §36.
        expect(bearing(AMS, LIS)).toBeCloseTo(220.93, 2)
    })

    it('is not simply the reverse bearing plus 180', () => {
        // Meridians converge — a bearing computed once and reused for the
        // whole flight would drift; flightPose() recomputes it per segment.
        // Why: docs/BUSINESS-LOGIC.md §36.
        expect(bearing(LIS, AMS)).toBeCloseTo(30.92, 2)
    })

    it('crosses the antimeridian the short way', () => {
        // Due east, over the date line. A naive `toLng - fromLng` makes this
        // 270° — the plane turns round and flies back across Asia.
        expect(bearing({ lat: 0, lng: 179 }, { lat: 0, lng: -179 })).toBeCloseTo(90, 6)
    })
})

describe('greatCirclePoints', () => {
    it('has one more point than it has segments, both endpoints included', () => {
        const path = greatCirclePoints(AMS, LIS)

        expect(path).toHaveLength(FLIGHT_SEGMENTS + 1)
        expect(path[0].lat).toBeCloseTo(AMS.lat, 9)
        expect(path[0].lng).toBeCloseTo(AMS.lng, 9)
        expect(path.at(-1).lat).toBeCloseTo(LIS.lat, 9)
        expect(path.at(-1).lng).toBeCloseTo(LIS.lng, 9)
    })

    it('bulges polewards of the straight lat/lng line, as a great circle does', () => {
        // Simple averaging gives 45.54°N; the sphere's shortest path runs a
        // touch further north — same effect that routes over Greenland.
        // Why: docs/BUSINESS-LOGIC.md §36.
        const middle = pathMidpoint(greatCirclePoints(AMS, LIS))

        expect(middle.lat).toBeCloseTo(45.75, 2)
        expect(middle.lat).toBeGreaterThan((AMS.lat + LIS.lat) / 2)
        expect(middle.lng).toBeCloseTo(-3.03, 2)
    })

    it('steps evenly across the date line rather than the long way round', () => {
        const path = greatCirclePoints({ lat: 0, lng: 170 }, { lat: 0, lng: -170 }, 4)

        expect(path.map((point) => Math.round(point.lng))).toEqual([170, 175, 180, -175, -170])
        expect(path.every((point) => Math.abs(point.lat) < 1e-9)).toBe(true)
    })

    it('degrades to the two endpoints when there is no path to interpolate', () => {
        // Same airport twice: the watchlist can't produce it, but a future
        // rounding error could. Answer is a short flight, not div-by-zero.
        // Why: docs/BUSINESS-LOGIC.md §36.
        expect(greatCirclePoints(AMS, { ...AMS })).toHaveLength(2)
    })
})

describe('flightAltitude', () => {
    it('climbs, cruises and descends deeper than it started', () => {
        expect(flightAltitude(0)).toBeCloseTo(0.42, 9)
        expect(flightAltitude(0.5)).toBeCloseTo(0.71, 9)
        expect(flightAltitude(1)).toBeCloseTo(0.2, 9)
    })

    it('never dips below the surface it is flying over', () => {
        for (let e = 0; e <= 1; e += 0.01) {
            expect(flightAltitude(e)).toBeGreaterThan(0.15)
        }
    })
})

describe('easeInOutQuad', () => {
    it('is pinned at both ends and symmetric about the middle', () => {
        expect(easeInOutQuad(0)).toBe(0)
        expect(easeInOutQuad(0.5)).toBeCloseTo(0.5, 9)
        expect(easeInOutQuad(1)).toBe(1)
        expect(easeInOutQuad(0.25) + easeInOutQuad(0.75)).toBeCloseTo(1, 9)
    })

    it('only ever moves forwards', () => {
        let previous = -1

        for (let t = 0; t <= 1; t += 0.02) {
            const eased = easeInOutQuad(t)
            expect(eased).toBeGreaterThan(previous)
            previous = eased
        }
    })
})

describe('flightPose', () => {
    const path = greatCirclePoints(AMS, LIS)

    it('starts over the origin and ends over the destination', () => {
        const takeOff = flightPose(path, 0)
        const landing = flightPose(path, 1)

        expect(takeOff.lat).toBeCloseTo(AMS.lat, 6)
        expect(takeOff.lng).toBeCloseTo(AMS.lng, 6)
        expect(takeOff.altitude).toBeCloseTo(0.42, 9)

        expect(landing.lat).toBeCloseTo(LIS.lat, 6)
        expect(landing.lng).toBeCloseTo(LIS.lng, 6)
        expect(landing.altitude).toBeCloseTo(0.2, 9)
    })

    it('is at the top of the climb and the middle of the path halfway through', () => {
        const cruise = flightPose(path, 0.5)

        expect(cruise.altitude).toBeCloseTo(0.71, 9)
        expect(cruise.lat).toBeCloseTo(pathMidpoint(path).lat, 6)
        expect(cruise.lng).toBeCloseTo(pathMidpoint(path).lng, 6)
    })

    it('turns as it goes, from the departure heading towards the arrival one', () => {
        expect(flightPose(path, 0).bearing).toBeCloseTo(220.93, 1)
        expect(flightPose(path, 1).bearing).toBeCloseTo(211.01, 1)
    })

    it('holds the camera still outside the flight rather than extrapolating', () => {
        // rAF doesn't fire exactly at the duration's end, so raw `t` often
        // overshoots 1 — clamped here so callers don't have to remember to.
        // Why: docs/BUSINESS-LOGIC.md §36.
        expect(flightPose(path, 1.4)).toEqual(flightPose(path, 1))
        expect(flightPose(path, -0.2)).toEqual(flightPose(path, 0))
    })

    it('stays on the short side of the date line mid-crossing', () => {
        const pacific = greatCirclePoints({ lat: 0, lng: 170 }, { lat: 0, lng: -170 }, 4)
        const crossing = flightPose(pacific, 0.5)

        expect(Math.abs(crossing.lng)).toBeCloseTo(180, 6)
        expect(crossing.bearing).toBeCloseTo(90, 6)
    })
})
