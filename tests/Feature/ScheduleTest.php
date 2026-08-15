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

    /**
     * `vite.config.js` stops the build emptying public/build, which makes this
     * the only thing that ever deletes an old chunk. The deploy runs it too;
     * the schedule is what keeps a forgotten deploy step from becoming a full
     * disk.
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
    public function neither_job_can_start_on_top_of_itself(): void
    {
        $this->assertTrue($this->find('orbit:poll-fares')->withoutOverlapping);
        $this->assertTrue($this->find('orbit:refresh-stats')->withoutOverlapping);
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
