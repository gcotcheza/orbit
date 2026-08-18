<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use DateTimeImmutable;
use App\Domain\Pricing\DatedFare;

/**
 * Which places a rule is about, and which fares it would fire on.
 *
 * PURE PHP, ZERO QUERIES — docs/PLAN.md's line for App\Domain, and the reason
 * this is two small functions rather than one method on a model. The two
 * halves are genuinely independent questions: "where would this send me" is
 * answered from the destination vocabulary alone, and "is this fare one of the
 * ones it means" is answered from the fare alone. Keeping them apart is what
 * lets App\Jobs\SweepRuleFares ask the first one about routes that have no
 * fares yet — which is the entire reason the sweep exists.
 *
 * TRIP LENGTH IS PARSED AND NOT MATCHED ON, and that is a deliberate hole
 * rather than an oversight. App\Application\Ports\PriceProvider answers with
 * the cheapest fare per DEPARTURE date — one-way, no return leg — so this app
 * does not currently hold the fact a "2–3 nights" filter would need. The chip
 * is still parsed, still shown and still stored, because the sentence really
 * does say it and dropping it would make the create screen misread somebody's
 * English; it starts filtering the day the provider grows return fares, and
 * nothing else has to change.
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
     * The destinations a rule is asking about, best fit first.
     *
     * TWO FILTERS AND A SORT:
     *
     *   - the VIBE filter is the rule's own words. No vibes asked for means
     *     anywhere Orbit knows, which is the right answer to "anywhere under
     *     €50" and the reason config('orbit.rules.sweep_cap') exists.
     *   - the CLIMATE filter only runs when the rule asks for a warm vibe AND
     *     names a window. See config/orbit.php for why it is the best month in
     *     the window rather than every month, and why a rule with no window
     *     skips it entirely.
     *   - the SORT is what App\Jobs\SweepRuleFares spends its budget on, so it
     *     has to be total and deterministic: more matching vibes first, then
     *     warmer, then the code, so the same rule sweeps the same places on
     *     every run rather than a different thirty each morning.
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
     * The cheapest fare on this route that the rule would actually fire on, or
     * NULL if none of them would.
     *
     * A departure has to clear three things: the price ceiling, the weekday,
     * and the window. A rule with none of them set takes the cheapest fare
     * there is, which is what "anywhere cheap" means.
     *
     * `$today` IS PASSED IN rather than read from a clock, because this is
     * pure and because the caller already knows what day it is in the owner's
     * timezone — and "which spring" depends on that answer (MonthWindow).
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
             * Strictly cheaper, so a tie keeps the EARLIER date — the fares
             * arrive ordered by departure (PriceProvider's contract), and the
             * sooner of two equally cheap flights is the one to show. Same
             * rule App\Application\Routes\RouteSnapshots picks by.
             */
            if ($cheapest === null || $fare->cents < $cheapest->cents) {
                $cheapest = $fare;
            }
        }

        return $cheapest;
    }
}
