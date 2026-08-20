// Printing the API's numbers and the one date that travels with them: money crosses as EUROS,
// `null` means "not known yet" and never zero, and `pctBelow` is SIGNED (docs/API.md).

/**
 * A fare, as the design prints it: `€58`. ROUNDED, not truncated, and NULL IN, NULL OUT —
 * callers decide what "no fare" looks like, but none of them may print €0.
 *
 * @param {number|null|undefined} amount
 * @returns {string|null}
 */
export function euro(amount) {
    if (amount === null || amount === undefined) {
        return null
    }

    return `€${Math.round(amount)}`
}

/**
 * `pctBelow` as the sentence under the price: `33` → "33% below usual", `0` → "at its usual".
 *
 * @param {number|null|undefined} pctBelow
 * @returns {string|null}
 */
/**
 * A DEPARTURE date: `2026-09-09` → `Wed, Sep 9`. Parsed by parts, formatted in UTC, locale pinned.
 *
 * @param {string|null|undefined} iso `YYYY-MM-DD`
 * @returns {string|null}
 */
export function departureLabel(iso) {
    if (iso === null || iso === undefined) {
        return null
    }

    const [year, month, day] = iso.split('-').map(Number)

    return new Intl.DateTimeFormat('en-US', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, month - 1, day)))
}

export function usualPriceLabel(pctBelow) {
    if (pctBelow === null || pctBelow === undefined) {
        return null
    }

    const rounded = Math.round(pctBelow)

    if (rounded === 0) {
        return 'at its usual price'
    }

    return `${Math.abs(rounded)}% ${rounded > 0 ? 'below' : 'above'} usual`
}

// HOW OLD A PRICE IS. `foundAt` is a third date (docs/API.md): not the day you fly and not the
// day we looked. One implementation for both screens, which have two rules over one arithmetic.

/** The clock, in ms, as one place so the tests can pass their own. */
function elapsedMs(iso, now) {
    if (iso === null || iso === undefined) {
        return null
    }

    const then = new Date(iso).getTime()

    // `new Date('nonsense')` is NaN rather than a throw, and NaN arithmetic propagates silently
    // into "NaN hours ago".
    return Number.isNaN(then) ? null : now - then
}

/**
 * How many hours ago something was seen, for a caller with a threshold rather than a sentence.
 * NOT ROUNDED: rounding would make 23h40m "24" and trip a rule meant for yesterday.
 *
 * @param {string|null|undefined} iso
 * @param {number} [now] epoch ms, injectable for tests
 * @returns {number|null}
 */
export function hoursSince(iso, now = Date.now()) {
    const elapsed = elapsedMs(iso, now)

    return elapsed === null ? null : elapsed / 3_600_000
}

/**
 * "just now", "3 hours ago", "4 days ago" — the age of a price. NULL IN, NULL OUT: a made-up
 * age is worse than silence. Under an hour is "just now"; hours to a day, then days.
 *
 * @param {string|null|undefined} iso ISO 8601 WITH an offset
 * @param {number} [now] epoch ms, injectable for tests
 * @returns {string|null}
 */
export function seenLabel(iso, now = Date.now()) {
    const elapsed = elapsedMs(iso, now)

    if (elapsed === null) {
        return null
    }

    const hours = Math.floor(elapsed / 3_600_000)

    if (hours < 1) {
        return 'just now'
    }

    if (hours < 24) {
        return `${hours} ${hours === 1 ? 'hour' : 'hours'} ago`
    }

    const days = Math.floor(elapsed / 86_400_000)

    return `${days} ${days === 1 ? 'day' : 'days'} ago`
}

/**
 * Fill the date holes in a booking template — NAMED holes, so this is pure date formatting and
 * knows nothing about either site. String surgery, not a `Date`, which re-reads the zone.
 *
 * @param {string|null|undefined} template
 * @param {string|null|undefined} iso `YYYY-MM-DD`
 * @returns {string|null}
 */
export function withDateTokens(template, iso) {
    if (!template || !iso) {
        return null
    }

    const [year, month, day] = iso.split('-')

    return template
        .replace('{yymmdd}', `${year.slice(2)}${month}${day}`)
        .replace('{ddmm}', `${day}${month}`)
}
