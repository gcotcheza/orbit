<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Alert;
use DateTimeInterface;
use App\Models\DealRule;
use Carbon\CarbonImmutable;
use App\Jobs\EvaluateAlerts;
use App\Models\UserSettings;
use App\Domain\Alerts\AlertType;
use Tests\Concerns\RunsCommands;
use Tests\Concerns\BuildsRuleData;
use Tests\Concerns\BuildsAlertData;
use Tests\Concerns\BuildsRouteData;
use Illuminate\Support\Facades\Date;
use App\Notifications\RouteDealAlert;
use App\Notifications\RuleMatchAlert;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The 06:55 run: what is worth saying, and what is worth saying it about
 * (docs/BUSINESS-LOGIC.md §10, docs/BUSINESS-LOGIC.md §36).
 */
final class AlertPipelineTest extends TestCase
{
    use BuildsAlertData, BuildsRouteData, BuildsRuleData, RefreshDatabase, RunsCommands;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-15 06:55:00');

        $this->owner = User::factory()->create();
    }

    /**
     * Same reasoning as RuleSweepTest: `dispatchSync()` under `Queue::fake()`
     * records a job it never runs (docs/BUSINESS-LOGIC.md §36).
     */
    private function evaluate(): void
    {
        $this->app->call([new EvaluateAlerts($this->owner->id), 'handle']);
    }

    private function settings(): UserSettings
    {
        return UserSettings::for($this->owner);
    }

    #[Test]
    public function an_insane_deal_on_a_watched_route_is_alerted_and_written_down(): void
    {
        Notification::fake();

        $route = $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);

        $this->evaluate();

        Notification::assertSentTo($this->owner, RouteDealAlert::class);

        $alert = Alert::query()->sole();

        $this->assertSame($this->owner->id, $alert->user_id);
        $this->assertSame($route->id, $alert->route_id);
        $this->assertNull($alert->deal_rule_id);
        $this->assertSame(AlertType::RouteDeal, $alert->type);
        $this->assertSame(94, $alert->score);
        $this->assertSame(self::INSANE_CENTS, $alert->price_cents);
        $this->assertSame(Alert::CHANNEL_MAIL, $alert->channel);
        $this->assertSame('2026-08-15 06:55:00', $alert->triggered_at->format('Y-m-d H:i:s'));

        /* Nothing has actually gone out under Notification::fake(). */
        $this->assertNull($alert->delivered_at);

        $this->assertSame('AMS→OPO €44 — 53% below usual', $alert->payload['headline']);
        $this->assertSame(9300, $alert->payload['usualCents']);
        $this->assertSame('2026-09-04', $alert->payload['departureDate']);
    }

    #[Test]
    public function the_subject_line_is_the_headline(): void
    {
        Notification::fake();

        $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);

        $this->evaluate();

        Notification::assertSentTo(
            $this->owner,
            RouteDealAlert::class,
            fn (RouteDealAlert $alert): bool => $alert->notice->subject() === '✈ AMS→OPO €44 — 53% below usual',
        );
    }

    /**
     * Relaxed is the default and only fires on the truly insane ones. A 72 is a
     * good price and is exactly what the owner asked NOT to be woken for.
     */
    #[Test]
    public function a_great_deal_is_not_an_insane_one(): void
    {
        Notification::fake();

        $this->watchedRoute($this->owner, 'LIS', self::GREAT_CENTS);

        $this->evaluate();

        Notification::assertNothingSent();
        $this->assertSame(0, Alert::query()->count());
    }

    #[Test]
    public function turning_the_sensitivity_up_fires_on_the_same_route(): void
    {
        Notification::fake();

        $this->watchedRoute($this->owner, 'LIS', self::GREAT_CENTS);
        $this->settings()->update(['sensitivity' => 1]);

        $this->evaluate();

        Notification::assertSentTo($this->owner, RouteDealAlert::class);
        $this->assertSame(72, Alert::query()->sole()->score);
    }

    #[Test]
    public function an_ordinary_price_is_not_news_at_any_sensitivity(): void
    {
        Notification::fake();

        $this->watchedRoute($this->owner, 'BCN', self::ORDINARY_CENTS);
        $this->settings()->update(['sensitivity' => 2]);

        $this->evaluate();

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_paused_watchlist_row_is_not_evaluated(): void
    {
        Notification::fake();

        $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS, active: false);

        $this->evaluate();

        Notification::assertNothingSent();
        $this->assertSame(0, Alert::query()->count());
    }

    /**
     * A route added an hour ago has no price and no opinion. It must not be
     * able to reach a mail template, where "no idea" renders as €0.
     */
    #[Test]
    public function a_route_with_no_prices_yet_is_silent(): void
    {
        Notification::fake();

        $this->watch($this->owner, $this->makeRoute('AMS', 'OPO'));

        $this->evaluate();

        Notification::assertNothingSent();
        $this->assertSame(0, Alert::query()->count());
    }

    /**
     * The day-1 floor exists for exactly this: an unscored fixture would score
     * 100/insane/confident (docs/BUSINESS-LOGIC.md §7, docs/BUSINESS-LOGIC.md §36).
     */
    #[Test]
    public function a_watchlist_filled_in_this_morning_sends_nothing_at_all(): void
    {
        Notification::fake();

        foreach (['OPO', 'LIS', 'FAO'] as $destination) {
            $this->brandNewRoute($this->owner, $destination, self::INSANE_CENTS);
        }

        $this->evaluate();

        Notification::assertNothingSent();

        // No ledger row either — a row here would start a cooldown on a route
        // nobody was told about (docs/BUSINESS-LOGIC.md §10).
        $this->assertSame(0, Alert::query()->count());
    }

    /**
     * The other side of the maturity boundary: renormalised over three
     * components once a trend exists, still insane (docs/BUSINESS-LOGIC.md §7).
     */
    #[Test]
    public function seven_mornings_in_the_same_fare_is_worth_an_alert(): void
    {
        Notification::fake();

        $route = $this->makeRoute('AMS', 'OPO');
        $this->watch($this->owner, $route);
        $this->observe($route, array_fill(0, 7, self::INSANE_CENTS), '2026-08-15');
        $this->summarise($route, ...self::USUAL);
        $this->offer($route, ['2026-09-04' => self::INSANE_CENTS]);

        $this->evaluate();

        Notification::assertSentTo($this->owner, RouteDealAlert::class);

        $alert = Alert::query()->sole();
        $this->assertSame($route->id, $alert->route_id);
        $this->assertSame(83, $alert->score);
    }

    /**
     * A rule fires on a route Orbit has never watched — rules are ungated by
     * maturity or sensitivity, deliberately (docs/BUSINESS-LOGIC.md §10).
     */
    #[Test]
    public function a_rule_still_fires_on_routes_orbit_has_never_watched(): void
    {
        Notification::fake();

        $this->beachRule();

        $this->evaluate();

        Notification::assertSentTimes(RuleMatchAlert::class, 1);
        Notification::assertNotSentTo($this->owner, RouteDealAlert::class);

        $this->assertSame(3, Alert::query()->where('type', AlertType::RuleMatch)->count());
    }

    /**
     * A young watchlist and a mature rule in the same run — gating the wrong
     * layer would take the rule mail with it (docs/BUSINESS-LOGIC.md §10).
     */
    #[Test]
    public function a_young_watchlist_is_silent_while_its_rules_still_speak(): void
    {
        Notification::fake();

        $this->beachRule();
        $this->brandNewRoute($this->owner, 'OPO', self::INSANE_CENTS);

        $this->evaluate();

        Notification::assertSentTimes(RuleMatchAlert::class, 1);
        Notification::assertNotSentTo($this->owner, RouteDealAlert::class);

        $this->assertSame(0, Alert::query()->where('type', AlertType::RouteDeal)->count());
    }

    #[Test]
    public function a_route_alerted_this_morning_is_not_alerted_again(): void
    {
        Notification::fake();

        $route = $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);
        $this->alerted($this->owner, AlertType::RouteDeal, $route, null, self::INSANE_CENTS, '2026-08-14 18:55:00');

        $this->evaluate();

        Notification::assertNothingSent();
        $this->assertSame(1, Alert::query()->count());
    }

    #[Test]
    public function exactly_a_day_later_it_is_alerted_again(): void
    {
        Notification::fake();

        $route = $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);
        $this->alerted($this->owner, AlertType::RouteDeal, $route, null, self::INSANE_CENTS, '2026-08-14 06:55:00');

        $this->evaluate();

        Notification::assertSentTo($this->owner, RouteDealAlert::class);
        $this->assertSame(2, Alert::query()->count());
    }

    /**
     * €44 yesterday and €41 today is the morning somebody actually books, and
     * the cooldown must not be what stops them hearing about it.
     */
    #[Test]
    public function a_further_five_percent_drop_punches_through_the_cooldown(): void
    {
        Notification::fake();

        $route = $this->watchedRoute($this->owner, 'OPO', 4100);
        $this->alerted($this->owner, AlertType::RouteDeal, $route, null, 4400, '2026-08-14 18:55:00');

        $this->evaluate();

        Notification::assertSentTo($this->owner, RouteDealAlert::class);

        $latest = Alert::query()->latest('id')->firstOrFail();
        $this->assertSame(4100, $latest->price_cents);
        $this->assertSame(2, Alert::query()->count());
    }

    #[Test]
    public function a_smaller_drop_does_not(): void
    {
        Notification::fake();

        $route = $this->watchedRoute($this->owner, 'OPO', 4300);
        $this->alerted($this->owner, AlertType::RouteDeal, $route, null, 4400, '2026-08-14 18:55:00');

        $this->evaluate();

        Notification::assertNothingSent();
        $this->assertSame(1, Alert::query()->count());
    }

    /**
     * The cooldown belongs to the pair (route, kind). A different route on the
     * same watchlist is a different piece of news.
     */
    #[Test]
    public function one_routes_cooldown_does_not_silence_another(): void
    {
        Notification::fake();

        $quiet = $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);
        $this->watchedRoute($this->owner, 'FAO', self::INSANE_CENTS);
        $this->alerted($this->owner, AlertType::RouteDeal, $quiet, null, self::INSANE_CENTS, '2026-08-14 18:55:00');

        $this->evaluate();

        Notification::assertSentTimes(RouteDealAlert::class, 1);
        $this->assertSame(2, Alert::query()->count());
    }

    #[Test]
    public function nothing_is_delayed_outside_the_quiet_window(): void
    {
        Notification::fake();

        $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);

        $this->evaluate();

        Notification::assertSentTo(
            $this->owner,
            RouteDealAlert::class,
            fn (RouteDealAlert $alert): bool => $alert->delay === null,
        );
    }

    /**
     * 21:00 UTC is 23:00 in Amsterdam — inside 22:00–08:00 — so the mail waits
     * until the window ends, which is TOMORROW's eight o'clock.
     */
    #[Test]
    public function an_evening_deal_is_held_until_the_morning(): void
    {
        Notification::fake();
        Date::setTestNow('2026-08-15 21:00:00');

        $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);

        $this->evaluate();

        Notification::assertSentTo(
            $this->owner,
            RouteDealAlert::class,
            fn (RouteDealAlert $alert): bool => $this->deliversAt($alert->delay, '2026-08-16T06:00:00+00:00'),
        );

        /* The DECISION is now; only the delivery moves. */
        $alert = Alert::query()->sole();
        $this->assertSame('2026-08-15 21:00:00', $alert->triggered_at->format('Y-m-d H:i:s'));
        $this->assertNull($alert->delivered_at);
    }

    /**
     * A naive `>= start AND < end` gets this wrong across midnight
     * (docs/BUSINESS-LOGIC.md §10).
     */
    #[Test]
    public function a_deal_found_in_the_small_hours_is_held_until_the_same_morning(): void
    {
        Notification::fake();
        Date::setTestNow('2026-08-16 01:00:00');

        $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);

        $this->evaluate();

        Notification::assertSentTo(
            $this->owner,
            RouteDealAlert::class,
            fn (RouteDealAlert $alert): bool => $this->deliversAt($alert->delay, '2026-08-16T06:00:00+00:00'),
        );
    }

    #[Test]
    public function quiet_hours_switched_off_send_at_once(): void
    {
        Notification::fake();
        Date::setTestNow('2026-08-15 21:00:00');

        $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);
        $this->settings()->update(['quiet_hours' => false]);

        $this->evaluate();

        Notification::assertSentTo(
            $this->owner,
            RouteDealAlert::class,
            fn (RouteDealAlert $alert): bool => $alert->delay === null,
        );
    }

    /**
     * The decision is recorded even though nothing is sent — `delivered_at`
     * stays null (docs/BUSINESS-LOGIC.md §10).
     */
    #[Test]
    public function mail_switched_off_records_the_decision_and_sends_nothing(): void
    {
        Notification::fake();

        $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);
        $this->settings()->update(['email_alerts' => false]);

        $this->evaluate();

        Notification::assertNothingSent();

        $alert = Alert::query()->sole();
        $this->assertNull($alert->delivered_at);
    }

    /**
     * Whole pipeline, socket only missing: queued, rendered, and
     * `NotificationSent` stamps the ledger (docs/BUSINESS-LOGIC.md §10).
     */
    #[Test]
    public function delivery_is_stamped_when_the_mail_actually_goes_out(): void
    {
        $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);

        $this->evaluate();

        $alert = Alert::query()->sole();
        $this->assertNotNull($alert->delivered_at);
        $this->assertSame('2026-08-15 06:55:00', $alert->delivered_at->format('Y-m-d H:i:s'));

        $mail = $this->sentMail();
        $this->assertCount(1, $mail);
        $this->assertSame('✈ AMS→OPO €44 — 53% below usual', $mail[0]->getSubject());

        $body = (string) $mail[0]->getHtmlBody();
        $this->assertStringContainsString('AMS→OPO', $body);
        $this->assertStringContainsString('53% below', $body);
        // Mail carries the primary hand-off alone, no second link to pick
        // between (docs/BUSINESS-LOGIC.md §12).
        $this->assertStringContainsString('aviasales', $body);
    }

    /**
     * Three beaches, priced cheapest-first, and a rule that wants them.
     *
     * @param  list<int>  $prices  one per destination, in FAO / AGP / PMI order
     */
    private function beachRule(array $prices = [3900, 4500, 5200]): DealRule
    {
        $this->makeOrigins();

        foreach (['FAO', 'AGP', 'PMI'] as $index => $iata) {
            $this->makeDestination($iata, ['beach']);

            if (isset($prices[$index])) {
                $this->makeRouteWithFares('AMS', $iata, ['2026-09-04' => $prices[$index]]);
            }
        }

        return $this->makeRule($this->owner, 'a beach somewhere under €80', [
            'origins'       => ['AMS'],
            'vibes'         => ['beach'],
            'maxPriceCents' => 8000,
        ]);
    }

    /**
     * ONE MAIL, THREE MATCHES, THREE LEDGER ROWS. Three mails would be the
     * fastest way to teach somebody to filter this app into a folder.
     */
    #[Test]
    public function a_rule_groups_its_new_matches_into_one_mail(): void
    {
        Notification::fake();

        $this->beachRule();

        $this->evaluate();

        Notification::assertSentTimes(RuleMatchAlert::class, 1);
        Notification::assertSentTo(
            $this->owner,
            RuleMatchAlert::class,
            fn (RuleMatchAlert $alert): bool => count($alert->notice->deals) === 3
                && $alert->notice->cheapest->priceCents === 3900
                && $alert->notice->chips === ['AMS', '€80', '🏖 Beach'],
        );

        $this->assertSame(3, Alert::query()->where('type', AlertType::RuleMatch)->count());

        $cheapest = Alert::query()->orderBy('price_cents')->first();
        $this->assertNotNull($cheapest);
        $this->assertSame('a beach somewhere under €80', $cheapest->payload['rule']['text']);
    }

    #[Test]
    public function a_match_already_alerted_is_left_out_of_the_next_mail(): void
    {
        Notification::fake();

        $rule = $this->beachRule();

        $this->alerted(
            $this->owner,
            AlertType::RuleMatch,
            $this->existingRoute('AMS-FAO'),
            $rule,
            3900,
            '2026-08-14 18:55:00',
        );

        $this->evaluate();

        Notification::assertSentTo(
            $this->owner,
            RuleMatchAlert::class,
            fn (RuleMatchAlert $alert): bool => count($alert->notice->deals) === 2
                && $alert->notice->cheapest->priceCents === 4500,
        );
    }

    #[Test]
    public function a_rule_whose_matches_are_all_cooling_down_sends_nothing(): void
    {
        Notification::fake();

        $rule = $this->beachRule([3900]);

        $this->alerted(
            $this->owner,
            AlertType::RuleMatch,
            $this->existingRoute('AMS-FAO'),
            $rule,
            3900,
            '2026-08-14 18:55:00',
        );

        $this->evaluate();

        Notification::assertNothingSent();
        $this->assertSame(1, Alert::query()->count());
    }

    #[Test]
    public function a_paused_rule_is_not_evaluated(): void
    {
        Notification::fake();

        $this->beachRule();
        $this->owner->dealRules()->update(['active' => false]);

        $this->evaluate();

        Notification::assertNothingSent();
    }

    /**
     * A rule's cooldown is its own. Two rules that both match FAO are two
     * questions the owner asked, and one answering does not answer the other.
     */
    #[Test]
    public function another_rules_cooldown_does_not_silence_this_one(): void
    {
        Notification::fake();

        $first = $this->beachRule([3900]);

        $second = $this->makeRule($this->owner, 'anywhere warm', ['vibes' => ['beach'], 'maxPriceCents' => 9000]);

        /* The FIRST rule has already said this. The second one never has. */
        $this->alerted(
            $this->owner,
            AlertType::RuleMatch,
            $this->existingRoute('AMS-FAO'),
            $first,
            3900,
            '2026-08-14 18:55:00',
        );

        $this->evaluate();

        Notification::assertSentTimes(RuleMatchAlert::class, 1);
        Notification::assertSentTo(
            $this->owner,
            RuleMatchAlert::class,
            fn (RuleMatchAlert $alert): bool => $alert->notice->ruleId === $second->id,
        );
    }

    #[Test]
    public function the_command_queues_one_evaluation_per_account(): void
    {
        Queue::fake();

        $this->runCommand('orbit:alerts')->assertSuccessful();

        Queue::assertPushed(
            EvaluateAlerts::class,
            fn (EvaluateAlerts $job): bool => $job->userId === $this->owner->id,
        );
    }

    /**
     * The queue delay, as an instant in UTC — which is what a mail held by
     * quiet hours until "08:00 Amsterdam" actually is.
     */
    private function deliversAt(mixed $delay, string $expected): bool
    {
        return $delay instanceof DateTimeInterface
            && CarbonImmutable::instance($delay)->setTimezone('UTC')->toIso8601String() === $expected;
    }
}
