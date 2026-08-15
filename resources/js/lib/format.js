// =============================================================================
// Printing the API's numbers
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
