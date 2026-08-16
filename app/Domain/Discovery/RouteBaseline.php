<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use DateTimeImmutable;

/**
 * What one route USUALLY costs, as Orbit last measured it.
 *
 * THE THING THE ABSOLUTE LANE NEVER NEEDED. Ranking by €/km compares a fare
 * against the rest of the world, and the rest of the world is in the sweep for
 * free. "Cheap for Dublin" compares a fare against DUBLIN, and there is exactly
 * one Dublin row in a sweep — so this number cannot be derived and has to be
 * remembered.
 *
 * IT IS A WINDOW MEDIAN AND NOT AN AVERAGE, for the reason App\Infrastructure\
 * Pricing\SelfStatsProvider and CandidateScorer::median both give: a fare
 * distribution has a long right tail (one €600 date in a month of €78s) and a
 * mean would quietly inflate every discount this lane claims.
 *
 * =============================================================================
 * `sampleDays` IS NOT DECORATION — IT IS WHAT MAKES THE MEDIAN ADMISSIBLE
 * =============================================================================
 * Travelpayouts' month-matrix coverage runs 41% to 87% even on routes Orbit
 * polls every morning, and a discovery is by definition an obscure pair. A
 * "median" over three priced days out of a 181-day window is not a claim about
 * what the route usually costs; it is three numbers. Acting on it would produce
 * exactly the failure this lane exists to avoid — a confident sentence on a
 * card, built on a sample that cannot carry it.
 *
 * So the count travels WITH the median, always, and RelativeLanePolicy::admits()
 * is the only thing allowed to decide whether it is enough.
 *
 * =============================================================================
 * AND `measuredAt` IS WHAT STOPS IT BECOMING A FOSSIL
 * =============================================================================
 * A baseline is a measurement, and a measurement from March is a claim about
 * March. Routes get cheaper and more expensive for structural reasons — a new
 * carrier, a dropped route, a season — and a discount computed against a
 * baseline nobody has refreshed since the spring is arithmetic against a number
 * that stopped being true. The policy carries the staleness rule; this type
 * carries the fact.
 */
final readonly class RouteBaseline
{
    public function __construct(
        /** `AMS-DUB` — App\Models\Route::codeFor's spelling, as everything keys on. */
        public string $code,
        /** The median of the route's own near window, in cents. */
        public int $medianCents,
        /** How many priced departure dates that median was taken over. */
        public int $sampleDays,
        public DateTimeImmutable $measuredAt,
    ) {}

    /**
     * How far under its own usual this fare sits, as a fraction: 0.5 is half
     * price.
     *
     * NEGATIVE IS A REAL ANSWER AND IS RETURNED RATHER THAN CLAMPED. A fare
     * ABOVE its route's median scores below zero, which is the honest reading
     * and the one the selector filters on. Clamping at zero would turn "this is
     * dearer than usual" into "this is exactly usual" and hide the distinction
     * that decides whether a card exists.
     *
     * A ZERO OR NEGATIVE MEDIAN ANSWERS 0.0 rather than dividing. It cannot
     * happen — `price_cents` is unsigned and a window with no fares produces no
     * baseline at all — but the alternative to a guard here is a division by
     * zero producing INF, and INF sorts to the TOP of a discount ranking.
     */
    public function discountOf(int $cents): float
    {
        if ($this->medianCents <= 0) {
            return 0.0;
        }

        return 1 - ($cents / $this->medianCents);
    }

    /**
     * How much cheaper than usual, in cents — the euro figure the card prints.
     *
     * NEVER NEGATIVE. This one IS clamped, and the asymmetry with
     * `discountOf()` is deliberate: that number is a RANKING KEY and has to
     * keep its sign to be ordered correctly, this one is a SAVING and a card
     * saying "€-12 under its usual" is not a sentence. The selector has already
     * dropped anything with a negative discount by the time this is read.
     */
    public function savingOf(int $cents): int
    {
        return max(0, $this->medianCents - $cents);
    }

    /** How old this measurement is, in days, as of `$now`. */
    public function ageInDays(DateTimeImmutable $now): float
    {
        return ($now->getTimestamp() - $this->measuredAt->getTimestamp()) / 86400;
    }
}
