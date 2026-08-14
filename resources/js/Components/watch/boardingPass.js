// =============================================================================
// The two pieces of boarding-pass dressing
// =============================================================================
// design/README.md §5 draws each watched route as a boarding pass: a flag
// swatch next to the destination city, and a flight number in the eyebrow.
// Neither is data — there is no flight and no airline — so both are DERIVED
// here rather than invented by the server, and both are PURE FUNCTIONS of what
// the API already sends.
//
// PURE MATTERS. A random flight number would change on every render, which on
// a boarding pass reads as the app losing track of which route it is showing;
// and a swatch computed in a component's setup would be recomputed per row per
// render for no reason. Same input, same output, always.
// =============================================================================

// -- Flags --------------------------------------------------------------------
//
// CSS GRADIENTS RATHER THAN IMAGES OR EMOJI. An image per country is 28 HTTP
// requests for 22×15 px of decoration; flag emoji are the obvious alternative
// and are the wrong one — Windows has no flag glyphs at all and renders the
// regional-indicator pair as two grey letters, which is exactly the platform
// somebody will open this on from a laptop.
//
// EVERY COUNTRY IN THE SEED SET IS HERE — the 28 in
// database/seeders/data/european_destinations.php, keyed by the `countryCode`
// the API sends. They are APPROXIMATIONS at 22×15: stripes and crosses are
// exact, charges (Albania's eagle, Turkey's crescent, the Union Jack's
// saltires) are simplified to what survives at that size. Portugal keeps its
// dot because the design's own prototype drew one.
//
// A country not listed gets a neutral slate swatch rather than nothing, so a
// destination added by hand still looks like a boarding pass.

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

// -- Flight numbers -----------------------------------------------------------

/**
 * The `FW###` in the boarding pass eyebrow, derived from the route code.
 *
 * THERE IS NO REAL FLIGHT. A watched route is a city pair and a price, not a
 * booking, and the design's flight number is set dressing that makes the card
 * read as a boarding pass. It is derived rather than random so that AMS-LIS is
 * FW-something-the-same on every render, on every device and after every
 * reload — a number that changed between two glances would be the one detail
 * that gives the whole card away as fiction.
 *
 * The hash is the design prototype's own: a 31-multiplier rolling sum, kept
 * small enough that the arithmetic is exact in a double.
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
