<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use DateTimeImmutable;
use App\Domain\Pricing\DatedFare;

/**
 * Which places a rule is about, and which fares it would fire on. Pure PHP, zero queries;
 * trip length is parsed but not matched on (docs/BUSINESS-LOGIC.md §11).
 */
final readonly class RuleMatcher
{
    /**
     * @param  int  $warmAt  config('orbit.rules.warm_at')
     * @param  list<string>  $warmVibes  config('orbit.rules.warm_vibes')
     */
    public function __construct(
        private int $warmAt,
        private array $warmVibes,
    ) {}

    /**
     * The destinations a rule is asking about, best fit first: two filters then a
     * deterministic sort (docs/BUSINESS-LOGIC.md §11).
     *
     * @param  list<DestinationProfile>  $destinations
     * @return list<DestinationProfile>
     */
    public function rank(RuleCriteria $criteria, array $destinations): array
    {
        $months = $criteria->dateWindow?->months() ?? [];
        $climate = $months !== [] && array_intersect($criteria->vibes, $this->warmVibes) !== [];

        $matching = array_values(array_filter($destinations, function (DestinationProfile $place) use ($criteria, $climate, $months): bool {
            if ($criteria->vibes !== [] && $place->vibeOverlap($criteria->vibes) === 0) {
                return false;
            }

            return ! $climate || $place->bestWarmth($months) >= $this->warmAt;
        }));

        usort($matching, function (DestinationProfile $a, DestinationProfile $b) use ($criteria, $months): int {
            return $b->vibeOverlap($criteria->vibes) <=> $a->vibeOverlap($criteria->vibes)
                ?: $b->bestWarmth($months) <=> $a->bestWarmth($months)
                ?: strcmp($a->iata, $b->iata);
        });

        return $matching;
    }

    /**
     * The cheapest fare on this route the rule would fire on, or NULL. `$today` is passed in,
     * not read from a clock — this stays pure and "which spring" is the caller's day.
     *
     * @param  list<DatedFare>  $fares
     */
    public function cheapest(RuleCriteria $criteria, array $fares, DateTimeImmutable $today): ?DatedFare
    {
        $window = $criteria->dateWindow?->resolve($today);

        $cheapest = null;

        foreach ($fares as $fare) {
            if ($criteria->maxPriceCents !== null && $fare->cents > $criteria->maxPriceCents) {
                continue;
            }

            $departure = $fare->departureDate->setTime(0, 0);

            if ($criteria->departDows !== [] && ! in_array((int) $departure->format('N'), $criteria->departDows, true)) {
                continue;
            }

            if ($window !== null && ($departure < $window[0] || $departure > $window[1])) {
                continue;
            }

            /*
             * Strictly cheaper, so a tie keeps the earlier date — the same rule
             * RouteSnapshots picks by (docs/BUSINESS-LOGIC.md §11).
             */
            if ($cheapest === null || $fare->cents < $cheapest->cents) {
                $cheapest = $fare;
            }
        }

        return $cheapest;
    }
}
