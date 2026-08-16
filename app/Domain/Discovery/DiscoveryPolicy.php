<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use DateTimeImmutable;

/**
 * Every number the discovery funnel applies, as one pure value.
 *
 * THE ARRANGEMENT App\Domain\Pricing\ScoringPolicy AND App\Domain\Alerts\
 * AlertPolicy ALREADY HAVE: App\Domain calls no framework function, config()
 * included, so config/orbit.php is read once by App\Providers\
 * AppServiceProvider and handed in here. A test that wants to see the funnel
 * behave at a different threshold constructs one of these rather than
 * publishing a config array.
 *
 * =============================================================================
 * WHERE THESE NUMBERS CAME FROM — the 2026-08-16 sweep, 1,177 real rows
 * =============================================================================
 * Every default in config/orbit.php's `discovery` section was read off ONE
 * measurement: an origin sweep of all three home airports on 2026-08-16, which
 * returned 562 rows from AMS, 419 from DUS and 196 from EIN — 1,086 of them to
 * a destination Orbit holds coordinates for. The distributions are in that
 * config section. What follows is what each rule is FOR.
 *
 * THE FOUR CHEAP RULES ARE A FUNNEL AND THE ORDER IS THE BUDGET. Distance,
 * price, ratio and age are all arithmetic over rows already in hand, and
 * together they take ~1,086 candidates down to a few dozen. Only then does
 * anything cost a request: the top `shortlist` are fetched a window each, and
 * at most `shortlist` of those are put to Google. Reordering these would not
 * change the answer; it would change the bill.
 */
