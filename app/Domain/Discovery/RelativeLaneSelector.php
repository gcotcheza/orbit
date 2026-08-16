<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use DateTimeImmutable;

/**
 * The relative lane's shortlist — and the flywheel that makes it better every
 * morning it runs.
 *
 * PURE, LIKE App\Domain\Discovery\CandidateScorer AND FOR THE SAME REASON.
 * Selection is the only part of this lane that can be judged by reading it, so
 * it is separated out where the tests are arithmetic and a threshold can be
 * moved without a fixture in sight. It is handed the pool, the remembered
 * baselines and a date; it returns an ordered list of picks and touches nothing.
 *
 * =============================================================================
 * THE PROBLEM: THREE FETCHES, SIXTY CANDIDATES, AND NO FREE SIGNAL
 * =============================================================================
 * On the 2026-08-16 sweep roughly sixty candidates cleared the sanity floors and
 * failed the absolute lane. The budget is three window fetches. So something has
 * to choose three, and — this is the whole difficulty — THE SWEEP CONTAINS
 * NOTHING THAT CAN CHOOSE THEM. A sweep is one cheapest cached fare per
 * destination (measured: the maximum rows for any origin-destination pair is 1),
 * so it holds no distribution for any single route and cannot say which of the
 * sixty is unusual FOR ITSELF. Every free ranking derivable from it — price,
 * distance band, price against a band median — is a re-spelling of €/km, which
 * is the lane next door. See App\Domain\Discovery\Lane for the three
 * measurements that killed the band-median version of this.
 *
 * =============================================================================
 * THE ANSWER: SPEND THE BUDGET LEARNING WHAT IT LACKS
 * =============================================================================
 * The honest baseline for "usual on this route" is the route's own window
 * median — the number DUS-AGP's €78 came from, the number `savings_cents` is
 * already measured against. It costs a fetch. So:
 *
 *   1. KNOWN ROUTES GO FIRST. Any candidate whose route Orbit has already
 *      measured, whose baseline is thick enough and recent enough
 *      (RelativeLanePolicy::admitsBaseline), and whose fare is far enough under
 *      that baseline (::isRare) is a pick — ranked by how far under. These are
 *      the lane's actual product: a fetch spent on a claim Orbit already
 *      half-believes.
 *
 *   2. EXPLORATION TAKES WHAT IS LEFT. Slots the known routes did not fill go to
 *      routes Orbit knows nothing about, in a deterministic rotation. The fetch
 *      answers "what does this route usually cost" and THE ANSWER IS KEPT —
 *      which is the flywheel: tomorrow those routes are known, and the day after
 *      there are more of them.
 *
 * AN EXPLORED ROUTE SURFACES ONLY IF IT EARNS IT. It goes through the identical
 * verification an absolute finalist does (bottom tenth of its own window, €15
 * under its own median) and most will fail, which is correct rather than
 * disappointing: the fetch already paid for itself by leaving a baseline behind.
 * A lane that showed a card for every route it explored would be a lane with no
 * standard at all.
 *
 * ON DAY ONE THIS LANE IS ALL EXPLORATION AND SURFACES ALMOST NOTHING. That is
 * the honest shape of it and is written down here so nobody tunes it away: the
 * first run has no baselines, so all three slots explore, and the first relative
 * CARD cannot appear until a route Orbit measured turns up cheap on a later
 * morning. It gets smarter every day it runs, and it starts knowing nothing.
 */
final readonly class RelativeLaneSelector
{
    public function __construct(private RelativeLanePolicy $policy) {}

    /**
     * The candidates this lane will spend its window fetches on, in the order
     * they should be spent.
     *
     * @param  list<DealCandidate>  $pool  candidates that cleared the sanity
     *                                     floors and are not the absolute lane's
     * @param  array<string, RouteBaseline>  $baselines  keyed by route code
     * @param  list<string>  $excludeDestinations  destination IATAs the absolute
     *                                             lane already took
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
             * ONE DESTINATION ONLY ONCE, ACROSS BOTH LANES AND ACROSS ORIGINS —
             * the rule CandidateScorer::shortlist() applies within the absolute
             * lane, extended to the pair of them. Málaga appeared in both the
             * DUS and EIN sweeps on 2026-08-16, and a screen that spent an
             * absolute slot AND a relative slot on the same city would be paying
             * twice to tell the owner about one place.
             */
            if (isset($taken[$candidate->destinationIata])) {
                continue;
            }

            $baseline = $baselines[$candidate->routeCode()] ?? null;

            /*
             * A BASELINE THAT IS TOO THIN OR TOO OLD COUNTS AS NO BASELINE, and
             * the candidate drops into the exploration pool rather than out of
             * the lane. That is what lets a bad measurement heal: the rotation
             * will eventually re-fetch the route and replace it.
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
         * DEEPEST DISCOUNT FIRST, ROUTE CODE BREAKING THE TIE — the same total
         * order CandidateScorer::admit() imposes, for the same reason. Two
         * candidates at an identical discount is not a case worth relying on
         * `usort`'s stability for, and a lane that reordered itself between two
         * runs with identical inputs would look like news.
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
     * Routes to learn about, in a rotation that is stable for a given day.
     *
     * =========================================================================
     * DETERMINISTIC, AND `rand()` WOULD HAVE BEEN A BUG RATHER THAN A SHORTCUT
     * =========================================================================
     * The obvious spelling is "shuffle the unknowns and take three". It would
     * make this lane untestable — a feature test could assert only that SOMETHING
     * was explored, never that the right thing was — and it would make two runs
     * on the same morning disagree, which matters because this job is
     * idempotent by design and a hand-run of `orbit:discover` is meant to
     * reproduce the scheduled one.
     *
     * SO THE ORDER IS A HASH OF THE DAY AND THE ROUTE, which is the same
     * technique App\Infrastructure\Discovery\FakeSweepProvider uses for its
     * holes and its dates, and for the same reason: stable on this box, in CI,
     * and after `docker compose down -v`.
     *
     * THE DATE IS IN THE SEED SO THE ROTATION MOVES. Hashing the route alone
     * would produce one fixed order forever — the same three routes explored
     * every morning until one of them got a baseline, and a flywheel that only
     * ever turned three cogs. Hashing the day with it re-deals the pool nightly,
     * so the unknown set is covered by sampling rather than by exhaustion, and a
     * route that is unlucky today is not unlucky permanently.
     *
     * THE OWNER'S DAY, NOT UTC'S — `$now` arrives already resolved to
     * `orbit.timezone` by App\Jobs\DiscoverDeals, so a run at 05:20 Amsterdam
     * time seeds on the date the owner would call today.
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
                 * THE ROUTE CODE BREAKS A HASH COLLISION. crc32 is 32 bits over
                 * a pool of a few dozen, so a collision is vanishingly unlikely
                 * and not impossible — and the answer still has to be total.
                 */
                ?: strcmp($a->routeCode(), $b->routeCode());
        });

        /*
         * ONE DESTINATION ONLY ONCE HERE TOO. The same city can reach the
         * exploration pool from two origins, and spending two of three learning
         * slots on one place would halve what the run learns.
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
