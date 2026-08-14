// =============================================================================
// Printing a fare
// =============================================================================
// docs/API.md: money crosses the API as EUROS, as a JSON number — `58` for a
// whole one and `57.45` for one with cents. There are no cents to divide by
// here, and the one thing this app must never do is print `€5745`.
//
// The design prints whole euros everywhere a fare appears (design/README.md
// §2–3), so this rounds rather than truncating.
//
// A twin of this function lives in `Components/calendar/format.js` — see the
// note there. The two are merged into one `lib/money.js` when the parallel
// branches are integrated.
// =============================================================================

export function euro(value) {
    return `€${Math.round(value)}`
}
