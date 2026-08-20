// Flag swatch and flight number are derived here, not sent by the server —
// neither is real data, and both must be pure (same input, same output) so a
// boarding pass doesn't visibly change between renders.
// Why: docs/BUSINESS-LOGIC.md §36.

// CSS gradients, not images/emoji: an image per country is 28 requests for
// 22×15px, and Windows renders flag emoji as grey letters. Approximated at
// that size; unlisted countries fall back to a neutral slate swatch.
// Why: docs/BUSINESS-LOGIC.md §36.

const NEUTRAL = 'linear-gradient(135deg, #8b93ad, #5d6883)'

/** Horizontal bands, top to bottom, in equal thirds. */
function tricolourH(top, middle, bottom) {
    return `linear-gradient(180deg, ${top} 0 33.34%, ${middle} 33.34% 66.67%, ${bottom} 66.67% 100%)`
}

/** Vertical bands, left to right, in equal thirds. */
function tricolourV(left, middle, right) {
    return `linear-gradient(90deg, ${left} 0 33.34%, ${middle} 33.34% 66.67%, ${right} 66.67% 100%)`
}

/**
 * A Nordic cross: vertical bar offset to the hoist, horizontal bar centred.
 * Two solid-colour layers over a field, which is exactly what the flag is.
 */
function nordic(field, cross) {
    return [
        `linear-gradient(${cross}, ${cross}) 34% 0 / 22% 100% no-repeat`,
        `linear-gradient(${cross}, ${cross}) 0 50% / 100% 24% no-repeat`,
        field,
    ].join(', ')
}

const FLAGS = {
    // Nordic crosses.
    DK: nordic('#c8102e', '#f4f4f4'),
    FI: nordic('#f4f4f4', '#002f6c'),
    IS: [
        'linear-gradient(#dc1e35, #dc1e35) 34% 0 / 9% 100% no-repeat',
        'linear-gradient(#dc1e35, #dc1e35) 0 50% / 100% 10% no-repeat',
        nordic('#02529c', '#f4f4f4'),
    ].join(', '),
    NO: [
        'linear-gradient(#ba0c2f, #ba0c2f) 34% 0 / 9% 100% no-repeat',
        'linear-gradient(#ba0c2f, #ba0c2f) 0 50% / 100% 10% no-repeat',
        nordic('#00205b', '#f4f4f4'),
    ].join(', '),
    SE: nordic('#005293', '#fecb00'),

    // Horizontal bands.
    AT: tricolourH('#ed2939', '#f4f4f4', '#ed2939'),
    DE: tricolourH('#111111', '#dd0000', '#ffce00'),
    EE: tricolourH('#0072ce', '#111111', '#f4f4f4'),
    EG: tricolourH('#ce1126', '#f4f4f4', '#111111'),
    HR: tricolourH('#ff0000', '#f4f4f4', '#171796'),
    HU: tricolourH('#cd2a3e', '#f4f4f4', '#436f4d'),
    NL: tricolourH('#ae1c28', '#f4f4f4', '#21468b'),
    SI: tricolourH('#f4f4f4', '#0000a0', '#e60000'),
    // Spain's yellow band is half the flag, not a third.
    ES: 'linear-gradient(180deg, #c60b1e 0 26%, #ffc400 26% 74%, #c60b1e 74% 100%)',
    // Latvia's white stripe is a fifth.
    LV: 'linear-gradient(180deg, #9e3039 0 40%, #f4f4f4 40% 60%, #9e3039 60% 100%)',
    PL: 'linear-gradient(180deg, #f4f4f4 0 50%, #dc143c 50% 100%)',

    // Vertical bands.
    FR: tricolourV('#2a3d8f', '#f4f4f4', '#d62b3a'),
    IE: tricolourV('#169b62', '#f4f4f4', '#ff883e'),
    IT: tricolourV('#1a8a4b', '#f4f4f4', '#d62b3a'),
    MT: 'linear-gradient(90deg, #f4f4f4 0 50%, #cf142b 50% 100%)',
    PT: [
        // The armillary sphere, as the design's prototype drew it: one dot on
        // the seam between the two fields.
        'radial-gradient(circle at 42% 50%, #ffd24a 0 17%, transparent 18%)',
        'linear-gradient(90deg, #1b6b3a 0 42%, #d4202c 42% 100%)',
    ].join(', '),

    // Crosses and charges.
    CH: [
        'linear-gradient(#f4f4f4, #f4f4f4) 50% / 34% 100% no-repeat',
        'linear-gradient(#f4f4f4, #f4f4f4) 50% / 100% 34% no-repeat',
        '#d52b1e',
    ].join(', '),
    GB: [
        'linear-gradient(#cf142b, #cf142b) 50% / 100% 20% no-repeat',
        'linear-gradient(#cf142b, #cf142b) 50% / 18% 100% no-repeat',
        'linear-gradient(45deg, transparent 43%, #f4f4f4 43% 57%, transparent 57%)',
        'linear-gradient(-45deg, transparent 43%, #f4f4f4 43% 57%, transparent 57%)',
        '#00247d',
    ].join(', '),
    GR: [
        // The canton, over the nine stripes.
        'linear-gradient(#0d5eaf, #0d5eaf) 0 0 / 45% 56% no-repeat',
        'repeating-linear-gradient(180deg, #0d5eaf 0 11.1%, #f4f4f4 11.1% 22.2%)',
    ].join(', '),
    CZ: [
        'linear-gradient(135deg, #11457e 0 40%, transparent 40%)',
        'linear-gradient(180deg, #f4f4f4 0 50%, #d7141a 50% 100%)',
    ].join(', '),
    CY: [
        'linear-gradient(#d57500, #d57500) 50% 42% / 40% 26% no-repeat',
        '#f7f7f7',
    ].join(', '),
    TR: [
        'radial-gradient(circle at 38% 50%, #f4f4f4 0 26%, transparent 27%)',
        'radial-gradient(circle at 46% 50%, #e30a17 0 22%, transparent 23%)',
        '#e30a17',
    ].join(', '),
    MA: [
        'radial-gradient(circle at 50% 50%, transparent 0 16%, #006233 17% 26%, transparent 27%)',
        '#c1272d',
    ].join(', '),
    AL: [
        'radial-gradient(circle at 50% 50%, #111111 0 24%, transparent 25%)',
        '#d02c2c',
    ].join(', '),
}

/**
 * A `background` value for a 22×15 flag swatch.
 *
 * @param {string|null|undefined} countryCode ISO-3166 alpha-2, as the API sends it
 * @returns {string}
 */
export function flagFor(countryCode) {
    return FLAGS[countryCode] ?? NEUTRAL
}

/**
 * The `FW###` in the boarding pass eyebrow, derived from the route code. No real flight — set dressing for the card. Derived, not random, so AMS-LIS shows the same number on every render/device/reload
 * (a changing number would give the fiction away). Hash is the design prototype's own 31-multiplier sum (docs/BUSINESS-LOGIC.md §36).
 *
 * @param {string} code "AMS-LIS"
 * @returns {string} "FW304"
 */
export function flightNumberFor(code) {
    let hash = 0

    for (let index = 0; index < code.length; index++) {
        hash = (hash * 31 + code.charCodeAt(index)) % 100000
    }

    // 100-999, so it is always three digits and never reads as a typo.
    return `FW${100 + (hash % 900)}`
}
