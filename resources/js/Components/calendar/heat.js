// =============================================================================
// The calendar's heat scale
// =============================================================================
// design/README.md §3: every day cell is painted by where its fare sits between
// the month's own cheapest and dearest, on a five-stop green → red ramp.
//
// WHY THIS IS A PLAIN MODULE AND NOT A COMPONENT. It is arithmetic — one number
// in, one colour out — and keeping it out of a `.vue` file buys three things:
// the linter reads it as ordinary JavaScript, the grid and the legend and the
// day sheet all get the SAME colour for the same fare because they call the
// same function, and it can be exercised from `node` without a DOM.
//
// WHY THE COLOURS ARE LITERALS IN A PROJECT THAT BANS THEM. tokens.css owns the
// PALETTE — the surfaces, inks and tones that change between the dark and light
// themes. This is not palette, it is a data-visualisation ramp: the stops are
// fixed by design/README.md, they carry meaning (green is cheap, red is dear)
// rather than theme, and re-tinting them per theme would mean the same €58 is
// two different colours depending on a setting. So the ramp is the same in both
// themes, which is also why its FOREGROUND has to be pinned here beside it —
// see HEAT_INK.
// =============================================================================

/**
 * The five stops, as RGB triples, in design/README.md's order.
 *
 * Exported so the legend can build its gradient from the same list the cells
 * are painted from — a legend that says "green ↔ red" while the grid draws
 * something else is worse than no legend.
 */
export const HEAT_STOPS = [
    [121, 184, 148],
    [176, 202, 150],
    [236, 217, 168],
    [228, 166, 116],
    [214, 112, 76],
]

/**
 * The text colour that sits ON a heat cell.
 *
 * The ramp above is light and mid-toned at every stop, so a cell's own label
 * has to be dark in BOTH themes. `var(--ink)` cannot do that: it is near-white
 * in the dark theme, which is where a €58 on pale green becomes unreadable.
 */
export const HEAT_INK = 'rgb(23, 48, 40)'

/**
 * The colour for `price`, interpolated across the month's `min`–`max`.
 *
 * Clamped at both ends, so a fare outside the range it was scaled against
 * (a stale cell mid-refresh) lands on an end stop rather than off the ramp.
 *
 * A month whose fares are all the same price has no range to interpolate
 * across; it collapses to the first stop, i.e. the only fare there is reads as
 * this month's cheapest, which it is.
 */
export function heatColour(price, min, max) {
    const span = max - min || 1
    const ratio = (price - min) / span
    const t = Number.isFinite(ratio) ? Math.min(1, Math.max(0, ratio)) : 0

    // Where t lands on the ramp: which pair of stops, and how far between them.
    const position = t * (HEAT_STOPS.length - 1)
    const index = Math.min(HEAT_STOPS.length - 2, Math.floor(position))
    const fraction = position - index

    const from = HEAT_STOPS[index]
    const to = HEAT_STOPS[index + 1]
    const channel = (k) => Math.round(from[k] + (to[k] - from[k]) * fraction)

    return `rgb(${channel(0)}, ${channel(1)}, ${channel(2)})`
}

/**
 * The whole ramp as a CSS gradient, for the legend bar.
 */
export function heatGradient(angle = '90deg') {
    const stops = HEAT_STOPS.map((stop) => `rgb(${stop.join(', ')})`)

    return `linear-gradient(${angle}, ${stops.join(', ')})`
}
