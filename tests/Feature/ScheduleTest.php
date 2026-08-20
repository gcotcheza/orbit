<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The clock, asserted rather than remembered.
 *
 * A wrong schedule is invisible — nothing errors, prices just look a day
 * old, and a missing timezone drifts an hour twice a year unnoticed.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final class ScheduleTest extends TestCase
{
    /**
     * @return array<string, Event>
     */
    private function events(): array
    {
        $events = [];

        foreach ($this->app->make(Schedule::class)->events() as $event) {
            $events[(string) $event->command] = $event;
        }

        return $events;
    }

    private function find(string $command): Event
    {
        foreach ($this->events() as $signature => $event) {
            if (str_contains($signature, $command)) {
                return $event;
            }
        }

        $this->fail("Nothing scheduled for {$command}.");
    }

    #[Test]
    public function fares_are_polled_every_morning_before_the_owner_is_awake(): void
    {
        $event = $this->find('orbit:poll-fares');

        $this->assertSame('10 6 * * *', $event->expression);
        $this->assertSame('Europe/Amsterdam', $event->timezone);
    }

    /**
     * The second speed, and the hour is the point of it: months 7-11 cost 12
     * calls/route vs. 7 daily, so this runs in the empty 04:00 hour, not 06:00.
     * Why: docs/BUSINESS-LOGIC.md §36.
     *
     * Does not replace that day's poll — the daily entry still runs four
     * hours later, both writing the same near-window observation.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    #[Test]
    public function the_far_months_are_refreshed_once_a_week_in_an_hour_of_their_own(): void
    {
        $event = $this->find('orbit:poll-fares --far');

        $this->assertSame('10 4 * * 6', $event->expression);
        $this->assertSame('Europe/Amsterdam', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);

        /* The expression above is that config key, not a coincidence. */
        $this->assertSame(6, (int) config('orbit.poll.far_refresh_weekday'));

        $sweep = $this->minuteOfDay('orbit:sweep-rules');
        $far = $this->minuteOfDay('orbit:poll-fares --far');

        $this->assertLessThan(
            intdiv($sweep, 60),
            intdiv($far, 60),
            'The far run has to land in an earlier clock hour than the sweep, or the two share a rate limit.',
        );
    }

    /**
     * Round trips go where there is room, not where the other polls are: the
     * 06:00 hour is already at 183/~200; 04:00 has 108 on Saturday, 0 otherwise.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    #[Test]
    public function round_trips_are_polled_in_the_hour_the_budget_left_free(): void
    {
        $event = $this->find('orbit:poll-returns');

        $this->assertSame('40 4 * * *', $event->expression);
        $this->assertSame('Europe/Amsterdam', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);

        $returns = $this->minuteOfDay('orbit:poll-returns');
        $poll = $this->minuteOfDay('orbit:poll-fares');
        $sweep = $this->minuteOfDay('orbit:sweep-rules');

        /* Not in the hour that is at 92% of the allowance before it arrives. */
        $this->assertLessThan(intdiv($poll, 60), intdiv($returns, 60));
        $this->assertLessThan(intdiv($sweep, 60), intdiv($returns, 60));
    }

    /**
     * The gap is the per-minute limit, not the hourly one: nine routes stagger
     * over 24 minutes, so returns must start after the far poll's fan-out ends.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    #[Test]
    public function the_returns_run_starts_after_the_far_polls_fan_out_is_away(): void
    {
        $far = $this->minuteOfDay('orbit:poll-fares --far');
        $returns = $this->minuteOfDay('orbit:poll-returns');

        $this->assertSame(intdiv($far, 60), intdiv($returns, 60), 'Both belong in the 04:00 hour.');
        $this->assertGreaterThanOrEqual(
            30,
            $returns - $far,
            'The far fan-out takes 24 minutes at nine routes; the returns run must not start inside it.',
        );
    }

    #[Test]
    public function statistics_are_refreshed_on_monday_ahead_of_that_mornings_poll(): void
    {
        $event = $this->find('orbit:refresh-stats');

        $this->assertSame('40 5 * * 1', $event->expression);
        $this->assertSame('Europe/Amsterdam', $event->timezone);
    }

    #[Test]
    public function rules_are_swept_every_morning_after_the_watchlist_poll(): void
    {
        $event = $this->find('orbit:sweep-rules');

        $this->assertSame('40 6 * * *', $event->expression);
        $this->assertSame('Europe/Amsterdam', $event->timezone);
    }

    #[Test]
    public function alerts_are_evaluated_after_both_of_the_runs_that_feed_them(): void
    {
        $event = $this->find('orbit:alerts');

        $this->assertSame('55 6 * * *', $event->expression);
        $this->assertSame('Europe/Amsterdam', $event->timezone);
    }

    #[Test]
    public function the_digest_goes_out_on_sunday_morning(): void
    {
        $event = $this->find('orbit:digest');

        $this->assertSame('0 9 * * 0', $event->expression);
        $this->assertSame('Europe/Amsterdam', $event->timezone);
    }

    /**
     * The order is load-bearing, not a preference: sweeping before the poll
     * wastes budget re-fetching the watchlist; alerts before both mails stale prices.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    #[Test]
    public function the_morning_runs_in_the_order_each_one_depends_on(): void
    {
        $poll = $this->minuteOfDay('orbit:poll-fares');
        $sweep = $this->minuteOfDay('orbit:sweep-rules');
        $alerts = $this->minuteOfDay('orbit:alerts');

        $this->assertGreaterThan($poll, $sweep);
        $this->assertGreaterThan($sweep, $alerts);
    }

    private function minuteOfDay(string $command): int
    {
        [$minute, $hour] = explode(' ', $this->find($command)->expression);

        return ((int) $hour) * 60 + (int) $minute;
    }

    /**
     * `vite.config.js` stops the build emptying public/build, so this is the
     * only thing that deletes an old chunk; the schedule backstops the deploy step.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    #[Test]
    public function old_builds_are_pruned_nightly(): void
    {
        $event = $this->find('build:retain');

        $this->assertSame('10 3 * * *', $event->expression);
        $this->assertSame('Europe/Amsterdam', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    /**
     * Two polls writing the same day's observation at once would race on the
     * upsert; the second one is held instead.
     */
    #[Test]
    public function no_job_can_start_on_top_of_itself(): void
    {
        $this->assertTrue($this->find('orbit:poll-fares')->withoutOverlapping);
        $this->assertTrue($this->find('orbit:poll-returns')->withoutOverlapping);
        $this->assertTrue($this->find('orbit:refresh-stats')->withoutOverlapping);
        $this->assertTrue($this->find('orbit:sweep-rules')->withoutOverlapping);
        $this->assertTrue($this->find('orbit:alerts')->withoutOverlapping);
        $this->assertTrue($this->find('orbit:digest')->withoutOverlapping);
    }

    /**
     * The schedule names commands, never closures that would enumerate routes
     * — routes/console.php loads on every artisan call, even `migrate` on empty DB.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    #[Test]
    public function nothing_scheduled_queries_the_database_to_be_defined(): void
    {
        foreach ($this->events() as $event) {
            $this->assertStringContainsString('artisan', (string) $event->command);
        }
    }
}
