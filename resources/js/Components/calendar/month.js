// The calendar's date arithmetic, kept away from the components that draw it. Everything is
// UTC and the locale is pinned to en-US, deliberately (docs/BUSINESS-LOGIC.md §36).

const LOCALE = 'en-US'

/**
 * The column headings, Monday first (design/README.md §3: MO–SU).
 */
export const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU']

/**
 * `YYYY-MM-DD` → the three numbers in it. No Date object involved.
 */
function parseDay(iso) {
    const [year, month, day] = iso.split('-').map(Number)

    return { year, month, day }
}

/**
 * `YYYY-MM` → the two numbers in it.
 */
export function parseMonth(key) {
    const [year, month] = key.split('-').map(Number)

    return { year, month }
}

/**
 * The two numbers back into `YYYY-MM`, which is the shape the API's `month` query parameter takes.
 */
export function monthKey(year, month) {
    return `${year}-${String(month).padStart(2, '0')}`
}

/**
 * The month `delta` months away from `key`. Negative goes back. `Date.UTC` normalises December + 1
 * into January of the next year, so the year-boundary case needs no arithmetic of its own here.
 */
export function addMonths(key, delta) {
    const { year, month } = parseMonth(key)
    const shifted = new Date(Date.UTC(year, month - 1 + delta, 1))

    return monthKey(shifted.getUTCFullYear(), shifted.getUTCMonth() + 1)
}

/**
 * The month the app opens on, in the viewer's own calendar — the ONE place a local date is the
 * right answer, because "which month is it" is about the person holding the phone.
 */
export function currentMonthKey(now = new Date()) {
    return monthKey(now.getFullYear(), now.getMonth() + 1)
}

/**
 * `2026-06` → `June 2026`, for the subtitle and the month navigator.
 */
export function monthLabel(key) {
    const { year, month } = parseMonth(key)

    return new Intl.DateTimeFormat(LOCALE, {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, month - 1, 1)))
}

/**
 * `2026-06-11` → `June 11` (the "cheapest this month" banner) or `June 11, 2026` (the day sheet,
 * which is read on its own).
 */
export function dayLabel(iso, { withYear = false } = {}) {
    const { year, month, day } = parseDay(iso)

    return new Intl.DateTimeFormat(LOCALE, {
        month: 'long',
        day: 'numeric',
        year: withYear ? 'numeric' : undefined,
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, month - 1, day)))
}

/**
 * How many empty cells the month opens with, 0 (starts on a Monday) to 6 (starts on a Sunday).
 * `getUTCDay()` is Sunday-first (0–6) and the design's grid is Monday-first, hence the rotation.
 */
function leadingBlanks(year, month) {
    const firstDay = new Date(Date.UTC(year, month - 1, 1)).getUTCDay()

    return (firstDay + 6) % 7
}

/**
 * The cells of one month, in reading order. Built from the calendar and filled from the API,
 * never the other way round: `days` arrives with gaps missing entirely.
 */
export function buildMonthGrid(key, days = []) {
    const { year, month } = parseMonth(key)
    const fares = new Map(days.map((day) => [day.date, day]))

    // Day 0 of the NEXT month is the last day of this one.
    const length = new Date(Date.UTC(year, month, 0)).getUTCDate()

    const cells = []

    for (let i = 0; i < leadingBlanks(year, month); i += 1) {
        cells.push({ key: `blank-${i}`, blank: true })
    }

    for (let day = 1; day <= length; day += 1) {
        const date = `${key}-${String(day).padStart(2, '0')}`

        cells.push({ key: date, blank: false, day, date, fare: fares.get(date) ?? null })
    }

    return cells
}
