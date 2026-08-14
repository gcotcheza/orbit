// =============================================================================
// Turning the API's numbers into the home screen's words
// =============================================================================
// Two formatters, shared by the spotlight card and the route rail so that a
// fare reads the same in both. They live under Components/globe/ because that
// is who uses them today; the day a second screen needs them they graduate to
// resources/js/lib/ rather than being copied.
//
// docs/API.md is the reason both of these exist at all:
//   - money is euros as a JSON number, whole (58) or with cents (57.45), and
//     the design prints it rounded;
//   - `null` means "not known yet", NEVER zero — a route polled for the first
//     time tomorrow has no price, and "€0" would be a lie about a free flight;
//   - `pctBelow` is SIGNED: negative means the fare is above its usual price.
// =============================================================================

/**
 * A fare, as the design prints it: `€58`.
 *
 * Rounded, because the spotlight card's 27 px number has room for a price and
 * not for a price and a decimal — and because a fare that moved by 45 cents
 * overnight has not moved.
 */
export function euros(amount) {
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
