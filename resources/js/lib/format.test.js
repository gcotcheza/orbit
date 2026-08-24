// `seenLabel` prints a fare's age; failure modes are quiet ones (off-by-one,
// fabricated ages, NaN), so the clock is pinned here (docs/BUSINESS-LOGIC.md §36).
import { describe, expect, it } from 'vitest'

import { dayLabel, departureLabel, hoursSince, seenLabel, withDateTokens } from './format'

/** A fixed "now", with an offset, exactly as the API sends its timestamps. */
const NOW = new Date('2026-08-15T12:00:00+02:00').getTime()

/** `hours` before NOW, as the ISO string the API would have sent. */
function ago(hours) {
    return new Date(NOW - hours * 3_600_000).toISOString()
}

describe('seenLabel', () => {
    // DO NOT fabricate an age: `foundAt` null means "unknown", and "seen just
    // now" over an unknown-vintage fare is worse than nothing (docs/BUSINESS-LOGIC.md §36).
    it.each([[null], [undefined], ['not a date']])('says nothing at all for %s', (value) => {
        expect(seenLabel(value, NOW)).toBeNull()
    })

    // Under an hour reads as "just now": nothing here moves on a per-minute
    // scale (daily poll, day-granularity cache) (docs/BUSINESS-LOGIC.md §36).
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

    // Boundary is exactly 24h, the poll's own period: off-by-one reports a
    // 25-hour-old fare in hours nobody adds up (docs/BUSINESS-LOGIC.md §36).
    it('switches from hours to days at exactly a day', () => {
        expect(seenLabel(ago(23.99), NOW)).toBe('23 hours ago')
        expect(seenLabel(ago(24), NOW)).toBe('1 day ago')
        expect(seenLabel(ago(25), NOW)).toBe('1 day ago')
    })

    it('counts whole days, and says "day" once', () => {
        expect(seenLabel(ago(24 * 4), NOW)).toBe('4 days ago')
        // Rounds down: 4d20h stays "4 days" (docs/BUSINESS-LOGIC.md §36).
        expect(seenLabel(ago(24 * 4 + 20), NOW)).toBe('4 days ago')
    })

    // Clock skew is real and small: a future timestamp reads as "just now",
    // not a negative age (docs/BUSINESS-LOGIC.md §36).
    it('reads a future timestamp as just now rather than as a negative age', () => {
        expect(seenLabel(ago(-2), NOW)).toBe('just now')
    })

    // API timestamps carry their own offset: the same instant written two
    // ways must produce the same age (docs/BUSINESS-LOGIC.md §36).
    it('reads the offset rather than the wall-clock digits', () => {
        // NOW is 12:00+02:00, i.e. 10:00 UTC. Both of these name 08:00 UTC.
        const utc = seenLabel('2026-08-15T08:00:00+00:00', NOW)
        const local = seenLabel('2026-08-15T10:00:00+02:00', NOW)

        expect(utc).toBe('2 hours ago')
        expect(local).toBe(utc)

        // Trap: identical wall-clock digits with a different offset are a
        // different instant (docs/BUSINESS-LOGIC.md §36).
        expect(seenLabel('2026-08-15T08:00:00+02:00', NOW)).toBe('4 hours ago')
    })
})

describe('hoursSince', () => {
    it('has nothing to measure without a timestamp', () => {
        expect(hoursSince(null, NOW)).toBeNull()
        expect(hoursSince('the seventh of never', NOW)).toBeNull()
    })

    // NOT rounded: its 24h-threshold caller must not see 23h40m round up to
    // "24" and trip on yesterday's prices (docs/BUSINESS-LOGIC.md §36).
    it('answers a fraction rather than a rounded count', () => {
        expect(hoursSince(ago(23.75), NOW)).toBeCloseTo(23.75, 5)
    })

    it('is negative for a timestamp in the future', () => {
        expect(hoursSince(ago(-3), NOW)).toBeCloseTo(-3, 5)
    })
})

describe('dayLabel', () => {
    it('prints a calendar day the way the design does', () => {
        expect(dayLabel('2026-09-09')).toBe('Wed, Sep 9')
    })

    // A day WE LOOKED arrives as a full timestamp; the date is taken as written, in its own
    // offset, rather than re-read through a timezone that can land on the day before.
    it('cuts a timestamp down to the day it was written in', () => {
        expect(dayLabel('2026-08-15T23:40:00+02:00')).toBe('Sat, Aug 15')
        expect(dayLabel('2027-01-01T00:10:00Z')).toBe('Fri, Jan 1')
    })

    it.each([[null], [undefined]])('says nothing at all for %s', (value) => {
        expect(dayLabel(value)).toBeNull()
    })

    // `departureLabel` is the same printing under the name of its meaning: the two must not drift.
    it('is exactly what departureLabel prints', () => {
        for (const iso of ['2026-09-09', '2026-08-15', '2027-01-01']) {
            expect(departureLabel(iso)).toBe(dayLabel(iso))
        }

        expect(departureLabel('2026-09-09')).toBe('Wed, Sep 9')
        expect(departureLabel(null)).toBeNull()
    })
})

describe('withDateTokens', () => {
    const AVIASALES = 'https://www.aviasales.com/search/AMS{ddmm}OPO1?marker=123'
    const SKYSCANNER = 'https://www.skyscanner.nl/transport/flights/ams/opo/{yymmdd}/'

    // Each site wants date parts in a different order/length, hence named
    // holes; swapping them opens a link for the wrong day (docs/BUSINESS-LOGIC.md §36).
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

    // String surgery, not `new Date(...)`: parsing as UTC and reading back in
    // a western timezone silently lands on the day before (docs/BUSINESS-LOGIC.md §36).
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
