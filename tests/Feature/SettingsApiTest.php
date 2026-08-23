<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use RuntimeException;
use App\Models\UserSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `GET` and `PUT /api/settings` — the alerts screen and the table the alert
 * engine reads. Defaults are hardcoded facts, not read back from config.
 */
final class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    /** SerpAPI's free quota probe (docs/BUSINESS-LOGIC.md §31). */
    private const ACCOUNT = 'https://serpapi.com/account.json*';

    /**
     * A complete, valid settings object — the shape every PUT has to send.
     *
     * @var array<string, bool|string|int>
     */
    private const VALID = [
        'emailAlerts'  => true,
        'pushAlerts'   => true,
        'weeklyDigest' => false,
        'quietHours'   => false,
        'quietStart'   => '23:30',
        'quietEnd'     => '06:45',
        'sensitivity'  => 2,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    #[Test]
    public function a_guest_is_refused_with_json(): void
    {
        $this->getJson('/api/settings')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->putJson('/api/settings', self::VALID)->assertUnauthorized();

        $this->assertSame(0, UserSettings::query()->count());
    }

    #[Test]
    public function the_first_read_creates_the_row_with_the_documented_defaults(): void
    {
        $this->assertSame(0, UserSettings::query()->count());

        $this->actingAs($this->owner)->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.emailAlerts', true)
            ->assertJsonPath('data.pushAlerts', false)
            ->assertJsonPath('data.weeklyDigest', true)
            ->assertJsonPath('data.quietHours', true)
            ->assertJsonPath('data.quietStart', '22:00')
            ->assertJsonPath('data.quietEnd', '08:00')
            ->assertJsonPath('data.sensitivity', 0);

        $this->assertSame(1, UserSettings::query()->count());
    }

    #[Test]
    public function reading_twice_does_not_create_a_second_row(): void
    {
        $this->actingAs($this->owner)->getJson('/api/settings')->assertOk();
        $this->actingAs($this->owner)->getJson('/api/settings')->assertOk();

        $this->assertSame(1, UserSettings::query()->where('user_id', $this->owner->id)->count());
    }

    #[Test]
    public function every_account_gets_its_own_row(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($this->owner)->putJson('/api/settings', self::VALID)->assertOk();
        $this->actingAs($stranger)->getJson('/api/settings')
            ->assertOk()
            // The stranger's defaults, untouched by the owner's PUT.
            ->assertJsonPath('data.sensitivity', 0)
            ->assertJsonPath('data.weeklyDigest', true);

        $this->assertSame(2, UserSettings::query()->count());
    }

    /**
     * `minimumScore` comes from `score.tiers`, the same source the route
     * `tier` uses (docs/BUSINESS-LOGIC.md §10).
     */
    #[Test]
    public function the_meta_describes_the_three_sensitivity_levels(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/settings');

        $response->assertOk()
            ->assertJsonCount(3, 'meta.sensitivities')
            ->assertJsonStructure(['meta' => ['sensitivities' => [['level', 'name', 'minimumScore', 'blurb']]]])
            ->assertJsonPath('meta.sensitivities.0.level', 0)
            ->assertJsonPath('meta.sensitivities.0.name', 'Relaxed')
            ->assertJsonPath('meta.sensitivities.0.minimumScore', 80)
            ->assertJsonPath('meta.sensitivities.1.name', 'Balanced')
            ->assertJsonPath('meta.sensitivities.1.minimumScore', 65)
            ->assertJsonPath('meta.sensitivities.2.name', 'Eager')
            ->assertJsonPath('meta.sensitivities.2.minimumScore', 50);

        // The blurb quotes the threshold rather than restating it in prose.
        $this->assertStringContainsString('80', (string) $response->json('meta.sensitivities.0.blurb'));
        $this->assertStringContainsString('50', (string) $response->json('meta.sensitivities.2.blurb'));
    }

    #[Test]
    public function a_put_round_trips_through_the_database(): void
    {
        $this->actingAs($this->owner)->putJson('/api/settings', self::VALID)
            ->assertOk()
            ->assertJsonPath('data.emailAlerts', true)
            ->assertJsonPath('data.pushAlerts', true)
            ->assertJsonPath('data.weeklyDigest', false)
            ->assertJsonPath('data.quietHours', false)
            ->assertJsonPath('data.quietStart', '23:30')
            ->assertJsonPath('data.quietEnd', '06:45')
            ->assertJsonPath('data.sensitivity', 2);

        $this->actingAs($this->owner)->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.pushAlerts', true)
            ->assertJsonPath('data.weeklyDigest', false)
            // Stored even though quiet hours are off: switching them back on
            // has to restore the window somebody chose.
            ->assertJsonPath('data.quietStart', '23:30')
            ->assertJsonPath('data.quietEnd', '06:45')
            ->assertJsonPath('data.sensitivity', 2);

        $this->assertSame(1, UserSettings::query()->count());
    }

    #[Test]
    public function a_put_before_anything_was_ever_read_still_works(): void
    {
        $this->assertSame(0, UserSettings::query()->count());

        $this->actingAs($this->owner)->putJson('/api/settings', self::VALID)->assertOk();

        $this->assertSame(1, UserSettings::query()->count());
    }

    #[Test]
    public function every_field_is_required(): void
    {
        $this->actingAs($this->owner)->putJson('/api/settings', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'emailAlerts', 'pushAlerts', 'weeklyDigest',
                'quietHours', 'quietStart', 'quietEnd', 'sensitivity',
            ]);

        $this->assertSame(0, UserSettings::query()->count());
    }

    #[Test]
    public function the_switches_have_to_be_booleans(): void
    {
        $this->actingAs($this->owner)
            ->putJson('/api/settings', [...self::VALID, 'emailAlerts' => 'yes'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('emailAlerts');
    }

    #[Test]
    public function the_quiet_hours_have_to_be_real_times(): void
    {
        foreach (['24:00', '22:60', '9:00', 'bedtime', '22:00:00'] as $bad) {
            $this->actingAs($this->owner)
                ->putJson('/api/settings', [...self::VALID, 'quietStart' => $bad])
                ->assertStatus(422)
                ->assertJsonPath('errors.quietStart.0', 'Quiet hours start at a time like 22:00.');
        }

        $this->actingAs($this->owner)
            ->putJson('/api/settings', [...self::VALID, 'quietEnd' => '25:00'])
            ->assertStatus(422)
            ->assertJsonPath('errors.quietEnd.0', 'Quiet hours end at a time like 08:00.');
    }

    #[Test]
    public function midnight_is_a_valid_quiet_hour(): void
    {
        $this->actingAs($this->owner)
            ->putJson('/api/settings', [...self::VALID, 'quietStart' => '00:00', 'quietEnd' => '23:59'])
            ->assertOk()
            ->assertJsonPath('data.quietStart', '00:00')
            ->assertJsonPath('data.quietEnd', '23:59');
    }

    #[Test]
    public function the_sensitivity_has_to_be_a_level_that_exists(): void
    {
        foreach ([-1, 3, 99] as $bad) {
            $this->actingAs($this->owner)
                ->putJson('/api/settings', [...self::VALID, 'sensitivity' => $bad])
                ->assertStatus(422)
                ->assertJsonPath('errors.sensitivity.0', 'Pick one of the three sensitivity levels.');
        }

        $this->actingAs($this->owner)
            ->putJson('/api/settings', [...self::VALID, 'sensitivity' => 'loud'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sensitivity');
    }

    #[Test]
    public function every_level_is_accepted(): void
    {
        foreach ([0, 1, 2] as $level) {
            $this->actingAs($this->owner)
                ->putJson('/api/settings', [...self::VALID, 'sensitivity' => $level])
                ->assertOk()
                ->assertJsonPath('data.sensitivity', $level);
        }
    }

    /**
     * What PR11 will actually ask this row: at what score is this account
     * worth waking up?
     */
    #[Test]
    public function the_stored_level_resolves_to_the_configured_tier_score(): void
    {
        $settings = UserSettings::for($this->owner);

        $this->assertSame(80, $settings->minimumScore());

        $settings->update(['sensitivity' => 1]);
        $this->assertSame(65, $settings->minimumScore());

        $settings->update(['sensitivity' => 2]);
        $this->assertSame(50, $settings->minimumScore());
    }

    /* --- meta.googleChecks: the SerpAPI month, on the "This app" card (§31) ------------- */

    private function withKey(): void
    {
        config(['orbit.serpapi.key' => 'test-key']);
    }

    #[Test]
    public function the_settings_carry_the_google_searches_the_month_has_left(): void
    {
        $this->withKey();

        Http::fake([self::ACCOUNT => Http::response(['total_searches_left' => 249], 200)]);

        $this->actingAs($this->owner)
            ->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('meta.googleChecks.left', 249)
            ->assertJsonPath('meta.googleChecks.reserve', 50)
            ->assertJsonPath('meta.googleChecks.checkedAt', fn (mixed $at): bool => is_string($at));
    }

    /**
     * The default box. `checkedAt` is null BECAUSE NOBODY ASKED — that is what
     * tells the screen to say "Not configured" rather than "Unknown right now".
     */
    #[Test]
    public function without_a_key_there_is_no_count_and_no_probe(): void
    {
        /* No Http::fake: preventStrayRequests turns a probe into a failed assertion. */
        $this->actingAs($this->owner)
            ->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('meta.googleChecks.left', null)
            ->assertJsonPath('meta.googleChecks.checkedAt', null)
            ->assertJsonPath('meta.googleChecks.reserve', 50);
    }

    /** A settings screen is not allowed to break because SerpAPI is having a day. */
    #[Test]
    public function a_failed_probe_leaves_the_count_unknown_and_the_settings_answering(): void
    {
        $this->withKey();

        Http::fake([self::ACCOUNT => Http::response('gateway timeout', 504)]);

        $this->actingAs($this->owner)
            ->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.emailAlerts', true)
            ->assertJsonPath('meta.googleChecks.left', null)
            ->assertJsonPath('meta.googleChecks.checkedAt', fn (mixed $at): bool => is_string($at));
    }

    #[Test]
    public function the_count_is_cached_so_a_second_read_does_not_probe_again(): void
    {
        $this->withKey();

        Http::fake([self::ACCOUNT => Http::response(['total_searches_left' => 249], 200)]);

        $this->actingAs($this->owner)->getJson('/api/settings')->assertOk();
        $this->actingAs($this->owner)->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('meta.googleChecks.left', 249);

        Http::assertSentCount(1);
    }

    /** The unknown answer is cached too, or a dead SerpAPI stalls every settings load. */
    #[Test]
    public function a_failed_probe_is_cached_as_well(): void
    {
        $this->withKey();

        Http::fake([self::ACCOUNT => Http::response('gateway timeout', 504)]);

        $this->actingAs($this->owner)->getJson('/api/settings')->assertOk();
        $this->actingAs($this->owner)->getJson('/api/settings')->assertOk();

        Http::assertSentCount(1);
    }

    /**
     * ⚠ A BOX WITH A KEY MUST NOT READ "Not configured". An unreachable cache is not the
     * same fact as an unconfigured one, and `checkedAt` is the only thing that says so.
     */
    #[Test]
    public function a_cache_that_cannot_be_reached_leaves_the_row_unknown_rather_than_unconfigured(): void
    {
        $this->withKey();

        Cache::partialMock()->shouldReceive('remember')->andThrow(new RuntimeException('redis is gone'));

        $this->actingAs($this->owner)
            ->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.emailAlerts', true)
            ->assertJsonPath('meta.googleChecks.left', null)
            ->assertJsonPath('meta.googleChecks.reserve', 50)
            /* A stamp, not null: null is reserved for "no key", which is not what happened. */
            ->assertJsonPath('meta.googleChecks.checkedAt', fn (mixed $at): bool => is_string($at));
    }

    #[Test]
    public function a_put_answers_the_count_too_since_both_actions_send_the_same_body(): void
    {
        $this->withKey();

        Http::fake([self::ACCOUNT => Http::response(['total_searches_left' => 100], 200)]);

        $this->actingAs($this->owner)
            ->putJson('/api/settings', self::VALID)
            ->assertOk()
            ->assertJsonPath('meta.googleChecks.left', 100);
    }
}
