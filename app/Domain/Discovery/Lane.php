<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * WHICH ARGUMENT A DISCOVERY IS MAKING — and they are two different claims about
 * two different populations, which is the only reason this enum exists.
 *
 * ABSOLUTE  "€18 to Vilnius is a steal, period." The claim is about the fare
 *           against EVERY OTHER FARE IN THE SWEEP, ranked by what a kilometre
 *           buys. It needs no knowledge of Vilnius: 13.1 m€/km is remarkable
 *           whoever you are and wherever you were going.
 *
 * RELATIVE  "€60 to Dublin is a steal FOR DUBLIN." The claim is about the fare
 *           against WHAT THIS ROUTE USUALLY COSTS, and it is unavailable to the
 *           absolute lane by construction — Dublin at 750 km cannot clear a
 *           30 m€/km floor at any price a person would call cheap, because the
 *           hop is short and the floor is a ratio.
 *
 * =============================================================================
 * WHY THE SECOND LANE COULD NOT BE BUILT FROM THE SWEEP ALONE
 * =============================================================================
 * The obvious cheap version is a distance-band baseline: bucket the day's
 * candidates by distance, take each band's median, and call a fare that is 40%
 * under its band a relative find. It costs nothing and IT DOES NOT WORK, and
 * the 2026-08-16 sweep says so three separate ways:
 *
 *   1. THE SWEEP IS A FLOOR, NOT A PRICE LIST. `/v2/prices/latest` returns ONE
 *      cheapest cached entry per destination — measured on the recorded
 *      fixtures, the maximum number of rows for any one origin-destination pair
 *      is 1. So a sweep contains no distribution for any single route and can
 *      express no notion of what Dublin usually costs. The band median is a
 *      median of cheapest-founds — a floor of floors — and lands at €29 for the
 *      500–1000 km band where the retail intuition says €120.
 *
 *   2. AMS-DUB SCORED −3.4% AGAINST IT. Dublin at €30 over 750 km is the
 *      MEDIAN fare for its distance. The owner's own example fails the rule
 *      written to catch it.
 *
 *   3. WITHIN A BAND, DISTANCE IS ~CONSTANT, so ranking by 1 − price/median is
 *      ranking by price is ranking by €/km. The band lane's top qualifiers were
 *      Tangier, Marrakesh, Pescara, Vilnius and Tirana — the absolute lane's
 *      shortlist, exactly. It was not a second kind of deal; it was the first
 *      kind, spelled differently, for three extra window fetches a night.
 *
 * The honest baseline for "usual on this route" is the route's OWN window
 * median, which this app already fetches and already trusts: it is what
 * `savings_cents` is measured against, and DUS-AGP's €29 against a €78 October
 * median is the measurement the whole verification stage was built on. That
 * costs a request — so the relative lane spends its budget LEARNING those
 * baselines and then reading them for free. See App\Domain\Discovery\
 * RelativeLaneSelector for the flywheel that falls out of it.
 */
enum Lane: string
{
    case Absolute = 'absolute';

    case Relative = 'relative';
}
