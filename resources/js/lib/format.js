// =============================================================================
// Printing the API's numbers, and the one date that travels with them
// =============================================================================
// ONE MODULE, AND IT USED TO BE THREE. `Components/route/format.js`,
// `Components/calendar/format.js` and `Components/globe/format.js` each carried
// their own copy of "print a fare" because the screens that needed it were
// written in parallel worktrees that could not create a shared file without
// colliding. Every one of them said so in a comment and every one of them
// pointed at this pass. The three are folded together here.
//
// docs/API.md is the reason both functions exist at all:
//   - money crosses the API as EUROS, as a JSON number — `58` for a whole one
//     and `57.45` for one with cents. There are no cents to divide by, and the
//     one thing this app must never do is print `€5745`;
//   - `null` means "not known yet", NEVER zero — a route polled for the first
//     time tomorrow has no price, and "€0" would be a lie about a free flight;
//   - `pctBelow` is SIGNED: negative means the fare is above its usual price.
// =============================================================================

/**
 * A fare, as the design prints it: `€58`.
 *
 * ROUNDED, not truncated. The design prints whole euros everywhere a fare
 * appears (design/README.md §2–3): €57.45 is nearer €57 and €57.60 is nearer
 * €58, and a fare that reads a euro cheaper than it is, is a small lie about a
 * price. The spotlight card's 27 px number has room for a price and not for a
 * price and a decimal either — and a fare that moved by 45 cents overnight has
 * not moved.
 *
 * NULL IN, NULL OUT — the globe's version of this function, which is the one
 * kept. Callers decide what "no fare" looks like on their screen, because they
 * do not agree: the rail and the boarding-pass stub print an em dash, the
 * spotlight card prints "No fare yet", and the route detail prints its own
 * sentence. What none of them may do is print €0.
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
 * `pctBelow` as the sentence under the price.
 *
 * `33` → "33% below usual", `-14` → "14% above usual", `0` → "at its usual
 * price" (which is neither, and reading "0% below usual" is a small stumble
 * every time). `null` — no fare yet, or no statistics for this route — has no
 * sentence at all, and the caller shows the tracking note instead.
 *
 * @param {number|null|undefined} pctBelow
 * @returns {string|null}
 */
/**
 * A DEPARTURE date, as the price screens print it: `2026-09-09` → `Wed, Sep 9`.
 *
 * WHY THIS IS NOT `Components/calendar/month.js`'s `dayLabel`, which formats the
 * same kind of string. That one writes "September 9" for a grid the reader is
 * already looking at a month of — the month is on the screen above it and the
 * weekday is the column the cell is in. This one is read out of any context at
 * all, under a fare, so it carries the two facts that context was supplying:
 * WHICH MONTH, and WHICH DAY OF THE WEEK. "€75 · Wed, Sep 9" and "€75 · Sat,
 * Sep 12" are different offers to a person with a job, and the calendar's
 * phrasing cannot say so.
 *
 * PARSED BY PARTS AND FORMATTED IN UTC, for the reason month.js gives at
 * length: `new Date('2026-09-09')` is UTC midnight, and asking it for a weekday
 * through a viewer's own timezone answers Tuesday for anyone west of London.
 * The locale is pinned for the reason the design pins it — one user, one
 * language, and a device set to de-DE must not render a screen nobody signed
 * off.
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

// =============================================================================
// HOW OLD A PRICE IS
// =============================================================================
// `foundAt` is when the fare was FOUND, which is a third date on top of the two
// this app is already careful about (docs/API.md): not the day you fly, and not
// the day we looked. Orbit's prices come from a cache of other people's
// searches, so a figure fetched this morning can have been found last Thursday
// — the owner caught the app showing €36 where the live cheapest was €56, and
// both were true. These two functions are how a screen says so.
//
// ONE IMPLEMENTATION FOR BOTH SCREENS. The day sheet prints the age under every
// price and the route detail prints it only past a threshold, which are two
// different rules over the same arithmetic — and two copies of "how long ago
// was this" is how one of them ends up saying "1 days ago".
// =============================================================================

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
 * How many hours ago something was seen — for a caller that has a threshold
 * rather than a sentence. Null when there is no timestamp to measure.
 *
 * NOT ROUNDED. The one caller compares it against a bound, and rounding first
 * would make a fare 23 hours and 40 minutes old "24" and trip a rule meant for
 * yesterday's prices.
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
 * "just now", "3 hours ago", "4 days ago" — the age of a price, as a person
 * says it.
 *
 * NULL IN, NULL OUT, and the caller prints NOTHING. This is the rule the whole
 * feature rests on: `foundAt` is null when Orbit does not know how old a price
 * is (every row written before the column existed, and any provider that will
 * not say), and the one thing that must never happen is a made-up age. "Seen
 * just now" over a fare of unknown vintage is worse than the silence this
 * feature replaced, because it is a claim rather than an omission.
 *
 * UNDER AN HOUR IS "JUST NOW" rather than "0 hours ago" or "43 minutes ago".
 * Nothing on these screens moves on a scale where forty minutes matters — the
 * poll is daily and the provider's cache turns over in days — so minute
 * precision would be false precision, and it would make the commonest state of
 * a freshly polled route the noisiest line on the sheet.
 *
 * HOURS UP TO A DAY, THEN DAYS. "26 hours ago" is a number a reader has to do
 * arithmetic on; "1 day ago" is the same fact already reduced. The switch is at
 * exactly 24 hours, which is also the poll's own period, so a route polled this
 * morning reads in hours and one that missed a morning reads in days.
 *
 * A FUTURE TIMESTAMP READS AS "just now". Clock skew between this browser and
 * the server is a fact of life and small; "in 2 hours" under a price is a bug
 * report, while "just now" is what a two-minute skew actually means.
 *
 * @param {string|null|undefined} iso ISO 8601 WITH an offset — the API sends
 *                                    one, and a bare local time would be read
 *                                    in the viewer's zone
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
 * Fill the date holes in a booking template.
 *
 * The API sends hand-off URLs with named holes in them — `{yymmdd}` for
 * Skyscanner's path and `{ddmm}` for Aviasales' params — because the two sites
 * want the parts of a date in different orders and different lengths
 * (docs/API.md). NAMED RATHER THAN A SINGLE `{date}`, so that this function is
 * pure date formatting and knows nothing about either site: it fills whichever
 * holes it finds and has no opinion about which URL it is looking at.
 *
 * STRING SURGERY AND NOT A `Date`, for the reason Components/calendar/month.js
 * opens with: `date` is a calendar day with no time and no zone, and routing it
 * through `new Date(...)` re-reads it in the viewer's own timezone — which
 * books the day before for everybody west of London.
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
