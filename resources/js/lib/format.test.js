// =============================================================================
// Printing a fare's age
// =============================================================================
// `seenLabel` is the sentence under every price in the day sheet and beside the
// cheapest departure on the route detail, and it is the whole of what the
// freshness work SHOWS a person. The failure modes it has are all quiet ones:
// "1 days ago", "NaN hours ago", a made-up age over a fare whose age is
// unknown, or an off-by-one at the hours/days boundary that makes a
// twenty-five-hour-old price read as fresh.
//
// THE CLOCK IS INJECTED. Both functions take `now` so that every case here is a
// fixed pair of instants a reader can check on paper — a test that built its
// expectation from `Date.now()` would be re-deriving the answer the same way
// the code does and would agree with a bug.
//
// The euro/departure/usual-price helpers in this module are exercised by the
// component and e2e suites that print them; what is pinned here is the
// arithmetic that nothing else can see.
// =============================================================================
import { describe, expect, it } from 'vitest'

import { hoursSince, seenLabel, withDateTokens } from './format'

/** A fixed "now", with an offset, exactly as the API sends its timestamps. */
const NOW = new Date('2026-08-15T12:00:00+02:00').getTime()

/** `hours` before NOW, as the ISO string the API would have sent. */
function ago(hours) {
    return new Date(NOW - hours * 3_600_000).toISOString()
}

describe('seenLabel', () => {
    /*
     * THE RULE THE WHOLE FEATURE RESTS ON. `foundAt` is null when Orbit does
     * not know how old a price is — every row written before the column
     * existed, and any provider that will not say — and the one thing that must
     * never happen is a fabricated age. "Seen just now" over a fare of unknown
     * vintage is a CLAIM, and worse than the silence it would replace.
     */
    it.each([[null], [undefined], ['not a date']])('says nothing at all for %s', (value) => {
        expect(seenLabel(value, NOW)).toBeNull()
    })

    /*
     * Under an hour is "just now" rather than "0 hours ago" or a count of
     * minutes: nothing in this app moves on that scale — the poll is daily and
     * the provider's cache turns over in days — so minutes would be false
     * precision, on what is also the commonest state of a freshly polled route.
     */
    it.each([
        [0, 'just now'],
        [0.5, 'just now'],
        [0.99, 'just now'],
    ])('reads %s hours old as "%s"', (hours, expected) => {
        expect(seenLabel(ago(hours), NOW)).toBe(expected)
    })

    it('counts whole hours, and says "hour" once', () => {
        expect(seenLabel(ago(1), NOW)).toBe('1 hour ago')
        expect(seenLabel(ago(3), NOW)).toBe('3 hours ago')
        expect(seenLabel(ago(23.9), NOW)).toBe('23 hours ago')
    })

    /*
     * THE BOUNDARY, FROM BOTH SIDES. It is at exactly 24 hours, which is also
     * the poll's own period, so a route polled this morning reads in hours and
     * one that missed a morning reads in days. An off-by-one here is a
     * twenty-five-hour-old fare reporting itself in hours nobody adds up.
     */
    it('switches from hours to days at exactly a day', () => {
        expect(seenLabel(ago(23.99), NOW)).toBe('23 hours ago')
        expect(seenLabel(ago(24), NOW)).toBe('1 day ago')
        expect(seenLabel(ago(25), NOW)).toBe('1 day ago')
    })

    it('counts whole days, and says "day" once', () => {
        expect(seenLabel(ago(24 * 4), NOW)).toBe('4 days ago')
        // 4 days and 20 hours is still 4 days: rounding UP would make a fare
        // sound older than it is, which is the safe direction for a warning but
        // the wrong one for a fact.
        expect(seenLabel(ago(24 * 4 + 20), NOW)).toBe('4 days ago')
    })

    /*
     * Clock skew between a phone and the server is a fact of life and small.
     * "in 2 hours" under a price is a bug report; "just now" is what a
     * two-minute skew actually means.
     */
    it('reads a future timestamp as just now rather than as a negative age', () => {
        expect(seenLabel(ago(-2), NOW)).toBe('just now')
    })

    /*
     * The API sends timestamps in the owner's timezone WITH an offset. The same
     * instant written two ways has to produce the same sentence, or a summer
     * afternoon's fares would age by two hours at the boundary.
     */
    it('reads the offset rather than the wall-clock digits', () => {
        // NOW is 12:00+02:00, i.e. 10:00 UTC. Both of these name 08:00 UTC.
        const utc = seenLabel('2026-08-15T08:00:00+00:00', NOW)
        const local = seenLabel('2026-08-15T10:00:00+02:00', NOW)

        expect(utc).toBe('2 hours ago')
        expect(local).toBe(utc)

        /*
         * And the trap the offset protects against: the same wall-clock DIGITS
         * with a different offset are a different instant, two hours apart. A
         * reader that ignored the zone would call these the same age.
         */
        expect(seenLabel('2026-08-15T08:00:00+02:00', NOW)).toBe('4 hours ago')
    })
})

describe('hoursSince', () => {
    it('has nothing to measure without a timestamp', () => {
        expect(hoursSince(null, NOW)).toBeNull()
        expect(hoursSince('the seventh of never', NOW)).toBeNull()
    })

    /*
     * NOT ROUNDED, deliberately. Its one caller compares it against a 24-hour
     * threshold, and rounding first would make a fare 23 h 40 m old "24" and
     * trip a rule written for yesterday's prices.
     */
    it('answers a fraction rather than a rounded count', () => {
        expect(hoursSince(ago(23.75), NOW)).toBeCloseTo(23.75, 5)
    })

    it('is negative for a timestamp in the future', () => {
        expect(hoursSince(ago(-3), NOW)).toBeCloseTo(-3, 5)
    })
})

describe('withDateTokens', () => {
    const AVIASALES = 'https://www.aviasales.com/search/AMS{ddmm}OPO1?marker=123'
    const SKYSCANNER = 'https://www.skyscanner.nl/transport/flights/ams/opo/{yymmdd}/'

    /*
     * The two sites want the parts of a date in different orders and different
     * lengths, which is exactly why the holes are NAMED — and why getting one
     * of them backwards is a link that opens perfectly and searches the wrong
     * day. `1509` is 15 September, day first (Travelpayouts' documented DDMM).
     */
    it('fills the Aviasales hole with the day before the month', () => {
        expect(withDateTokens(AVIASALES, '2026-09-15')).toBe(
            'https://www.aviasales.com/search/AMS1509OPO1?marker=123',
        )
    })

    it('fills the Skyscanner hole with a two-digit year first', () => {
        expect(withDateTokens(SKYSCANNER, '2026-09-15')).toBe(
            'https://www.skyscanner.nl/transport/flights/ams/opo/260915/',
        )
    })

    /*
     * THE FIRST OF JANUARY, which is where a `new Date(...)` would betray
     * itself: `new Date('2027-01-01')` is UTC midnight, and reading it back
     * through a viewer's own timezone lands on 31 December for everybody west
     * of London — a link that books the day before, silently, for half the
     * planet. This is string surgery precisely so that cannot happen.
     */
    it('does not slide a date into another timezone', () => {
        expect(withDateTokens(AVIASALES, '2027-01-01')).toContain('AMS0101OPO1')
        expect(withDateTokens(SKYSCANNER, '2027-01-01')).toContain('/270101/')
    })

    /* A response from an older build carries no template; the caller drops the
       one action rather than rendering the word "null" into an href. */
    it('has no URL to give without a template or a date', () => {
        expect(withDateTokens(null, '2026-09-15')).toBeNull()
        expect(withDateTokens(AVIASALES, null)).toBeNull()
    })
})
