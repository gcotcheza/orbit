<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The clock, asserted rather than remembered.
 *
 * A schedule is invisible when it is wrong: nothing errors, no page breaks, and
 * the only symptom is prices that are a day old — which looks exactly like
 * prices that have not moved. Worse, a timezone left off drifts an hour twice a
 * year and nobody notices in either direction.
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
     * THE ORDER IS LOAD-BEARING, not a preference.
     *
     * App\Jobs\SweepRuleFares skips any route the morning has already priced,
     * so sweeping before the poll would spend a rule's capped budget
     * re-fetching the watchlist and the routes only a rule cares about would
     * never get their turn. And the alert run reads what both of them wrote:
     * going first would not fail, it would simply mail this morning's verdict
     * on yesterday's prices, every day, invisibly.
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
     * Two polls writing the same day's observation at once would race on the
     * upsert; the second one is held instead.
     */
    #[Test]
    public function no_job_can_start_on_top_of_itself(): void
    {
        $this->assertTrue($this->find('orbit:poll-fares')->withoutOverlapping);
        $this->assertTrue($this->find('orbit:refresh-stats')->withoutOverlapping);
        $this->assertTrue($this->find('orbit:sweep-rules')->withoutOverlapping);
        $this->assertTrue($this->find('orbit:alerts')->withoutOverlapping);
        $this->assertTrue($this->find('orbit:digest')->withoutOverlapping);
    }

    /**
     * The schedule names COMMANDS, never closures or jobs that would have to
     * enumerate routes. routes/console.php is loaded on every artisan
     * invocation, including `migrate` against an empty database.
     */
    #[Test]
    public function nothing_scheduled_queries_the_database_to_be_defined(): void
    {
        foreach ($this->events() as $event) {
            $this->assertStringContainsString('artisan', (string) $event->command);
        }
    }
}
