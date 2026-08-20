<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Airport;
use App\Models\Destination;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Database\Seeders\DestinationSeeder;
use Database\Seeders\WorldAirportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `GET /api/airports?q=` — the other half of the add-route typeahead.
 *
 * `GET /api/destinations` sends 184 curated places whole; this searches all 3,270 and sends ten — same four fields,
 * same ranking, one panel (docs/BUSINESS-LOGIC.md §36).
 *
 * The seeded tests at the bottom matter most — everything above proves the SQL on factory rows; those two prove it
 * against the real snapshot (docs/BUSINESS-LOGIC.md §36).
 */
final class AirportSearchTest extends TestCase
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
        $this->getJson('/api/airports?q=lisbon')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    #[Test]
    public function one_character_is_not_a_search(): void
    {
        $this->search('l')
            ->assertStatus(422)
            ->assertJsonPath('errors.q.0', 'Two letters is the shortest thing worth searching for.');

        /* Trimmed first, so whitespace cannot smuggle a one-character query in. */
        $this->search('  l  ')->assertStatus(422);

        $this->actingAs($this->owner)->getJson('/api/airports')
            ->assertStatus(422)
            ->assertJsonPath('errors.q.0', 'Say what to look for.');
    }

    #[Test]
    public function it_answers_with_the_four_fields_a_suggestion_row_prints(): void
    {
        $this->airport('JFK', 'John F. Kennedy', 'New York', 'United States', 'US');

        $response = $this->search('new york')->assertOk();

        $this->assertSame(
            [['iata' => 'JFK', 'city' => 'New York', 'country' => 'United States', 'countryCode' => 'US']],
            $response->json('data'),
        );

        $this->assertSame(1, $response->json('meta.count'));
        $this->assertSame('new york', $response->json('meta.query'));
    }

    #[Test]
    public function it_finds_an_airport_by_city_code_country_or_its_own_name(): void
    {
        $this->airport('BKK', 'Suvarnabhumi', 'Bangkok', 'Thailand', 'TH');

        $this->assertSame(['BKK'], $this->codes($this->search('bangk')));
        $this->assertSame(['BKK'], $this->codes($this->search('bkk')));
        $this->assertSame(['BKK'], $this->codes($this->search('thai')));
        $this->assertSame(['BKK'], $this->codes($this->search('suvarna')));
    }

    #[Test]
    public function it_does_not_care_about_case(): void
    {
        $this->airport('BKK', 'Suvarnabhumi', 'Bangkok', 'Thailand', 'TH');

        $this->assertSame(['BKK'], $this->codes($this->search('BANGKOK')));
        $this->assertSame(['BKK'], $this->codes($this->search('bkk')));
        $this->assertSame(['BKK'], $this->codes($this->search('BkK')));
    }

    /**
     * The same order resources/js/stores/destinations.js ranks the curated list with (code > place > country > substring)
     * — one panel, one order (docs/BUSINESS-LOGIC.md §36).
     */
    #[Test]
    public function the_code_beats_the_city_and_the_city_beats_the_country(): void
    {
        $this->airport('IND', 'Indianapolis', 'Indianapolis', 'United States', 'US');
        $this->airport('IDR', 'Devi Ahilya Bai Holkar', 'Indore', 'India', 'IN');
        $this->airport('DPS', 'Ngurah Rai', 'Bali', 'Indonesia', 'ID');
        $this->airport('WDH', 'Hosea Kutako', 'Windhoek', 'Namibia', 'NA');

        // All four ranks in one query: exact code, city-starts-with, country-
        // starts-with, and city-contains.
        $this->assertSame(['IND', 'IDR', 'DPS', 'WDH'], $this->codes($this->search('ind')));

        /* No exact code: alphabetical by city within the rank, which is total. */
        $this->airport('SAN', 'San Diego', 'San Diego', 'United States', 'US');
        $this->airport('JTR', 'Santorini', 'Santorini', 'Greece', 'GR');

        $this->assertSame(['SAN', 'JTR'], $this->codes($this->search('sa')));
    }

    #[Test]
    public function it_never_answers_with_more_than_ten(): void
    {
        foreach (range(1, 14) as $index) {
            $this->airport(sprintf('A%02d', $index), 'Sanport', 'Sancity '.$index, 'Sanland', 'SA');
        }

        $this->assertCount(10, $this->search('san')->assertOk()->json('data'));
    }

    /**
     * `%`/`_` are LIKE wildcards, both escaped — the one injection-shaped thing a search endpoint has; `%` must not match
     * all 3,270 rows (docs/BUSINESS-LOGIC.md §36).
     */
    #[Test]
    public function a_like_wildcard_is_a_character_somebody_typed(): void
    {
        $this->airport('JFK', 'John F. Kennedy', 'New York', 'United States', 'US');
        $this->airport('PCT', 'Percent', '100% Town', 'Nowhere', 'NO');

        $this->assertSame([], $this->codes($this->search('%%')));
        $this->assertSame(['PCT'], $this->codes($this->search('0%')));
        $this->assertSame([], $this->codes($this->search('n_w')));
    }

    /**
     * Deliberately differs from `GET /api/destinations` — Amsterdam is a valid origin here since RoutePairRequest accepts
     * DUS-AMS (docs/BUSINESS-LOGIC.md §36).
     */
    #[Test]
    public function an_origin_is_an_airport_like_any_other(): void
    {
        Airport::factory()->origin()->create([
            'iata' => 'AMS', 'city' => 'Amsterdam', 'name' => 'Schiphol', 'country' => 'Netherlands',
        ]);

        $this->assertSame(['AMS'], $this->codes($this->search('amsterdam')));
    }

    #[Test]
    public function it_may_be_cached_privately(): void
    {
        $this->airport('JFK', 'John F. Kennedy', 'New York', 'United States', 'US');

        $this->search('new')->assertOk()->assertHeader('Cache-Control', 'max-age=300, private');
    }

    /**
     * Sixty a minute, keyed on the account — guards against a client bug, not a cost. See the `airport-search` limiter in
     * AppServiceProvider (docs/BUSINESS-LOGIC.md §36).
     */
    #[Test]
    public function the_sixty_first_search_in_a_minute_is_refused(): void
    {
        $this->airport('JFK', 'John F. Kennedy', 'New York', 'United States', 'US');

        foreach (range(1, 60) as $ignored) {
            $this->search('new')->assertOk();
        }

        $this->search('new')->assertStatus(429);
    }

    #[Test]
    public function the_world_is_searchable_once_it_is_seeded(): void
    {
        $this->seedTheWorld();

        // TOKYO IS HANEDA, NOT NARITA — a fact about the OurAirports snapshot (NRT files under its own municipality), not this
        // endpoint (docs/BUSINESS-LOGIC.md §36).
        $this->assertSame(['HND'], $this->codes($this->search('tokyo')));
        $this->assertSame(['NRT'], $this->codes($this->search('narita')));

        /* A curated row, with the name and city a person wrote rather than the snapshot's. */
        $this->assertSame(
            ['iata' => 'JFK', 'city' => 'New York', 'country' => 'United States', 'countryCode' => 'US'],
            $this->search('jfk')->assertOk()->json('data.0'),
        );

        /* And a world-only one, which no rule will ever match. */
        $this->assertContains('EWR', $this->codes($this->search('newark')));
        $this->assertNull(Airport::query()->where('iata', 'EWR')->firstOrFail()->destination);
    }

    /**
     * The query somebody with no idea what they want types. Ten rows out of a
     * table with 3,270 in it, ranked, and quick enough to be a keystroke.
     */
    #[Test]
    public function a_two_letter_query_against_the_whole_world_is_still_ten_rows(): void
    {
        $this->seedTheWorld();

        $response = $this->search('sa')->assertOk();

        $this->assertCount(10, $response->json('data'));
        $this->assertSame(10, $response->json('meta.count'));
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function search(string $query): TestResponse
    {
        return $this->actingAs($this->owner)->getJson('/api/airports?q='.urlencode($query));
    }

    /**
     * @param  TestResponse<JsonResponse>  $response
     * @return list<string>
     */
    private function codes(TestResponse $response): array
    {
        /** @var list<string> $codes */
        $codes = $response->assertOk()->json('data.*.iata');

        return $codes;
    }

    private function airport(string $iata, string $name, string $city, string $country, string $countryCode): Airport
    {
        return Airport::factory()->create([
            'iata'         => $iata,
            'name'         => $name,
            'city'         => $city,
            'country'      => $country,
            'country_code' => $countryCode,
        ]);
    }

    private function seedTheWorld(): void
    {
        $this->seed(DestinationSeeder::class);
        $this->seed(WorldAirportSeeder::class);

        /* The curated rows are the ones with an opinion attached. */
        $this->assertSame(184, Destination::query()->count());
    }
}
