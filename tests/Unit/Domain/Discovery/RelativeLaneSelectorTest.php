<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Discovery;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use App\Domain\Discovery\PickReason;
use App\Domain\Discovery\RelativePick;
use PHPUnit\Framework\Attributes\Test;
use App\Domain\Discovery\DealCandidate;
use App\Domain\Discovery\RouteBaseline;
use App\Domain\Discovery\RelativeLanePolicy;
use App\Domain\Discovery\RelativeLaneSelector;

/**
 * How the second lane spends its three fetches — the flywheel, as arithmetic.
 *
 * NO FRAMEWORK AND NO DATABASE, which is the whole reason the selection is a
 * pure class. Every case below is "given these candidates and these remembered
 * baselines, which three routes get a request", and the answer is checkable by
 * reading.
 */
final class RelativeLaneSelectorTest extends TestCase
{
    private const NOW = '2026-08-16 05:20:00';

    private function policy(int $shortlist = 3): RelativeLanePolicy
    {
        return new RelativeLanePolicy(
            maxPriceCents: 15000,
            minDiscount: 0.40,
            minSavingsCents: 1500,
            minBaselineDays: 10,
            maxBaselineAgeDays: 30,
            shortlist: $shortlist,
        );
    }

    private function candidate(string $destination, int $euros, string $origin = 'AMS'): DealCandidate
    {
        return new DealCandidate(
            originIata: $origin,
            destinationIata: $destination,
            departureDate: new DateTimeImmutable('2026-10-24'),
            cents: $euros * 100,
            kilometres: 750.0,
            foundAt: new DateTimeImmutable('2026-08-15 08:00:00'),
        );
    }

    private function baseline(string $code, int $euros, int $sampleDays = 40, string $measuredAt = '2026-08-14 05:20:00'): RouteBaseline
    {
        return new RouteBaseline($code, $euros * 100, $sampleDays, new DateTimeImmutable($measuredAt));
    }

    /**
     * @param  list<DealCandidate>  $pool
     * @param  array<string, RouteBaseline>  $baselines
     * @param  list<string>  $exclude
     * @return list<RelativePick>
     */
    private function select(array $pool, array $baselines = [], array $exclude = [], int $shortlist = 3, string $now = self::NOW): array
    {
        return (new RelativeLaneSelector($this->policy($shortlist)))
            ->select($pool, $baselines, $exclude, new DateTimeImmutable($now));
    }

    /**
     * @param  list<RelativePick>  $picks
     * @return list<string>
     */
    private function codes(array $picks): array
    {
        return array_map(static fn (RelativePick $pick): string => $pick->candidate->routeCode(), $picks);
    }

    /**
     * =========================================================================
     * THE PRODUCT: A REMEMBERED BASELINE SAYS A FARE IS RARE
     * =========================================================================
     */
    #[Test]
    public function the_owners_dublin_case_is_picked_on_its_baseline(): void
    {
        /* €60 to Dublin against a remembered usual of €120 — 50% off. */
        $picks = $this->select(
            [$this->candidate('DUB', 60)],
            ['AMS-DUB' => $this->baseline('AMS-DUB', 120)],
        );

        $this->assertSame(['AMS-DUB'], $this->codes($picks));
        $this->assertSame(PickReason::Baseline, $picks[0]->reason);
        $this->assertEqualsWithDelta(0.5, $picks[0]->expectedDiscount(), 0.0001);
    }

    #[Test]
    public function baseline_picks_are_ordered_by_how_far_under_usual_they_are(): void
    {
        $picks = $this->select(
            [
                $this->candidate('DUB', 60),   /* vs 120 -> 50% */
                $this->candidate('LIS', 30),   /* vs 100 -> 70% */
                $this->candidate('OPO', 55),   /* vs 100 -> 45% */
            ],
            [
                'AMS-DUB' => $this->baseline('AMS-DUB', 120),
                'AMS-LIS' => $this->baseline('AMS-LIS', 100),
                'AMS-OPO' => $this->baseline('AMS-OPO', 100),
            ],
        );

        $this->assertSame(['AMS-LIS', 'AMS-DUB', 'AMS-OPO'], $this->codes($picks));
    }

    #[Test]
    public function a_fare_not_far_enough_under_its_usual_is_not_picked(): void
    {
        /* €70 against €100 is 30% — a good day on the route, not a rare one. */
        $picks = $this->select(
            [$this->candidate('DUB', 70)],
            ['AMS-DUB' => $this->baseline('AMS-DUB', 100)],
        );

        $this->assertSame([], $picks);
    }

    /**
     * THE THIN-ROUTE GUARD, and it is the reason a percentage alone is not the
     * rule. €17 against a €30 usual is 43% off and saves €13 — under the €15
     * floor, and a card announcing it would be announcing nothing.
     */
    #[Test]
    public function a_big_percentage_off_a_cheap_route_still_has_to_save_real_money(): void
    {
        $picks = $this->select(
            [$this->candidate('DUB', 17)],
            ['AMS-DUB' => $this->baseline('AMS-DUB', 30)],
        );

        $this->assertSame([], $picks);
    }

