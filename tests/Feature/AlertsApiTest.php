<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Alert;
use App\Domain\Alerts\AlertType;
use Tests\Concerns\BuildsRuleData;
use Tests\Concerns\BuildsAlertData;
use Tests\Concerns\BuildsRouteData;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `GET /api/alerts` — what Orbit has actually said.
 *
 * THE SHAPE IS ASSERTED AGAINST THE FROZEN PAYLOAD and not against the route it
 * points at, which is the property this endpoint exists to have: a row written
 * in March quotes March's price, and a rule that has since been deleted still
 * explains the mails it caused.
 */
final class AlertsApiTest extends TestCase
{
    use BuildsAlertData, BuildsRouteData, BuildsRuleData, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-15 06:55:00');

        $this->owner = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_guest_is_refused_with_json(): void
    {
        $this->getJson('/api/alerts')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    #[Test]
    public function it_answers_with_the_ledger_newest_first(): void
    {
        $route = $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);

        $this->alerted($this->owner, AlertType::RouteDeal, $route, null, 4400, '2026-08-13 06:55:00');
        $this->alerted($this->owner, AlertType::RouteDeal, $route, null, 4100, '2026-08-15 06:55:00');

        $this->actingAs($this->owner)->getJson('/api/alerts')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.price', 41)
            ->assertJsonPath('data.0.type', 'route_deal')
            ->assertJsonPath('data.0.route', 'AMS-OPO')
            ->assertJsonPath('data.0.rule', null)
            ->assertJsonPath('data.0.score', 94)
            ->assertJsonPath('data.0.summary', 'AMS→OPO €41')
            ->assertJsonPath('data.0.triggeredAt', '2026-08-15T06:55:00+00:00')
            ->assertJsonPath('data.0.deliveredAt', '2026-08-15T06:55:00+00:00')
            ->assertJsonPath('data.1.price', 44)
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('meta.limit', 20)
            ->assertJsonPath('meta.total', 2);
    }

    /**
     * A mail held by quiet hours, or one an account has switched off, is a row
     * with a decision and no delivery. Both are things worth being able to see.
     */
    #[Test]
    public function an_undelivered_alert_says_so(): void
    {
        $route = $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);

        $this->alerted($this->owner, AlertType::RouteDeal, $route, null, 4400, '2026-08-15 06:55:00', delivered: false);

        $this->actingAs($this->owner)->getJson('/api/alerts')
            ->assertOk()
            ->assertJsonPath('data.0.deliveredAt', null);
    }

    /**
     * The rule travels in the payload, so deleting the rule does not erase the
     * reason a mail was sent — docs/API.md promises the same about the routes a
     * rule surfaced.
     */
    #[Test]
    public function a_rule_match_carries_the_rule_that_found_it_even_after_it_is_deleted(): void
    {
        $this->makeOrigins();
        $this->makeDestination('FAO', ['beach']);
        $this->makeRouteWithFares('AMS', 'FAO', ['2026-09-04' => 3900]);

        $rule = $this->makeRule($this->owner, 'a beach somewhere under €80', [
            'origins'       => ['AMS'],
            'vibes'         => ['beach'],
            'maxPriceCents' => 8000,
        ]);

        $alert = $this->alerted(
            $this->owner,
            AlertType::RuleMatch,
            $this->existingRoute('AMS-FAO'),
            $rule,
            3900,
            '2026-08-15 06:55:00',
        );

        $alert->update(['payload' => $alert->payload + [
            'rule' => ['id' => $rule->id, 'text' => $rule->raw_text, 'chips' => ['AMS', '€80', '🏖 Beach']],
        ]]);

        $rule->delete();

        $this->actingAs($this->owner)->getJson('/api/alerts')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'rule_match')
            ->assertJsonPath('data.0.rule.text', 'a beach somewhere under €80')
            ->assertJsonPath('data.0.rule.chips', ['AMS', '€80', '🏖 Beach'])
            ->assertJsonPath('data.0.score', null);

        /* The row survives the rule; only the foreign key is let go. */
        $this->assertNull(Alert::query()->sole()->deal_rule_id);
    }

    #[Test]
    public function the_digest_has_no_route_and_no_price(): void
    {
        Alert::query()->create([
            'user_id'      => $this->owner->id,
            'type'         => AlertType::WeeklyDigest,
            'payload'      => ['routes' => 2, 'rules' => 1, 'week' => 3, 'headline' => 'Your week in fares'],
            'channel'      => Alert::CHANNEL_MAIL,
            'triggered_at' => Date::now(),
        ]);

        $this->actingAs($this->owner)->getJson('/api/alerts')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'weekly_digest')
            ->assertJsonPath('data.0.route', null)
            ->assertJsonPath('data.0.price', null)
            ->assertJsonPath('data.0.summary', 'Your week in fares');
    }

    #[Test]
    public function it_takes_a_limit(): void
    {
        $route = $this->watchedRoute($this->owner, 'OPO', self::INSANE_CENTS);

        foreach (['2026-08-13 06:55:00', '2026-08-14 06:55:00', '2026-08-15 06:55:00'] as $when) {
            $this->alerted($this->owner, AlertType::RouteDeal, $route, null, 4400, $when);
        }

        $this->actingAs($this->owner)->getJson('/api/alerts?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('meta.limit', 2)
            /* `total` is the whole ledger, so a client can tell there is more. */
            ->assertJsonPath('meta.total', 3);
    }

    #[Test]
    public function a_limit_over_fifty_is_refused(): void
    {
        $this->actingAs($this->owner)->getJson('/api/alerts?limit=51')
            ->assertUnprocessable()
            ->assertJsonPath('errors.limit.0', 'Fifty rows is the most this endpoint returns at once.');
    }

    #[Test]
    public function a_limit_that_is_not_a_number_is_refused(): void
    {
        $this->actingAs($this->owner)->getJson('/api/alerts?limit=lots')
            ->assertUnprocessable()
            ->assertJsonPath('errors.limit.0', 'The limit is a number of rows.');
    }

    #[Test]
    public function one_account_cannot_see_anothers_alerts(): void
    {
        $stranger = User::factory()->create();
        $route = $this->watchedRoute($stranger, 'OPO', self::INSANE_CENTS);

        $this->alerted($stranger, AlertType::RouteDeal, $route, null, 4400, '2026-08-15 06:55:00');

        $this->actingAs($this->owner)->getJson('/api/alerts')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }
}
