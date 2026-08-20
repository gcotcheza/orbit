// The calendar's heat scale (design/README.md §3): each day cell is painted
// by where its fare sits between the month's cheapest and dearest, on a
// five-stop green -> red ramp.
//
// Plain module, not a component — shared arithmetic for the grid, legend
// and day sheet.
// Why: docs/BUSINESS-LOGIC.md §36.
//
// Colours are literals, not tokens.css palette — a fixed data-viz ramp,
// not a themed surface.
// Why: docs/BUSINESS-LOGIC.md §36.

/**
 * The five stops, as RGB triples, in design/README.md's order. Exported so
 * the legend builds its gradient from the same list the cells use.
 */
export const HEAT_STOPS = [
    [121, 184, 148],
    [176, 202, 150],
    [236, 217, 168],
    [228, 166, 116],
    [214, 112, 76],
]

/**
 * The text colour that sits ON a heat cell. `var(--ink)` can't be used:
 * it's near-white in dark mode, unreadable on the light/mid-toned ramp.
 */
export const HEAT_INK = 'rgb(23, 48, 40)'

/**
 * The colour for `price`, interpolated across the month's `min`–`max`,
 * clamped at both ends. A flat month (min === max) collapses to the first stop.
 */
export function heatColour(price, min, max) {
    const span = max - min || 1
    const ratio = (price - min) / span
    const t = Number.isFinite(ratio) ? Math.min(1, Math.max(0, ratio)) : 0

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
