<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\Discovery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /api/discoveries` — the contract in docs/API.md.
 */
final class DiscoveryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-16 09:00:00');

        $this->user = User::factory()->create();

        Airport::factory()->create(['iata' => 'DUS', 'city' => 'Düsseldorf', 'country' => 'Germany', 'lat' => 51.2895, 'lng' => 6.76678]);
        Airport::factory()->create(['iata' => 'AGP', 'city' => 'Málaga', 'country' => 'Spain', 'lat' => 36.6749, 'lng' => -4.49911]);
        Airport::factory()->create(['iata' => 'RAK', 'city' => 'Marrakesh', 'country' => 'Morocco', 'lat' => 31.6069, 'lng' => -8.0363]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function discovery(array $overrides = []): Discovery
    {
        $ids = Airport::query()->pluck('id', 'iata');

        $code = (string) ($overrides['code'] ?? 'DUS-AGP');
        [$origin, $destination] = explode('-', $code);

        /** @var array<model-property<Discovery>, mixed> $attributes */
        $attributes = $overrides + [
            'origin_airport_id' => $ids[$origin],
            'destination_airport_id' => $ids[$destination],
            'code' => $code,
            'departure_date' => '2026-10-24',
            'price_cents' => 2900,
            'cents_per_km' => 1.565,
            'percentile' => 0.0,
            'savings_cents' => 4900,
            'google_verdict' => null,
            'found_at' => Date::parse('2026-08-15 08:00:00'),
            'discovered_at' => Date::parse('2026-08-16 05:20:00'),
            'expires_at' => Date::parse('2026-08-17 17:20:00'),
        ];

        return Discovery::query()->create($attributes);
    }

    #[Test]
    public function a_guest_is_refused_with_json(): void
    {
        $this->getJson('/api/discoveries')->assertUnauthorized();
    }

    #[Test]
    public function it_publishes_the_documented_shape(): void
    {
        $this->discovery();

        $response = $this->actingAs($this->user)->getJson('/api/discoveries');

        $response->assertOk()->assertJson([
            'data' => [[
                'code' => 'DUS-AGP',
                'origin' => ['iata' => 'DUS'],
                'destination' => ['iata' => 'AGP', 'city' => 'Málaga', 'country' => 'Spain'],
                'price' => 29,
                'departureDate' => '2026-10-24',
                'percentile' => 0,
                'savings' => 49,
                'verdict' => ['verified' => false, 'label' => 'Unverified'],
            ]],
            'meta' => ['count' => 1],
        ]);

        /* Millieuros per kilometre: 1.565 cents/km, which is 15.649999… in
           binary and therefore rounds down. One decimal is all the card needs. */
        $this->assertSame(15.6, $response->json('data.0.milliEurosPerKm'));

        /* `found_at` is a MOMENT and travels with an offset, unlike the day. */
        $this->assertStringContainsString('2026-08-15T', (string) $response->json('data.0.foundAt'));
        $this->assertNotNull($response->json('meta.discoveredAt'));
    }

    #[Test]
    public function it_publishes_googles_own_price_even_when_it_disagrees(): void
    {
        $this->discovery(['google_verdict' => [
            'level' => 'typical', 'lowest' => 7000,
            'typical_low' => 5500, 'typical_high' => 17500, 'confirmed' => false,
        ]]);

        $this->actingAs($this->user)->getJson('/api/discoveries')
            ->assertOk()
            ->assertJsonPath('data.0.verdict.verified', false)
            ->assertJsonPath('data.0.verdict.level', 'typical')
            /* We say €29, Google says €70 — the reader is entitled to know. */
            ->assertJsonPath('data.0.verdict.googleLowest', 70)
            ->assertJsonPath('data.0.verdict.typicalLow', 55);
    }

    #[Test]
    public function an_earned_badge_says_so(): void
    {
        $this->discovery(['google_verdict' => [
            'level' => 'low', 'lowest' => 4800,
            'typical_low' => 5500, 'typical_high' => 17500, 'confirmed' => true,
        ]]);

        $this->actingAs($this->user)->getJson('/api/discoveries')
            ->assertOk()
            ->assertJsonPath('data.0.verdict.verified', true)
            ->assertJsonPath('data.0.verdict.label', 'Verified low by Google');
    }

    /**
     * A verification stage that could not fetch a window says nothing rather
     * than something it cannot support.
     */
    #[Test]
    public function an_unmeasured_window_publishes_nulls_and_not_zeroes(): void
    {
        $this->discovery(['percentile' => null, 'savings_cents' => null]);

        $this->actingAs($this->user)->getJson('/api/discoveries')
            ->assertOk()
            ->assertJsonPath('data.0.percentile', null)
            ->assertJsonPath('data.0.savings', null);
    }

    #[Test]
    public function it_orders_by_what_a_kilometre_costs(): void
    {
        $this->discovery(['code' => 'DUS-AGP', 'cents_per_km' => 1.565, 'price_cents' => 2900]);
        $this->discovery(['code' => 'DUS-RAK', 'cents_per_km' => 1.079, 'price_cents' => 2700]);

        $codes = $this->actingAs($this->user)->getJson('/api/discoveries')->json('data.*.code');

        /* Marrakesh is dearer in euros and cheaper per kilometre, and leads. */
        $this->assertSame(['DUS-RAK', 'DUS-AGP'], $codes);
    }

    #[Test]
    public function expired_rows_and_departed_flights_are_not_served(): void
    {
        $this->discovery(['code' => 'DUS-AGP']);
        $this->discovery(['code' => 'DUS-RAK', 'expires_at' => Date::parse('2026-08-16 08:00:00')]);

        $codes = $this->actingAs($this->user)->getJson('/api/discoveries')->json('data.*.code');
        $this->assertSame(['DUS-AGP'], $codes);

        /* And a flight that has already left. */
        Discovery::query()->where('code', 'DUS-AGP')->update(['departure_date' => '2026-08-15']);

        $this->actingAs($this->user)->getJson('/api/discoveries')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * A box with no sweep provider, or a week where nothing was remarkable.
     * Every threshold is a floor rather than a quota precisely so this can
     * happen.
     */
    #[Test]
    public function an_empty_set_is_a_real_and_common_answer(): void
    {
        $this->actingAs($this->user)->getJson('/api/discoveries')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.count', 0)
            ->assertJsonPath('meta.discoveredAt', null);
    }

    /**
     * THE HAND-OFF. A discovery's `code` is the one the existing lookup flow
     * takes — nothing about this feature reinvents booking or watching.
     */
    #[Test]
    public function the_code_it_publishes_opens_the_ordinary_lookup_flow(): void
    {
        $this->discovery();

        $code = (string) $this->actingAs($this->user)->getJson('/api/discoveries')->json('data.0.code');

        $this->assertMatchesRegularExpression('/^[A-Z]{3}-[A-Z]{3}$/', $code);

        /* Orbit has never priced this pair, so the read is a 404 and the LOOKUP
           is what answers — exactly as the search screen's journey does. */
        $this->actingAs($this->user)->getJson("/api/routes/{$code}")->assertNotFound();

        $this->actingAs($this->user)
            ->postJson('/api/routes/lookup', ['origin' => 'DUS', 'destination' => 'AGP'])
            ->assertCreated();
    }
}
