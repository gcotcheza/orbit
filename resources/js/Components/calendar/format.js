// =============================================================================
// Printing a fare
// =============================================================================
// docs/API.md: money crosses the API as EUROS, as a JSON number — `58` for a
// whole one and `57.45` for one with cents. There are no cents to divide by
// here, and the one thing this app must never do is print `€5745`.
//
// The design prints whole euros everywhere a fare appears (design/README.md
// §2–3), so this rounds rather than truncating: €57.45 is nearer €57 and
// €57.60 is nearer €58, and a fare that reads a euro cheaper than it is, is a
// small lie about a price.
//
// A twin of this function lives in `Components/route/format.js`. That is not an
// oversight: the route screens and the calendar screens are being written in
// parallel worktrees that may not edit each other's files, so a shared
// `lib/money.js` cannot be created by either of them without colliding. Merging
// the two is a one-line job for the DRY pass once the branches are integrated.
// =============================================================================

export function euro(value) {
    return `€${Math.round(value)}`
}
