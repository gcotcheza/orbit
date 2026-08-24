<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Alert;
use App\Models\UserSettings;
use App\Jobs\SendWeeklyDigest;
use App\Domain\Alerts\AlertType;
use Tests\Concerns\RunsCommands;
use Tests\Concerns\BuildsRuleData;
use App\Notifications\WeeklyDigest;
use Tests\Concerns\BuildsAlertData;
use Tests\Concerns\BuildsRouteData;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Sunday, 09:00 Europe/Amsterdam — 07:00 UTC on 2026-08-16, the hour
 * routes/console.php schedules, an hour outside the default quiet window.
 */
final class WeeklyDigestTest extends TestCase
{
    use BuildsAlertData, BuildsRouteData, BuildsRuleData, RefreshDatabase, RunsCommands;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-16 07:00:00');

        $this->owner = User::factory()->create();
    }

    private function send(): void
    {
        $this->app->call([new SendWeeklyDigest($this->owner->id), 'handle']);
    }

    private function settings(): UserSettings
    {
        return UserSettings::for($this->owner);
    }

    #[Test]
    public function it_summarises_the_watchlist_the_rules_and_the_week(): void
    {
        Notification::fake();

        /* The three origin airports first — makeRoute() would otherwise have
         * created AMS already, and `airports.iata` is unique. */
        $this->makeOrigins();

        $route = $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);
        $this->watchedRoute($this->owner, 'LIS', self::GREAT_CENTS);

        $this->makeDestination('FAO', ['beach']);
        $this->makeRouteWithFares('AMS', 'FAO', ['2026-09-04' => 3900]);
        $this->makeRule($this->owner, 'a beach somewhere under €80', [
            'origins'       => ['AMS'],
            'vibes'         => ['beach'],
            'maxPriceCents' => 8000,
        ]);

        $this->alerted($this->owner, AlertType::RouteDeal, $route, null, self::INSANE_CENTS, '2026-08-14 06:55:00');

        $this->send();

        Notification::assertSentTo(
            $this->owner,
            WeeklyDigest::class,
            function (WeeklyDigest $digest): bool {
                $notice = $digest->notice;

                return count($notice->routes) === 2
                    /* Best first: 94 before 72. */
                    && $notice->routes[0]->score === 94
                    && count($notice->rules) === 1
                    && $notice->rules[0]->matches === 1
                    && count($notice->week) === 1
                    && $notice->subject() === 'Your week in fares — 1 deal Orbit flagged';
            },
        );
    }

    #[Test]
    public function the_digest_is_written_to_the_ledger_as_counts_rather_than_deals(): void
    {
        Notification::fake();

        $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);

        $this->send();

        $digest = Alert::query()->where('type', AlertType::WeeklyDigest)->sole();

        $this->assertNull($digest->route_id);
        $this->assertNull($digest->deal_rule_id);
        $this->assertNull($digest->score);
        $this->assertNull($digest->price_cents);
        $this->assertSame(1, $digest->payload['routes']);
        $this->assertSame(0, $digest->payload['rules']);
        $this->assertSame(0, $digest->payload['week']);
        $this->assertSame('2026-08-16 07:00:00', $digest->triggered_at->format('Y-m-d H:i:s'));
    }

    /**
     * THE COOLDOWN DOES NOT APPLY HERE, deliberately — the digest is not an
     * interruption; its whole job is to say where things stand.
     */
    #[Test]
    public function a_route_that_has_been_cooling_down_all_week_is_still_in_it(): void
    {
        Notification::fake();

        $route = $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);
        $this->alerted($this->owner, AlertType::RouteDeal, $route, null, self::INSANE_CENTS, '2026-08-16 06:55:00');

        $this->send();

        Notification::assertSentTo(
            $this->owner,
            WeeklyDigest::class,
            fn (WeeklyDigest $digest): bool => count($digest->notice->routes) === 1,
        );
    }

    #[Test]
    public function an_account_with_nothing_to_say_gets_no_mail_and_no_row(): void
    {
        Notification::fake();

        $this->send();

        Notification::assertNothingSent();
        $this->assertSame(0, Alert::query()->count());
    }

    /**
     * A route Orbit has never priced is not something to say — the design's
     * answer is the "tracking N days" note on screen, not a verdict in mail.
     */
    #[Test]
    public function a_watchlist_of_routes_with_no_prices_is_nothing_to_say(): void
    {
        Notification::fake();

        $this->watch($this->owner, $this->makeRoute('AMS', 'OPO'));

        $this->send();

        Notification::assertNothingSent();
        $this->assertSame(0, Alert::query()->count());
    }

    #[Test]
    public function the_digest_switched_off_costs_nothing_and_records_nothing(): void
    {
        Notification::fake();

        $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);
        $this->settings()->update(['weekly_digest' => false]);

        $this->send();

        Notification::assertNothingSent();
        $this->assertSame(0, Alert::query()->count());
    }

    /**
     * The deal alerts and the digest are two subscriptions (design/README.md
     * §6) — wanting the summary without mid-week pings is a valid choice.
     */
    #[Test]
    public function the_digest_is_sent_even_when_the_deal_alerts_are_off(): void
    {
        Notification::fake();

        $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);
        $this->settings()->update(['email_alerts' => false]);

        $this->send();

        Notification::assertSentTo($this->owner, WeeklyDigest::class);
    }

    /**
     * A deal still queued behind quiet hours has not been seen — counting it
     * would summarise mail somebody is about to receive.
     */
    #[Test]
    public function the_week_counts_only_what_was_actually_delivered(): void
    {
        Notification::fake();

        $route = $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);

        $this->alerted($this->owner, AlertType::RouteDeal, $route, null, 4400, '2026-08-14 06:55:00');
        $this->alerted($this->owner, AlertType::RouteDeal, $route, null, 4300, '2026-08-15 06:55:00', delivered: false);
        /* Older than config('orbit.alerts.digest_days'). */
        $this->alerted($this->owner, AlertType::RouteDeal, $route, null, 4200, '2026-08-01 06:55:00');

        $this->send();

        Notification::assertSentTo(
            $this->owner,
            WeeklyDigest::class,
            fn (WeeklyDigest $digest): bool => count($digest->notice->week) === 1
                && $digest->notice->week[0]->priceCents === 4400,
        );
    }

    #[Test]
    public function quiet_hours_hold_the_digest_too(): void
    {
        Notification::fake();

        $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);
        $this->settings()->update(['quiet_start' => '08:00', 'quiet_end' => '10:00']);

        $this->send();

        Notification::assertSentTo(
            $this->owner,
            WeeklyDigest::class,
            fn (WeeklyDigest $digest): bool => $digest->delay !== null,
        );
    }

    #[Test]
    public function the_mail_lists_the_watchlist_and_leads_with_the_cheapest(): void
    {
        $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);
        $this->watchedRoute($this->owner, 'LIS', self::GREAT_CENTS);

        $this->send();

        $mail = $this->sentMail();
        $this->assertCount(1, $mail);
        $this->assertSame('Your week in fares — cheapest AMS→OPO €44', $mail[0]->getSubject());

        $body = (string) $mail[0]->getHtmlBody();
        $this->assertStringContainsString('Your watchlist', $body);
        $this->assertStringContainsString('€44', $body);
        $this->assertStringContainsString('€60', $body);

        $this->assertNotNull(Alert::query()->where('type', AlertType::WeeklyDigest)->sole()->delivered_at);
    }

    #[Test]
    public function the_command_queues_one_digest_per_account(): void
    {
        Queue::fake();

        $this->runCommand('orbit:digest')->assertSuccessful();

        Queue::assertPushed(
            SendWeeklyDigest::class,
            fn (SendWeeklyDigest $job): bool => $job->userId === $this->owner->id,
        );
    }
}
