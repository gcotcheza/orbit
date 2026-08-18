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

    /**
     * THE SECOND SPEED, AND THE HOUR IS THE POINT OF IT.
     *
     * Orbit maintains eleven months of calendar and fetches the near six every
     * morning; this run fills in months 7 to 11. It costs twelve provider calls
     * per watched route where the daily poll costs seven, and Travelpayouts
     * allows ~200 an hour per IP — so in the 06:00 hour, beside the rule sweep's
     * 120, nine watched routes would be 228 and over the limit. In an otherwise
     * empty 04:00 hour it is 108, and the ordinary morning is left exactly as it
     * was. config/orbit.php's `poll` section carries the whole table.
     *
     * IT DOES NOT REPLACE THAT DAY'S POLL: the daily entry still runs four hours
     * later, and both write the same observation from the same near window.
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
     * ROUND TRIPS GO WHERE THERE IS ROOM, NOT WHERE THE OTHER POLLS ARE.
     *
     * One request per watched route, flat — `/v2/prices/latest` answers for the
     * whole horizon in a single call — so nine today. The 06:00 hour is already
     * at 183 of Travelpayouts' ~200 (poll 63 + sweep 120) and is the hour that
     * breaks first as the watchlist grows; the 04:00 hour holds 108 on Saturday
     * and nothing at all on the other six mornings. 108 + 9 = 117.
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
     * THE GAP IS THE PER-MINUTE LIMIT, NOT THE HOURLY ONE.
     *
     * Both of these share the 04:00 hour on a Saturday and both are staggered
     * fan-outs: nine routes at `orbit.poll.stagger_minutes` is twenty-four
     * minutes, so the far poll is still queueing jobs until 04:34. Starting the
     * returns run at 04:20 would interleave the two and hand the provider two
     * bursts in the same minutes — which is the one thing the stagger exists to
     * prevent, and which the hourly arithmetic above would not notice.
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
