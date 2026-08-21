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
 * Pure class, no framework/DB — selection logic is checkable by reading
 * test cases (docs/BUSINESS-LOGIC.md §16).
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

    #[Test]
    public function the_owners_dublin_case_is_picked_on_its_baseline(): void
    {
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
                $this->candidate('DUB', 60),
                $this->candidate('LIS', 30),
                $this->candidate('OPO', 55),
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
        $picks = $this->select(
            [$this->candidate('DUB', 70)],
            ['AMS-DUB' => $this->baseline('AMS-DUB', 100)],
        );

        $this->assertSame([], $picks);
    }

    /**
     * Thin-route guard: a big percentage off a cheap route still must clear
     * the min savings floor (docs/BUSINESS-LOGIC.md §16).
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
     * A known route that isn't rare today is dropped, not re-explored
     * (docs/BUSINESS-LOGIC.md §16).
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

    #[Test]
    public function a_route_with_no_baseline_is_explored(): void
    {
        $picks = $this->select([$this->candidate('DUB', 60)]);

        $this->assertSame(['AMS-DUB'], $this->codes($picks));
        $this->assertSame(PickReason::Exploration, $picks[0]->reason);
        $this->assertNull($picks[0]->expectedDiscount());
    }

    /**
     * A thin baseline returns to exploration to be re-measured, not
     * disqualified — that's what lets it heal (docs/BUSINESS-LOGIC.md §16).
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
     * A baseline 31 days old (one past maxBaselineAgeDays) is re-measured, not trusted.
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
     * Same city from two origins is one city — one place never takes two
     * slots (docs/BUSINESS-LOGIC.md §16).
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
     * Pool order must not decide the answer (docs/BUSINESS-LOGIC.md §16).
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
     * The rotation seed includes the day, not just the route — otherwise
     * the same three routes explore forever (docs/BUSINESS-LOGIC.md §16).
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
     * The time of day must not change the rotation — a hand-run has to
     * reproduce the 05:20 schedule's answer (docs/BUSINESS-LOGIC.md §16).
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
