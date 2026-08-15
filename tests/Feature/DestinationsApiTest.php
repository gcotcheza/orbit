<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\Destination;
use App\Models\User;
use Database\Seeders\DestinationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /api/destinations` — what the add-route form offers.
 *
 * THE ONE RULE THIS ENDPOINT HAS is which airports are in it. The `airports`
 * table holds eighty rows and three of them are the places the owner leaves
 * FROM; a list that included those would put "Amsterdam" in a dropdown whose
 * every entry becomes the far end of a route from Amsterdam. The
 * `destinations` table is what tells the two apart — see the note in
 * database/migrations/..._create_airports_table.php — and this asserts the
 * endpoint keys off it rather than off `is_origin`, which is the same answer
 * today and the one that stops being true the day a fourth origin is added
 * that people also fly to.
 */
final class DestinationsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    #[Test]
    public function a_guest_is_refused_with_json(): void
    {
        $this->getJson('/api/destinations')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    #[Test]
    public function it_answers_with_the_four_fields_a_suggestion_row_prints(): void
    {
        $this->destination('BIO', 'Bilbao', 'Spain', 'ES');

        $this->actingAs($this->owner)->getJson('/api/destinations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', [
                'iata' => 'BIO',
                'city' => 'Bilbao',
                'country' => 'Spain',
                'countryCode' => 'ES',
            ])
            ->assertJsonPath('meta.count', 1);
    }

    /**
     * Coordinates travel with a WATCHLIST row, because the globe needs them.
     * They have no reader here and eighty airports' worth of them is payload
     * the form would download and drop.
     */
    #[Test]
    public function it_does_not_carry_the_globe_coordinates(): void
    {
        $this->destination('BIO', 'Bilbao', 'Spain', 'ES');

        $response = $this->actingAs($this->owner)->getJson('/api/destinations')->assertOk();

        $this->assertSame(
            ['iata', 'city', 'country', 'countryCode'],
            array_keys($response->json('data.0')),
        );
    }

    #[Test]
    public function an_origin_is_not_somewhere_to_fly_to(): void
    {
        $this->destination('BIO', 'Bilbao', 'Spain', 'ES');

        // The three the owner departs from, which have no `destinations` row.
        Airport::factory()->origin()->create(['iata' => 'AMS', 'city' => 'Amsterdam']);
        Airport::factory()->origin()->create(['iata' => 'EIN', 'city' => 'Eindhoven']);
        Airport::factory()->origin()->create(['iata' => 'DUS', 'city' => 'Düsseldorf']);

        $codes = $this->actingAs($this->owner)->getJson('/api/destinations')
            ->assertOk()
            ->json('data.*.iata');

        $this->assertSame(['BIO'], $codes);
    }

    #[Test]
    public function it_is_alphabetical_by_city(): void
    {
        // Created out of order on purpose: insertion order is what an
        // unordered query would hand back, and it is not this.
        $this->destination('OPO', 'Porto', 'Portugal', 'PT');
        $this->destination('BIO', 'Bilbao', 'Spain', 'ES');
        $this->destination('LIS', 'Lisbon', 'Portugal', 'PT');

        $cities = $this->actingAs($this->owner)->getJson('/api/destinations')
            ->assertOk()
            ->json('data.*.city');

        $this->assertSame(['Bilbao', 'Lisbon', 'Porto'], $cities);
    }

    /**
     * The list the form actually gets in production, against the file it comes
     * from: seventy-seven destinations and not the eighty airports beside them.
     */
    #[Test]
    public function it_offers_every_seeded_destination_and_only_those(): void
    {
        $this->seed(DestinationSeeder::class);

        $response = $this->actingAs($this->owner)->getJson('/api/destinations')->assertOk();

        $expected = Destination::query()->count();

        $this->assertSame(80, Airport::query()->count());
        $this->assertSame(77, $expected);
        $this->assertCount($expected, $response->json('data'));
        $this->assertSame($expected, $response->json('meta.count'));

        // A place the e2e suite types into the box, spelled the way it will be
        // matched: city, code and country all searchable.
        $this->assertContains(
            ['iata' => 'BIO', 'city' => 'Bilbao', 'country' => 'Spain', 'countryCode' => 'ES'],
            $response->json('data'),
        );
    }

    /**
     * Behind a session, so it must not be held by a shared cache — and cheap
     * enough that the browser holding it for an hour is the whole point.
     */
    #[Test]
    public function it_may_be_cached_privately(): void
    {
        $this->destination('BIO', 'Bilbao', 'Spain', 'ES');

        $this->actingAs($this->owner)->getJson('/api/destinations')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=3600, private');
    }

    private function destination(string $iata, string $city, string $country, string $countryCode): Airport
    {
        $airport = Airport::factory()->create([
            'iata' => $iata,
            'city' => $city,
            'country' => $country,
            'country_code' => $countryCode,
        ]);

        Destination::query()->create([
            'airport_id' => $airport->id,
            'vibes' => ['city'],
            'warmth' => array_fill_keys(range(1, 12), 3),
        ]);

        return $airport;
    }
}
