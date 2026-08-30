<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use App\Domain\Pricing\ProviderRun;
use App\Domain\Pricing\RequestBudget;
use PHPUnit\Framework\Attributes\Test;

/**
 * The stagger arithmetic on its own: no config, no database, no framework
 * (docs/BUSINESS-LOGIC.md §27).
 */
final class RequestBudgetTest extends TestCase
{
    #[Test]
    public function a_single_job_costs_its_whole_price_in_the_hour_it_starts(): void
    {
        $budget = new RequestBudget([ProviderRun::single('sweep', '06:40', 120)], staggerMinutes: 6, watchedRoutes: 13);

        $this->assertSame([6 => 120], $budget->perClockHour());
    }

    #[Test]
    public function a_fan_out_is_charged_to_the_hour_each_route_actually_lands_in(): void
    {
        $budget = new RequestBudget([ProviderRun::fanOut('poll', '06:10', 7)], staggerMinutes: 6, watchedRoutes: 13);

        // 06:10 + 6i: routes 0-8 land by 06:58, route 9 at 07:04.
        $this->assertSame([6 => 63, 7 => 28], $budget->perClockHour());
    }

    #[Test]
    public function without_a_stagger_the_whole_fan_out_lands_in_one_hour(): void
    {
        $budget = new RequestBudget([ProviderRun::fanOut('poll', '06:10', 7)], staggerMinutes: 0, watchedRoutes: 13);

        $this->assertSame([6 => 91], $budget->perClockHour());
    }

    #[Test]
    public function a_fan_out_long_enough_to_pass_midnight_wraps_onto_the_clock(): void
    {
        $budget = new RequestBudget([ProviderRun::fanOut('poll', '23:50', 1)], staggerMinutes: 30, watchedRoutes: 3);

        $this->assertSame([0 => 2, 23 => 1], $budget->perClockHour());
    }

    #[Test]
    public function the_peak_is_the_busiest_hour_and_it_says_which_one(): void
    {
        $budget = new RequestBudget(
            [
                ProviderRun::fanOut('far poll', '04:10', 12),
                ProviderRun::single('discovery', '05:20', 59),
                ProviderRun::fanOut('fare poll', '06:10', 7),
                ProviderRun::single('rule sweep', '06:40', 120),
            ],
            staggerMinutes: 6,
            watchedRoutes: 13,
        );

        $this->assertSame([4 => 108, 5 => 107, 6 => 183, 7 => 28], $budget->perClockHour());
        $this->assertSame(183, $budget->peak());
        $this->assertSame(6, $budget->busiestHour());
        $this->assertFalse($budget->exceeds(200));
        $this->assertTrue($budget->exceeds(182));
    }

    #[Test]
    public function an_empty_watchlist_costs_only_what_does_not_fan_out(): void
    {
        $budget = new RequestBudget(
            [ProviderRun::fanOut('poll', '06:10', 7), ProviderRun::single('sweep', '06:40', 120)],
            staggerMinutes: 6,
            watchedRoutes: 0,
        );

        $this->assertSame([6 => 120], $budget->perClockHour());
        $this->assertSame(120, $budget->peak());
    }

    #[Test]
    public function the_same_schedule_can_be_asked_about_a_different_watchlist(): void
    {
        $budget = new RequestBudget([ProviderRun::fanOut('poll', '06:10', 7)], staggerMinutes: 0, watchedRoutes: 13);

        $this->assertSame(91, $budget->peak());
        $this->assertSame(140, $budget->withWatchedRoutes(20)->peak());
        $this->assertSame(91, $budget->peak(), 'withWatchedRoutes() must not rewrite the budget it was asked from.');
    }

    #[Test]
    public function a_run_scheduled_at_something_that_is_not_a_time_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a 24-hour clock time: 24:10.');

        ProviderRun::fanOut('poll', '24:10', 7);
    }

    #[Test]
    public function a_negative_stagger_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A stagger of -1 minutes is not a delay.');

        new RequestBudget([], staggerMinutes: -1, watchedRoutes: 13);
    }

    #[Test]
    public function a_negative_watchlist_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A watchlist cannot hold -1 routes.');

        new RequestBudget([], staggerMinutes: 6, watchedRoutes: -1);
    }
}
