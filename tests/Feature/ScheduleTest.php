<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use App\Application\Pricing\FareRequestBudget;

/**
 * The clock, asserted rather than remembered — a wrong schedule is invisible
 * (docs/BUSINESS-LOGIC.md §13, docs/BUSINESS-LOGIC.md §36).
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
     * The second speed runs in the empty 04:00 hour, not 06:00 — and does not
     * replace that day's ordinary poll (docs/BUSINESS-LOGIC.md §13).
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
     * Round trips go where there is room, not where the other polls are
     * (docs/BUSINESS-LOGIC.md §13).
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
     * The gap is the per-minute limit: two fan-outs must not queue on the same
     * minute. Their overlapping cost is counted, not avoided (§27).
     */
    #[Test]
    public function the_returns_run_starts_well_clear_of_the_far_polls_first_jobs(): void
    {
        $far = $this->minuteOfDay('orbit:poll-fares --far');
        $returns = $this->minuteOfDay('orbit:poll-returns');

        $this->assertSame(intdiv($far, 60), intdiv($returns, 60), 'Both start in the 04:00 hour.');
        $this->assertGreaterThanOrEqual(30, $returns - $far, 'The two fan-outs must not start together.');
    }

    /**
     * The budget model is checked against this file rather than against a
     * memory of it (docs/BUSINESS-LOGIC.md §27).
     */
    #[Test]
    public function the_request_budget_reads_the_clock_that_is_actually_scheduled(): void
    {
        $scheduled = [
            'orbit:poll-fares --far' => FareRequestBudget::FAR_POLL_AT,
            'orbit:poll-returns'     => FareRequestBudget::RETURNS_POLL_AT,
            'orbit:discover'         => FareRequestBudget::DISCOVERY_AT,
            'orbit:poll-fares'       => FareRequestBudget::NEAR_POLL_AT,
            'orbit:sweep-rules'      => FareRequestBudget::RULE_SWEEP_AT,
        ];

        foreach ($scheduled as $command => $clock) {
            $minute = $this->minuteOfDay($command);

            $this->assertSame(
                $clock,
                sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60),
                "FareRequestBudget puts {$command} at {$clock}; the scheduler does not.",
            );
        }
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

        $this->assertSame('35 7 * * *', $event->expression);
        $this->assertSame('Europe/Amsterdam', $event->timezone);
    }

    /**
     * The alert run has to clear the fan-out it reads, and stay inside the
     * default quiet window that holds the mail (docs/BUSINESS-LOGIC.md §13).
     */
    #[Test]
    public function the_alert_run_clears_the_polls_fan_out_and_still_sits_inside_quiet_hours(): void
    {
        $poll = $this->minuteOfDay('orbit:poll-fares');
        $alerts = $this->minuteOfDay('orbit:alerts');
        $stagger = (int) config('orbit.poll.stagger_minutes');

        // Twelve staggers is a thirteen-route fan-out; 13 minutes is twice the
        // worst case of the poll job it has to wait for.
        $this->assertGreaterThanOrEqual(
            $poll + $stagger * 12 + 13,
            $alerts,
            'Widening the stagger without moving the alert run silently drops the tail of the watchlist.',
        );

        $this->assertLessThan(
            8 * 60,
            $alerts,
            'Past 08:00 the default quiet window stops holding the mail, and delivery time changes.',
        );
    }

    #[Test]
    public function the_digest_goes_out_on_sunday_morning(): void
    {
        $event = $this->find('orbit:digest');

        $this->assertSame('0 9 * * 0', $event->expression);
        $this->assertSame('Europe/Amsterdam', $event->timezone);
    }

    /**
     * The order is load-bearing, not a preference (docs/BUSINESS-LOGIC.md §13).
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
     * `vite.config.js` never empties public/build — this is the only thing
     * that deletes an old chunk (docs/BUSINESS-LOGIC.md §36).
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
     * Named commands, never closures that would enumerate routes —
     * `routes/console.php` loads on every artisan call, even on an empty DB.
     */
    #[Test]
    public function nothing_scheduled_queries_the_database_to_be_defined(): void
    {
        foreach ($this->events() as $event) {
            $this->assertStringContainsString('artisan', (string) $event->command);
        }
    }
}
