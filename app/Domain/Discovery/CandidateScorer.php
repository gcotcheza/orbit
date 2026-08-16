<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use DateTimeImmutable;

/**
 * The cheap half of the funnel: a thousand swept fares in, a handful out, and
 * not one request spent doing it.
 *
 * PURE, AND THAT IS THE WHOLE ARGUMENT FOR IT BEING A CLASS AT ALL. Ranking a
 * sweep is the only part of discovery that can be judged by reading it — the
 * providers are HTTP, the job is a database, the verification is somebody
 * else's API — so it is separated out here where tests are arithmetic and the
 * thresholds can be moved without a fixture in sight.
 *
 * IT DOES NOT KNOW WHAT A REQUEST COSTS. `shortlist()` cuts to a number the
 * policy carries and has no opinion about why that number is five; the budget
 * lives in config/orbit.php next to the rest of the app's request arithmetic.
 */
final readonly class CandidateScorer
{
    public function __construct(private DiscoveryPolicy $policy) {}

    /**
     * Every candidate worth considering, cheapest per kilometre first.
     *
     * THE SORT IS THE RANKING AND THE FILTER IS THE FLOOR — see
     * DiscoveryPolicy::admits() for the four rules and the measurements behind
     * each. An empty answer is a real answer: a week with no deals in it should
     * produce an empty discovery screen, not the least mediocre thing available.
     *
     * @param  list<DealCandidate>  $candidates
     * @return list<DealCandidate>
     */
    public function admit(array $candidates, DateTimeImmutable $now): array
    {
        $admitted = array_values(array_filter(
            $candidates,
            fn (DealCandidate $candidate): bool => $this->policy->admits($candidate, $now),
        ));

        /*
         * `usort` IS NOT STABLE ACROSS PHP VERSIONS FOR EQUAL KEYS — it is as of
         * 8.0, but two candidates with the same €/km to fifteen decimal places
         * is not a case worth relying on that for, and a discovery list that
         * reordered itself between two runs with identical inputs would look
         * like news. The route code breaks the tie, so the answer is total.
         */
        usort($admitted, static function (DealCandidate $a, DealCandidate $b): int {
            return $a->centsPerKilometre() <=> $b->centsPerKilometre()
                ?: strcmp($a->routeCode(), $b->routeCode());
        });

        return $admitted;
    }

    /**
     * The few that are worth spending requests on.
     *
     * ONE DESTINATION ONLY ONCE, ACROSS ALL THREE ORIGINS, and that is the only
     * thing this method does that `array_slice` would not. Málaga appeared in
     * both the DUS sweep (€29) and the EIN sweep (€31) on 2026-08-16, and a
     * shortlist of five that spent two of its five slots — two window fetches,
     * two Google searches — on the same city would be paying twice to tell the
     * owner one thing. The cheaper per kilometre wins, which is already the
     * order the list arrives in.
     *
     * @param  list<DealCandidate>  $candidates  as returned by `admit()`
     * @return list<DealCandidate>
     */
    public function shortlist(array $candidates): array
    {
        $seen = [];
        $picked = [];

        foreach ($candidates as $candidate) {
            if (isset($seen[$candidate->destinationIata])) {
                continue;
            }

            $seen[$candidate->destinationIata] = true;
            $picked[] = $candidate;

            if (count($picked) === $this->policy->shortlist) {
                break;
            }
        }

        return $picked;
    }

    /**
     * Where `$cents` falls among the fares of its own window, as a percentage.
     *
     * THE SHARE OF THE WINDOW THAT IS STRICTLY CHEAPER, so the cheapest fare in
     * a window scores 0 and nothing ever scores 100 — which is the reading
     * DiscoveryPolicy::$maxPercentile is written against ("in the cheapest
     * tenth"). DUS-AGP's €29 was cheaper than all 23 fares in its October
     * window and scored 0.0.
     *
     * STRICTLY, NOT `<=`, so that a window in which every fare is the same
     * price puts the candidate at 0 rather than at 100. That window is flat and
     * the candidate is not a find — which is what the savings floor is for, and
     * it is a cleaner division of labour than making the percentile carry it.
     *
     * AN EMPTY WINDOW IS 100 AND NOT 0. No window means the verification stage
     * learned nothing, and the direction of the failure has to be "prove it",
     * not "assume it": zero would silently promote every route the provider has
     * no calendar for.
     *
     * @param  list<int>  $windowCents
     */
    public static function percentile(int $cents, array $windowCents): float
    {
        $total = count($windowCents);

        if ($total === 0) {
            return 100.0;
        }

        $cheaper = count(array_filter($windowCents, static fn (int $fare): bool => $fare < $cents));

        return $cheaper / $total * 100;
    }

    /**
     * The middle fare of a window, in cents — or null if there is no window.
     *
     * THE MEDIAN AND NOT THE MEAN, for the reason App\Infrastructure\Pricing\
     * SelfStatsProvider uses one: a fare distribution has a long right tail
     * (one €600 date in a month of €78s) and the mean would quietly inflate
     * every saving this feature claims.
     *
     * THE LOWER OF THE TWO MIDDLES on an even count, rather than their average.
     * A median that is not one of the observed fares is a price nobody was ever
     * offered, and this number is subtracted from a real fare to produce a
     * saving the screen states in euros.
     *
     * @param  list<int>  $windowCents
     */
    public static function median(array $windowCents): ?int
    {
        if ($windowCents === []) {
            return null;
        }

        sort($windowCents);

        return $windowCents[intdiv(count($windowCents) - 1, 2)];
    }
}
