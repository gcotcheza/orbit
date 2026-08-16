<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use DateTimeImmutable;

/**
 * Every number the RELATIVE lane applies — the second half of App\Domain\
 * Discovery\DiscoveryPolicy, kept as its own value for the reason that class is
 * one at all: App\Domain reads no config(), so config/orbit.php is resolved once
 * by App\Providers\AppServiceProvider and handed in.
 *
 * A SEPARATE CLASS AND NOT SEVEN MORE CONSTRUCTOR ARGUMENTS, because the two
 * lanes are two products. The absolute lane's numbers are a statement about
 * fares in general (a ratio floor, a price ceiling); these are a statement about
 * one route against itself, and every one of them would read as an exception if
 * it sat next to `maxCentsPerKilometre`. A test that wants to see the relative
 * lane behave at a different threshold constructs one of these and leaves the
 * absolute lane's rules untouched, which is the whole point.
 *
 * =============================================================================
 * WHERE THESE NUMBERS CAME FROM
 * =============================================================================
 * Two measurements and one stated requirement, all of them real:
 *
 *   DUS-AGP, 2026-08-16   €29 against a €78 October window median — the find
 *                         the verification stage was built on. A 62.8% discount.
 *   AMS-DUB, 2026-08-16   €30 over 750 km, 40.0 m€/km. The absolute lane's
 *                         floor is 30 m€/km, so it is rejected there at ANY
 *                         price a person would call cheap: at 30 m€/km Dublin
 *                         would have to be €22.
 *   The owner's ask       "€60 to Dublin is a steal for Dublin", against a
 *                         usual they put at €120 — a 50% discount.
 *
 * `minDiscount` is set below both measured cases with margin. See its own note.
 */