final readonly class DiscoveryPolicy
{
    public function __construct(
        /**
         * HOW FAR IS FAR ENOUGH TO BE A TRIP, in kilometres.
         *
         * 59 of the 1,086 scored candidates were under 500 km and 31 under 300
         * — Brussels, Cologne, Maastricht, Eindhoven from Amsterdam. Every one
         * of those is a train, and a screen that surprised the owner with
         * "Brussels, €51" would be a screen they stopped opening.
         *
         * IT IS NOT THERE TO PROTECT THE RATIO. A short hop already scores
         * badly per kilometre and mostly excludes itself. This rule is about
         * what a DISCOVERY IS: the promise is somewhere you would not have
         * thought to look, and the far side of a two-hour drive does not
         * qualify however cheap the fare gets.
         */
        public float $minKilometres,

        /**
         * THE CEILING, IN CENTS, AND THE REASON €/km IS NOT THE WHOLE RULE.
         *
         * Ranked by €/km alone the sweep offers Singapore at €287 (27.3
         * m€/km), Manila at €293 (28.1) and Bangkok at €271 (29.4) in the same
         * band as Málaga at €36 (19.1). Those are real bargains and none of
         * them is this feature: €287 is a trip somebody plans around, and the
         * promise here is a fare you see on a Tuesday and book on the Tuesday.
         *
         * €120 IS WHERE THAT LINE SITS TODAY. 206 of the 524 fresh, far-enough
         * candidates were under it. It happened not to bite on the top 25 of
         * the 2026-08-16 sweep — short-haul beat long-haul on the ratio that
         * week outright — which is precisely why it has to be written down
         * rather than left to luck: on a thin week the ratio alone would fill
         * this screen with long-haul, and nothing would have changed except the
         * weather.
         */
        public int $maxPriceCents,

        /**
         * THE RANKING THRESHOLD, in EURO CENTS per kilometre.
         *
         * 3.0 cents/km is 30 millieuros/km, which is the unit the sweep is
         * comfortable to read in and the one config/orbit.php quotes. On the
         * 2026-08-16 data it is a genuine dividing line rather than a round
         * number: 53 of the 1,086 candidates were under it while also being
         * under €120, far enough and fresh enough. The best of them were
         * Marrakesh at 10.8, Tangier at 11.5 and Vilnius at 13.1; the fares
         * sitting just the wrong side of 30 were the ordinary good deals a
         * person finds by looking.
         *
         * IT IS A FLOOR AND NOT THE SORT. The list is ordered by this number
         * and cut at `shortlist`; the threshold's job is to stop a WEEK WITH NO
         * DEALS IN IT from promoting the least mediocre thing available. An
         * empty discovery screen is an honest answer and this is what makes it
         * reachable.
         */
        public float $maxCentsPerKilometre,

        /**
         * HOW OLD A SWEPT PRICE MAY BE, IN DAYS.
         *
         * A sweep is not a price list — it is seven days of other people's
         * searches piled up. The recorded `found_at` spread ran the full week:
         * of the 1,086 rows, 394 were under two days old, 542 under three, and
         * 1,082 under seven.
         *
         * THREE DAYS KEEPS HALF THE POOL AND DROPS THE TAIL. It is one day
         * MORE generous than `alerts.max_fare_age_days`, and the difference is
         * the difference between the two features: that number governs waking
         * somebody up, this one governs a card on a screen that PRINTS THE AGE
         * next to the price ("seen 2 days ago"). Orbit is allowed to show a
         * reader something slightly stale as long as it says so; it is not
         * allowed to mail them about it. v1 of discovery does not alert — see
         * docs/BUSINESS-LOGIC.md §16 — and this number is one of the places
         * that decision is spent.
         */
        public int $maxFoundAgeDays,

        /**
         * HOW MANY CANDIDATES ARE PUT THROUGH VERIFICATION.
         *
         * THIS IS THE ONLY NUMBER IN THIS CLASS THAT COSTS MONEY. Each finalist
         * is a full near-window fetch — 6 or 7 Travelpayouts requests, billed
         * per calendar month — plus at most one SerpAPI search. Five finalists
         * is ~35 provider requests and ≤5 searches; six would be ~42 and would
         * start to matter against the ~200-an-hour ceiling. The whole table is
         * in config/orbit.php's `poll` section.
         */
        public int $shortlist,

        /**
         * HOW DEEP IN ITS OWN WINDOW A FINALIST MUST SIT, as a percentile.
         *
         * The cross-sectional check: having fetched the finalist's own near
         * window, where does the swept fare fall among every OTHER departure
         * date on the same route? DUS-AGP's €29 was cheaper than all 23 fares
         * its October window held — the 0th percentile, against a month median
         * of €78 — which is what "insanely cheap" is supposed to mean and is
         * a claim nothing before this stage could support.
         *
         * TEN, because the point is to catch the candidate that looked good
         * against the WORLD and is ordinary on its OWN route. A fare in the
         * cheapest tenth of its window is a real outlier; one at the 40th is
         * just a route that is cheap in general, which the €/km ranking has
         * already noticed and does not need to say twice.
         */
        public float $maxPercentile,

        /**
         * THE SMALLEST GAP WORTH THE WORD "FIND", IN CENTS.
         *
         * Measured against the MEDIAN of the finalist's own window, so it is
         * "how much cheaper than an ordinary day on this route" rather than a
         * comparison with a number from somewhere else. DUS-AGP: €29 against a
         * €78 median is €49 of daylight.
         *
         * €15, AND IT IS A GUARD ON THE THIN ROUTE. A route whose whole window
         * is €38 to €44 can put a fare in its own bottom tenth while saving
         * nobody anything; the percentile alone would call that a discovery.
         * Both rules have to pass, because each is blind to the case the other
         * catches.
         */
        public int $minSavingsCents,

        /**
         * HOW LONG A DISCOVERY STAYS ON THE SCREEN, IN HOURS.
         *
         * A discovery is EPHEMERAL — it is the one thing in this app that is
         * deliberately not history. The run is daily, so 36 hours is "until
         * tomorrow's run replaces it, plus half a day of slack": a run that
         * fails leaves yesterday's set standing until the afternoon instead of
         * blanking the screen at 05:20, and a set two runs old is gone whatever
         * happens.
         */
        public int $expiresAfterHours,

        /**
         * THE CEILING ON THE WHOLE TABLE.
         *
         * At `shortlist` rows a run and a 36-hour life, at most ten rows are
         * ever alive at once; twelve is headroom rather than a target. It
         * exists because a table nothing bounds is a table that grows, and this
         * one has no reason to: nothing reads a discovery from last March, and
         * App\Jobs\DiscoverDeals prunes to this on every run.
         */
        public int $maxRows,
    ) {}

    /**
     * Is this fare recent enough to be worth a claim?
     *
     * NULL `foundAt` IS NOT FRESH — see DealCandidate::ageInDays() for why this
     * is the opposite of what AlertPolicy does with the identical fact.
     */
    public function isFresh(DealCandidate $candidate, DateTimeImmutable $now): bool
    {
        $age = $candidate->ageInDays($now);

        return $age !== null && $age <= (float) $this->maxFoundAgeDays;
    }

    /**
     * Could this candidate be a discovery at all, before anything is spent on
     * finding out?
     *
     * THE FOUR CHEAP RULES, TOGETHER. Everything here is arithmetic over a row
     * already in memory, which is what lets ~1,086 candidates be reduced to a
     * few dozen for the price of one loop.
     */
    public function admits(DealCandidate $candidate, DateTimeImmutable $now): bool
    {
        return $candidate->kilometres >= $this->minKilometres
            && $candidate->cents <= $this->maxPriceCents
            && $candidate->centsPerKilometre() <= $this->maxCentsPerKilometre
            && $this->isFresh($candidate, $now);
    }

    /**
     * Having seen the finalist's own window: is it actually remarkable there?
     *
     * BOTH RULES, AND THE `&&` IS THE POINT. The percentile catches a fare that
     * is ordinary on its own route; the savings floor catches a route so flat
     * that its own bottom tenth is worth nothing. See the two constructor
     * notes.
     */
    public function isRemarkable(float $percentile, int $savingsCents): bool
    {
        return $percentile <= $this->maxPercentile
            && $savingsCents >= $this->minSavingsCents;
    }
}
