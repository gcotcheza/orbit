// The tour's timetable, pinned as absolute moments rather than as the
// relative delays design/README.md §1 states them as (docs/BUSINESS-LOGIC.md §36).
import { describe, expect, it } from 'vitest'
import { DEFAULT_MOTION, DWELL_MS, TIMING, flightSequence, nextIndex } from './tour'

/** The `at` of one step, by name. */
const at = (sequence, step) => sequence.find((entry) => entry.step === step).at

describe('flightSequence', () => {
    it('runs fit, dive, fly, advance — in that order and never backwards', () => {
        const sequence = flightSequence()

        expect(sequence.map((entry) => entry.step)).toEqual(['fit', 'dive', 'fly', 'advance'])

        for (let i = 1; i < sequence.length; i++) {
            expect(sequence[i].at).toBeGreaterThan(sequence[i - 1].at)
        }
    })

    it('places every step at the moment design/README.md §1 asks for', () => {
        const sequence = flightSequence()

        expect(at(sequence, 'fit')).toBe(0)
        expect(at(sequence, 'dive')).toBe(1300)
        // 1300 + 2500.
        expect(at(sequence, 'fly')).toBe(3800)
        // 3800 + 3600 flying + 4400 dwelling on the destination.
        expect(at(sequence, 'advance')).toBe(11800)
    })

    it('skips the opening move on the first route, and only that', () => {
        const first = flightSequence({ instant: true })

        expect(first[0].durationMs).toBe(0)
        // The dive still waits out a beat — there is a globe to look at even
        // when nothing moved to reveal it.
        expect(at(first, 'dive')).toBe(TIMING.instantDiveDelayMs)
        expect(at(first, 'fly')).toBe(3600)

        // Everything after the fit is the same length of film either way.
        const later = flightSequence()
        expect(at(first, 'advance') - at(first, 'dive')).toBe(at(later, 'advance') - at(later, 'dive'))
    })

    it('spends the motion setting on the dwell and nothing else', () => {
        const balanced = flightSequence({ motion: 'balanced' })
        const calm = flightSequence({ motion: 'calm' })
        const lively = flightSequence({ motion: 'lively' })

        expect(at(calm, 'fly')).toBe(at(balanced, 'fly'))
        expect(at(lively, 'fly')).toBe(at(balanced, 'fly'))

        expect(at(calm, 'advance') - at(calm, 'fly')).toBe(TIMING.flightMs + DWELL_MS.calm)
        expect(at(lively, 'advance') - at(lively, 'fly')).toBe(TIMING.flightMs + DWELL_MS.lively)
    })

    it('falls back to the default dwell for a motion setting it does not know', () => {
        // A motion value read back from storage is not to be trusted.
        expect(flightSequence({ motion: 'ludicrous' })).toEqual(flightSequence({ motion: DEFAULT_MOTION }))
    })
})

describe('nextIndex', () => {
    it('walks the list and wraps at the end', () => {
        expect(nextIndex(0, 3)).toBe(1)
        expect(nextIndex(1, 3)).toBe(2)
        expect(nextIndex(2, 3)).toBe(0)
    })

    it('stays put when there is one route, and stays sane when there are none', () => {
        expect(nextIndex(0, 1)).toBe(0)
        expect(nextIndex(0, 0)).toBe(0)
        expect(nextIndex(4, 0)).toBe(0)
    })
})