final readonly class RelativeLanePolicy
{
    public function __construct(
        /**
         * THE CEILING, IN CENTS, AND IT IS DELIBERATELY ABOVE THE ABSOLUTE
         * LANE'S €120.
         *
         * A relative find is by construction a fare that is NOT remarkable per
         * kilometre — that is what makes it this lane's and not the other's — so
         * judging it against a ceiling tuned for €/km outliers would reject the
         * whole population. Dublin at €60 is €60: unremarkable as a ratio,
         * remarkable as a Dublin fare, and €120 leaves no room for the mid-haul
         * routes whose usual is genuinely high.
         *
         * €150 IS STILL A CEILING AND IT IS THERE ON PURPOSE. "50% off" is a
         * percentage and percentages are scale-free: a €400 long-haul at half
         * its €800 usual is a genuine discount and IS A DIFFERENT PRODUCT — it
         * is a trip somebody plans around, not a fare they see on a Tuesday and
         * book on the Tuesday. The promise this screen makes is the same in both
         * lanes, so both lanes need an absolute cap; they just do not need the
         * SAME one.
         */
        public int $maxPriceCents,

        /**
         * HOW FAR UNDER ITS OWN USUAL A FARE MUST SIT, as a fraction — 0.40 is
         * 40% off.
         *
         * BELOW BOTH REAL CASES, WITH MARGIN. DUS-AGP measured 62.8% (€29
         * against €78) and the owner's Dublin ask is 50% (€60 against €120);
         * 0.40 admits both and is comfortably clear of an ordinary good day on a
         * route, which is the thing this must not fire on. A route's window
         * routinely spans 20–30% between its cheap Tuesdays and its median, and
         * a lane that surfaced those would be telling the owner that Tuesday is
         * cheaper than Friday once a night.
         *
         * IT IS A FLOOR AND NOT A QUOTA, like every other number in this
         * feature. A day on which no known route is 40% under itself produces no
         * relative cards, and the exploration rotation spends the budget
         * learning instead — which is the one case where an empty lane still
         * did something useful.
         */
        public float $minDiscount,

        /**
         * AND THE SAME ABSOLUTE FLOOR THE OTHER GATE USES, IN CENTS.
         *
         * A PERCENTAGE ALONE CANNOT GUARD A CHEAP ROUTE. A window that runs €18
         * to €30 puts a €17 fare 43% under its median while saving nobody
         * anything worth a card — the identical failure DiscoveryPolicy::
         * $minSavingsCents was added for, one axis over. Both rules pass or the
         * candidate is dropped, because each is blind to what the other catches.
         *
         * IT IS THE SAME €15 and it is passed in separately rather than read off
         * the other policy, so that the two can be retuned apart if the relative
         * lane ever turns out to need a different one.
         */
        public int $minSavingsCents,

        /**
         * HOW MANY DEPARTURE DATES A BASELINE MUST BE BUILT ON.
         *
         * A MEDIAN OVER THREE PRICED DAYS IS THREE NUMBERS, NOT A USUAL PRICE.
         * Travelpayouts' month-matrix coverage runs 41% to 87% on the routes
         * Orbit polls daily and is far thinner on the obscure pairs this lane
         * deals in, so a window fetch can easily come back with a handful of
         * dates. Acting on that would produce precisely the failure the lane
         * exists to avoid: a confident sentence on a card, resting on a sample
         * that cannot carry it.
         *
         * TEN, WHICH IS WHERE ONE OUTLIER STOPS MOVING THE ANSWER. Below about
         * ten dates a single peak-season fare drags the median far enough to
         * change whether a candidate clears 40%; above it, the median is stable
         * against any one date. It is the same job `selfstats.
         * maturity_observations` does for the longitudinal half — "is there
         * enough here to say this out loud" — asked of a cross-section instead
         * of a history.
         *
         * A BASELINE THAT FAILS THIS IS NOT DISCARDED, IT IS UNKNOWN. It goes
         * back into the exploration pool, so the next run that picks the route
         * re-measures it — which is how a thin baseline heals rather than
         * permanently disqualifying a route.
         */
        public int $minBaselineDays,

        /**
         * HOW LONG A MEASURED BASELINE STAYS ADMISSIBLE, IN DAYS.
         *
         * A BASELINE IS A MEASUREMENT AND MEASUREMENTS EXPIRE. Routes reprice
         * for structural reasons — a carrier arrives, a route is dropped, a
         * season turns — and a discount computed against a median nobody has
         * refreshed since the spring is arithmetic against a number that stopped
         * being true. Unlike the swept `found_at` rule this is not about the
         * FARE being stale; it is about the yardstick being stale, which is the
         * quieter and more dangerous of the two.
         *
         * THIRTY DAYS, AND THE TENSION IS WITH EXPLORATION RATHER THAN WITH
         * ACCURACY. Every verified relative finalist re-measures its own
         * baseline on the spot, so an active route never ages out; this number
         * only governs how long a route Orbit has stopped picking keeps its
         * memory. Shorter would send the rotation back to routes it already
         * knows and starve the unknown pool — the flywheel would spin without
         * ever widening. A month is roughly "a season has not turned yet".
         */
        public int $maxBaselineAgeDays,

        /**
         * HOW MANY CANDIDATES THIS LANE PUTS THROUGH VERIFICATION.
         *
         * THREE, AND IT IS THE ONLY NUMBER HERE THAT COSTS ANYTHING. Each
         * finalist is one near-window fetch — 6 or 7 Travelpayouts requests — so
         * this lane adds ≤21 requests to a run that was 38, in a clock hour with
         * ~160 to spare. The full arithmetic is in config/orbit.php's `poll`
         * section.
         *
         * IT DOES NOT ADD A SINGLE GOOGLE SEARCH. `orbit.serpapi.max_per_run`
         * is unchanged at five and is now shared across BOTH lanes, absolute
         * first — see App\Jobs\DiscoverDeals. A second lane was not worth
         * re-opening a 250-a-month allowance for.
         */
        public int $shortlist,
    ) {}

    /**
     * Is this baseline good enough to judge a fare against?
     *
     * THE TWO WAYS A REMEMBERED NUMBER GOES WRONG — too few dates behind it, or
     * measured too long ago — and a baseline that fails either is treated as
     * ABSENT rather than as bad. That distinction is the flywheel: absent means
     * the route is eligible for exploration, so the next run that reaches it
     * fixes the thing that was wrong.
     */
    public function admitsBaseline(RouteBaseline $baseline, DateTimeImmutable $now): bool
    {
        return $baseline->sampleDays >= $this->minBaselineDays
            && $baseline->ageInDays($now) <= (float) $this->maxBaselineAgeDays;
    }

    /**
     * Given an admissible baseline: is this fare rare enough for its own route
     * to be worth a window fetch?
     *
     * BOTH RULES, AND THE `&&` IS THE POINT — see `$minSavingsCents`. This is
     * the CHEAP gate, spent on remembered numbers before anything is fetched;
     * the finalist still has to clear DiscoveryPolicy::isRemarkable() against a
     * freshly fetched window afterwards, exactly as an absolute finalist does.
     */
    public function isRare(RouteBaseline $baseline, int $cents): bool
    {
        return $baseline->discountOf($cents) >= $this->minDiscount
            && $baseline->savingOf($cents) >= $this->minSavingsCents;
    }
}
