<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use DateTimeImmutable;

/**
 * The cheap half of the funnel: ranks swept fares without spending requests.
 * Why: docs/BUSINESS-LOGIC.md §16.
 */
final readonly class CandidateScorer
{
    public function __construct(private DiscoveryPolicy $policy) {}

    /**
     * Every candidate worth considering, cheapest per kilometre first.
     * Why: docs/BUSINESS-LOGIC.md §16.
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

        // Route code breaks ties deliberately: usort() stability is not something to depend on here.
        // Why: docs/BUSINESS-LOGIC.md §16.
        usort($admitted, static function (DealCandidate $a, DealCandidate $b): int {
            return $a->centsPerKilometre() <=> $b->centsPerKilometre()
                ?: strcmp($a->routeCode(), $b->routeCode());
        });

        return $admitted;
    }

    /**
     * The few that are worth spending requests on.
     * Why: docs/BUSINESS-LOGIC.md §16.
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
     * Where `$cents` falls among the fares of its own window, as a percentage. Strictly-cheaper share (0 = cheapest); an
     * empty window scores 100, not 0, so missing data can't masquerade as a bargain (docs/BUSINESS-LOGIC.md §16).
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
     * The middle fare of a window, in cents — or null if there is no window. Median, not mean (long right tail skews it); lower of two middles on an even
     * count, so the result is a fare someone was actually offered (docs/BUSINESS-LOGIC.md §16).
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
