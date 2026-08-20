<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use DateTimeImmutable;

/**
 * The relative lane's shortlist and the flywheel behind it: known routes first, exploration
 * fills the rest, and every fetched window is kept (docs/BUSINESS-LOGIC.md §16).
 */
final readonly class RelativeLaneSelector
{
    public function __construct(private RelativeLanePolicy $policy) {}

    /**
     * The candidates this lane will spend its window fetches on, in spend order.
     *
     * @param  list<DealCandidate>  $pool  cleared the sanity floors, not the absolute lane's
     * @param  array<string, RouteBaseline>  $baselines  keyed by route code
     * @param  list<string>  $excludeDestinations  destination IATAs the absolute lane took
     * @return list<RelativePick>
     */
    public function select(
        array $pool,
        array $baselines,
        array $excludeDestinations,
        DateTimeImmutable $now,
    ): array {
        $taken = array_fill_keys($excludeDestinations, true);

        $known = [];
        $unknown = [];

        foreach ($pool as $candidate) {
            /*
             * ⚠ One destination only once, across BOTH lanes and across origins — Málaga
             * appeared in two sweeps, and two slots on one city pays twice for one place.
             */
            if (isset($taken[$candidate->destinationIata])) {
                continue;
            }

            $baseline = $baselines[$candidate->routeCode()] ?? null;

            /*
             * A baseline too thin or too old counts as NO baseline: the candidate drops into
             * the exploration pool rather than out of the lane, so it can heal.
             */
            if ($baseline !== null && $this->policy->admitsBaseline($baseline, $now)) {
                $known[] = new RelativePick($candidate, PickReason::Baseline, $baseline);

                continue;
            }

            $unknown[] = $candidate;
        }

        $picks = $this->fromBaselines($known);

        $slots = $this->policy->shortlist - count($picks);

        if ($slots > 0) {
            foreach ($this->explore($unknown, $picks, $now) as $candidate) {
                $picks[] = new RelativePick($candidate, PickReason::Exploration);

                if (count($picks) === $this->policy->shortlist) {
                    break;
                }
            }
        }

        return $picks;
    }

    /**
     * The known routes whose fares are rare enough to be worth checking, best
     * discount first.
     *
     * @param  list<RelativePick>  $known
     * @return list<RelativePick>
     */
    private function fromBaselines(array $known): array
    {
        $rare = array_values(array_filter(
            $known,
            fn (RelativePick $pick): bool => $pick->baseline !== null
                && $this->policy->isRare($pick->baseline, $pick->candidate->cents),
        ));

        /*
         * Deepest discount first, route code breaking the tie — the same total order
         * CandidateScorer::admit() imposes; `usort` stability is not relied on.
         */
        usort($rare, static function (RelativePick $a, RelativePick $b): int {
            return ($b->expectedDiscount() ?? 0.0) <=> ($a->expectedDiscount() ?? 0.0)
                ?: strcmp($a->candidate->routeCode(), $b->candidate->routeCode());
        });

        $picked = [];
        $seen = [];

        foreach ($rare as $pick) {
            if (isset($seen[$pick->candidate->destinationIata])) {
                continue;
            }

            $seen[$pick->candidate->destinationIata] = true;
            $picked[] = $pick;

            if (count($picked) === $this->policy->shortlist) {
                break;
            }
        }

        return $picked;
    }

    /**
     * Routes to learn about, in a rotation stable for a given day: crc32 of the owner's day
     * and the route, never rand() — two runs must agree (docs/BUSINESS-LOGIC.md §16).
     *
     * @param  list<DealCandidate>  $unknown
     * @param  list<RelativePick>  $picks  already chosen, whose destinations are excluded
     * @return list<DealCandidate>
     */
    private function explore(array $unknown, array $picks, DateTimeImmutable $now): array
    {
        $seen = [];

        foreach ($picks as $pick) {
            $seen[$pick->candidate->destinationIata] = true;
        }

        $eligible = array_values(array_filter(
            $unknown,
            static fn (DealCandidate $candidate): bool => ! isset($seen[$candidate->destinationIata]),
        ));

        $seed = $now->format('Y-m-d');

        usort($eligible, static function (DealCandidate $a, DealCandidate $b) use ($seed): int {
            return crc32($seed.':lane-b-rotation:'.$a->routeCode())
                <=> crc32($seed.':lane-b-rotation:'.$b->routeCode())
                /*
                 * The route code breaks a hash collision: crc32 is 32 bits, so a collision is
                 * unlikely but not impossible, and the order still has to be total.
                 */
                ?: strcmp($a->routeCode(), $b->routeCode());
        });

        /*
         * One destination only once here too: the same city can reach the pool from two
         * origins, and two learning slots on one place halves what the run learns.
         */
        $picked = [];
        $cities = [];

        foreach ($eligible as $candidate) {
            if (isset($cities[$candidate->destinationIata])) {
                continue;
            }

            $cities[$candidate->destinationIata] = true;
            $picked[] = $candidate;
        }

        return $picked;
    }
}