    /**
     * A KNOWN ROUTE THAT IS NOT RARE TODAY IS DROPPED, NOT EXPLORED.
     *
     * Orbit already knows what it costs; spending a fetch to learn it again is
     * the one thing the exploration budget must not do. It becomes explorable
     * again only when its baseline ages past `maxBaselineAgeDays`.
     */
    #[Test]
    public function a_known_route_that_is_ordinary_today_does_not_fall_back_into_exploration(): void
    {
        $picks = $this->select(
            [$this->candidate('DUB', 90)],
            ['AMS-DUB' => $this->baseline('AMS-DUB', 100)],
        );

        $this->assertSame([], $picks);
    }

    /**
     * =========================================================================
     * THE FLYWHEEL: WHAT COUNTS AS "NOT KNOWN"
     * =========================================================================
     */
    #[Test]
    public function a_route_with_no_baseline_is_explored(): void
    {
        $picks = $this->select([$this->candidate('DUB', 60)]);

        $this->assertSame(['AMS-DUB'], $this->codes($picks));
        $this->assertSame(PickReason::Exploration, $picks[0]->reason);
        $this->assertNull($picks[0]->expectedDiscount());
    }

    /**
     * A BASELINE OVER TOO FEW DATES IS NOT A USUAL PRICE, and the route goes
     * back to exploration to be re-measured rather than being disqualified.
     * That is what lets a thin measurement heal.
     */
    #[Test]
    public function a_baseline_built_on_too_few_days_is_treated_as_unknown(): void
    {
        $picks = $this->select(
            [$this->candidate('DUB', 60)],
            ['AMS-DUB' => $this->baseline('AMS-DUB', 120, sampleDays: 9)],
        );

        $this->assertSame(PickReason::Exploration, $picks[0]->reason);
    }

    #[Test]
    public function a_baseline_measured_ten_days_inside_the_limit_is_still_believed(): void
    {
        $picks = $this->select(
            [$this->candidate('DUB', 60)],
            ['AMS-DUB' => $this->baseline('AMS-DUB', 120, measuredAt: '2026-07-27 05:20:00')],
        );

        $this->assertSame(PickReason::Baseline, $picks[0]->reason);
    }

    /**
     * AND A YARDSTICK FROM THE SPRING IS NOT A YARDSTICK. 31 days old, one past
     * the limit — the route is re-measured rather than trusted.
     */
    #[Test]
    public function a_baseline_older_than_the_limit_is_treated_as_unknown(): void
    {
        $picks = $this->select(
            [$this->candidate('DUB', 60)],
            ['AMS-DUB' => $this->baseline('AMS-DUB', 120, measuredAt: '2026-07-16 04:20:00')],
        );

        $this->assertSame(PickReason::Exploration, $picks[0]->reason);
    }

    /**
     * =========================================================================
     * THE ORDER: CLAIMS BEFORE QUESTIONS
     * =========================================================================
     */
    #[Test]
    public function baseline_picks_take_the_slots_before_exploration_gets_any(): void
    {
        $picks = $this->select(
            [
                $this->candidate('DUB', 60),
                $this->candidate('LIS', 30),
                $this->candidate('OPO', 40),
                $this->candidate('FAO', 40),
            ],
            [
                'AMS-DUB' => $this->baseline('AMS-DUB', 120),
                'AMS-LIS' => $this->baseline('AMS-LIS', 100),
            ],
            shortlist: 3,
        );

        $this->assertCount(3, $picks);
        $this->assertSame(PickReason::Baseline, $picks[0]->reason);
        $this->assertSame(PickReason::Baseline, $picks[1]->reason);
        /* One slot was left, and exploration got exactly that one. */
        $this->assertSame(PickReason::Exploration, $picks[2]->reason);
    }

    #[Test]
    public function a_full_slate_of_baseline_picks_leaves_exploration_nothing(): void
    {
        $picks = $this->select(
            [
                $this->candidate('DUB', 60),
                $this->candidate('LIS', 30),
                $this->candidate('OPO', 40),
                $this->candidate('FAO', 40),
            ],
            [
                'AMS-DUB' => $this->baseline('AMS-DUB', 120),
                'AMS-LIS' => $this->baseline('AMS-LIS', 100),
                'AMS-OPO' => $this->baseline('AMS-OPO', 100),
            ],
        );

        $this->assertCount(3, $picks);

        foreach ($picks as $pick) {
            $this->assertSame(PickReason::Baseline, $pick->reason);
        }
    }

    #[Test]
    public function the_shortlist_is_a_hard_cap(): void
    {
        $pool = [];

        foreach (['DUB', 'LIS', 'OPO', 'FAO', 'BCN', 'MAD'] as $destination) {
            $pool[] = $this->candidate($destination, 60);
        }

        $this->assertCount(3, $this->select($pool));
    }

