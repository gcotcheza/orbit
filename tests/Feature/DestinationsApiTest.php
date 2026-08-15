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
 * THE ONE RULE THIS ENDPOINT HAS is which airports are in it, and world
 * flights made that rule matter far more than it did. The `airports` table
 * holds 3,270 rows: 184 are the curated destinations this endpoint exists to
 * offer, three are the places the owner leaves FROM, and the other 3,083 came
 * from an OurAirports snapshot nobody has an opinion about. A list that
 * included the origins would put "Amsterdam" in a dropdown whose every entry
 * becomes the far end of a route from Amsterdam; a list that included the
 * snapshot would be a 200 KB payload of places the rule engine can never
 * match.
 *
 * The `destinations` table is what tells all three apart — see the note in
 * database/migrations/..._create_airports_table.php — and this asserts the
 * endpoint keys off it rather than off `is_origin`, which was the same answer
 * when this file was written and has not been since.
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
     * They have no reader here, and 184 airports' worth of them is payload the
     * form would download and drop.
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
     * The list the form actually gets in production, against the files it comes
     * from: a hundred and eighty-four destinations and not the hundred and
     * eighty-seven airports beside them.
     *
     * THE NUMBERS MOVED WITH WORLD FLIGHTS, and both halves of the change are
     * deliberate rather than a bump to make a red test green:
     *
     *   77 -> 184 destinations   the European file's 77, plus the 107 long-haul
     *                            places world_destinations.php adds. Every one
     *                            of them carries vibes and twelve warmth
     *                            ratings, because this is the tier the rule
     *                            engine matches against.
     *   80 -> 187 airports       the same 107, plus the three origins. This
     *                            seeder still writes ONLY curated rows — the
     *                            3,083 from the OurAirports snapshot are
     *                            WorldAirportSeeder's, are not seeded here, and
     *                            must never appear in this endpoint's answer.
     *
     * That last line is the drift this test now guards: `whereHas('destination')`
     * is what keeps 3,086 airports with no opinion attached out of a dropdown
     * that used to be the whole table minus three.
     */
    #[Test]
    public function it_offers_every_seeded_destination_and_only_those(): void
    {
        $this->seed(DestinationSeeder::class);

        $response = $this->actingAs($this->owner)->getJson('/api/destinations')->assertOk();

        $expected = Destination::query()->count();

        $this->assertSame(187, Airport::query()->count());
        $this->assertSame(184, $expected);
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