    /**
     * =========================================================================
     * DEDUPE — one city, one slot, across both lanes
     * =========================================================================
     */
    #[Test]
    public function a_destination_the_absolute_lane_took_is_never_picked_again(): void
    {
        $picks = $this->select(
            [$this->candidate('DUB', 60), $this->candidate('LIS', 30)],
            [
                'AMS-DUB' => $this->baseline('AMS-DUB', 120),
                'AMS-LIS' => $this->baseline('AMS-LIS', 100),
            ],
            exclude: ['LIS'],
        );

        $this->assertSame(['AMS-DUB'], $this->codes($picks));
    }

    /**
     * THE SAME CITY FROM TWO ORIGINS IS ONE CITY. Málaga appeared in both the
     * DUS and the EIN sweep on 2026-08-16, and two slots on one place is paying
     * twice to say one thing.
     */
    #[Test]
    public function one_city_reached_from_two_origins_takes_a_single_slot(): void
    {
        $picks = $this->select(
            [
                $this->candidate('AGP', 30, origin: 'DUS'),
                $this->candidate('AGP', 31, origin: 'EIN'),
            ],
            [
                'DUS-AGP' => $this->baseline('DUS-AGP', 78),
                'EIN-AGP' => $this->baseline('EIN-AGP', 78),
            ],
        );

        $this->assertSame(['DUS-AGP'], $this->codes($picks));
    }

    #[Test]
    public function exploration_also_refuses_to_spend_two_slots_on_one_city(): void
    {
        $picks = $this->select([
            $this->candidate('AGP', 30, origin: 'DUS'),
            $this->candidate('AGP', 31, origin: 'EIN'),
        ]);

        $this->assertCount(1, $picks);
    }

    /**
     * =========================================================================
     * THE ROTATION — deterministic, and it moves
     * =========================================================================
     */
    #[Test]
    public function the_same_day_and_the_same_pool_explore_the_same_routes(): void
    {
        $pool = [];

        foreach (['DUB', 'LIS', 'OPO', 'FAO', 'BCN', 'MAD', 'VNO', 'TIA'] as $destination) {
            $pool[] = $this->candidate($destination, 60);
        }

        $first = $this->codes($this->select($pool));
        $second = $this->codes($this->select($pool));

        $this->assertSame($first, $second);
        $this->assertCount(3, $first);
    }

    /**
     * THE POOL'S ORDER MUST NOT DECIDE THE ANSWER. A sweep that came back in a
     * different order — a provider reshuffling its JSON, an origin failing — has
     * to explore the same three routes, or "deterministic" means only "stable
     * given an input nobody controls".
     */
    #[Test]
    public function the_rotation_does_not_depend_on_the_order_the_sweep_arrived_in(): void
    {
        $destinations = ['DUB', 'LIS', 'OPO', 'FAO', 'BCN', 'MAD', 'VNO', 'TIA'];

        $pool = array_map(fn (string $d): DealCandidate => $this->candidate($d, 60), $destinations);
        $reversed = array_reverse($pool);

        $this->assertSame($this->codes($this->select($pool)), $this->codes($this->select($reversed)));
    }

    /**
     * AND IT RE-DEALS OVERNIGHT. Hashing the route alone would explore the same
     * three routes every morning forever — a flywheel that only ever turned
     * three cogs. The day is in the seed, so an unlucky route is not unlucky
     * permanently.
     */
    #[Test]
    public function a_different_day_explores_a_different_set(): void
    {
        $pool = [];

        foreach (['DUB', 'LIS', 'OPO', 'FAO', 'BCN', 'MAD', 'VNO', 'TIA', 'AGP', 'RAK'] as $destination) {
            $pool[] = $this->candidate($destination, 60);
        }

        $today = $this->codes($this->select($pool, now: '2026-08-16 05:20:00'));
        $tomorrow = $this->codes($this->select($pool, now: '2026-08-17 05:20:00'));

        $this->assertNotSame($today, $tomorrow);
    }

    /**
     * THE CLOCK INSIDE A DAY MUST NOT MATTER. A hand-run of `orbit:discover` at
     * lunchtime has to reproduce the 05:20 schedule's answer — the job is
     * idempotent by design, and a rotation that read the hour would quietly
     * break that.
     */
    #[Test]
    public function the_time_of_day_does_not_change_the_rotation(): void
    {
        $pool = [];

        foreach (['DUB', 'LIS', 'OPO', 'FAO', 'BCN', 'MAD'] as $destination) {
            $pool[] = $this->candidate($destination, 60);
        }

        $this->assertSame(
            $this->codes($this->select($pool, now: '2026-08-16 05:20:00')),
            $this->codes($this->select($pool, now: '2026-08-16 13:47:11')),
        );
    }

    #[Test]
    public function an_empty_pool_is_an_empty_answer(): void
    {
        $this->assertSame([], $this->select([]));
    }
}
