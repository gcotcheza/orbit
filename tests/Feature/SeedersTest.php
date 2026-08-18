<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Route;
use App\Models\Airport;
use App\Models\RouteStats;
use App\Models\Destination;
use App\Models\WatchlistItem;
use App\Models\PriceObservation;
use Illuminate\Support\Facades\Date;
use Database\Seeders\WatchlistSeeder;
use PHPUnit\Framework\Attributes\Test;
use Database\Seeders\DestinationSeeder;
use Database\Seeders\FakeHistorySeeder;
use Database\Seeders\WorldAirportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The seeders run on EVERY DEPLOY, so what is tested here is mostly what
 * happens the second time.
 */
final class SeedersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_destinations_land_with_their_vibes_and_a_rating_for_every_month(): void
    {
        $this->seed(DestinationSeeder::class);

        $this->assertGreaterThanOrEqual(60, Destination::query()->count());
        $this->assertSame(3, Airport::query()->where('is_origin', true)->count());

        $faro = Airport::query()->where('iata', 'FAO')->with('destination')->firstOrFail();

        $this->assertSame('Portugal', $faro->country);
        $this->assertSame('PT', $faro->country_code);
        $this->assertNotNull($faro->destination);
        $this->assertContains('beach', $faro->destination->vibes);
        $this->assertCount(12, $faro->destination->warmth);
        // High summer beats midwinter, everywhere.
        $this->assertGreaterThan($faro->destination->warmthIn(1), $faro->destination->warmthIn(7));
    }

    #[Test]
    public function every_destination_is_rated_one_to_five_for_all_twelve_months(): void
    {
        $this->seed(DestinationSeeder::class);

        foreach (Destination::query()->cursor() as $destination) {
            $this->assertCount(12, $destination->warmth);

            foreach (range(1, 12) as $month) {
                $rating = $destination->warmthIn($month);

                $this->assertGreaterThanOrEqual(1, $rating);
                $this->assertLessThanOrEqual(5, $rating);
            }

            $this->assertNotSame([], $destination->vibes);
        }
    }

    /**
     * `config('orbit.origins')` and the seeder's `is_origin` flag are the same
     * three airports, said twice: the config is what a request is validated
     * against without a query, the data file is what carries the coordinates.
     * This is the line that stops them drifting.
     */
    #[Test]
    public function the_configured_origins_are_the_airports_the_seeder_flags_as_such(): void
    {
        $this->seed(DestinationSeeder::class);

        /** @var list<string> $configured */
        $configured = config('orbit.origins');
        sort($configured);

        $this->assertSame(
            Airport::query()->where('is_origin', true)->orderBy('iata')->pluck('iata')->all(),
            $configured,
        );
    }

    /**
     * The SAME DRIFT GUARD, one layer up: the rule parser's vibe vocabulary
     * and the tags the seeder actually writes.
     *
     * A key in `config('orbit.nlp.vibe_words')` that no destination carries is
     * a rule somebody can write that matches nothing, forever, with no error
     * anywhere — the worst shape of bug this feature can have. A tag in the
     * data that the parser has no words for is the opposite: a place nobody
     * can ask for. Both are silent, so they are asserted.
     */
    #[Test]
    public function the_parsers_vibe_vocabulary_is_exactly_the_one_the_seeder_writes(): void
    {
        $this->seed(DestinationSeeder::class);

        $seeded = [];

        foreach (Destination::query()->cursor() as $destination) {
            foreach ($destination->vibes as $vibe) {
                $seeded[$vibe] = true;
            }
        }

        $seeded = array_keys($seeded);
        sort($seeded);

        $configured = array_keys((array) config('orbit.nlp.vibe_words'));
        sort($configured);

        $this->assertSame($seeded, $configured);
    }

    #[Test]
    public function every_vibe_the_parser_knows_has_a_chip_to_show_for_it(): void
    {
        $labels = (array) config('orbit.nlp.vibe_labels');

        foreach (array_keys((array) config('orbit.nlp.vibe_words')) as $vibe) {
            $this->assertArrayHasKey($vibe, $labels, "No chip label for the [{$vibe}] vibe.");
            $this->assertNotSame('', $labels[$vibe]);
        }
    }

    /**
     * The words somebody types for an airport, against the airports that exist.
     * An alias pointing at a fourth airport would be a rule that reads as
     * departing from somewhere Orbit cannot fly from.
     */
    #[Test]
    public function every_origin_alias_names_a_configured_origin_and_every_origin_has_one(): void
    {
        $this->seed(DestinationSeeder::class);

        /** @var array<string, string> $aliases */
        $aliases = config('orbit.nlp.origin_aliases');
        /** @var list<string> $origins */
        $origins = config('orbit.origins');

        foreach ($aliases as $word => $iata) {
            $this->assertContains($iata, $origins, "The alias [{$word}] points at an airport Orbit does not fly from.");
            $this->assertSame(mb_strtolower($word), $word, 'Aliases are matched against lower-cased text.');
        }

        foreach ($origins as $iata) {
            $this->assertContains($iata, $aliases, "Nobody can name [{$iata}] in a rule.");

            /* The city the seeder gave it is one of the words a person can type. */
            $city = Airport::query()->where('iata', $iata)->firstOrFail()->city;
            $this->assertArrayHasKey(mb_strtolower($city), $aliases, "[{$city}] is not a word this app answers to.");
        }
    }

    #[Test]
    public function seeding_the_destinations_twice_changes_nothing(): void
    {
        $this->seed(DestinationSeeder::class);
        $before = Airport::query()->count();

        $this->seed(DestinationSeeder::class);

        $this->assertSame($before, Airport::query()->count());
    }

    // -- The world import ----------------------------------------------------
    // Tier 1: every scheduled airport on Earth, so that any IATA code can be
    // looked up and watched. It is a third-party snapshot rather than a list
    // anybody wrote, which is why the rules never touch it and why the tests
    // below are mostly about what it must NOT do.

    /**
     * The counts, and they are two different facts rather than one number.
     *
     * 184 CURATED DESTINATIONS is an editorial decision — 77 European plus the
     * 107 in world_destinations.php — and is what the rule engine may match.
     * 3,270 AIRPORTS is what OurAirports says has scheduled service and an IATA
     * code; it is what `exists:airports,iata` validates against, and it moves
     * whenever the snapshot is refreshed.
     *
     * They overlap: 187 of the 3,270 codes are curated (184 destinations and
     * the three origins) and are seeded by the first seeder, so the second one
     * imports 3,083 — WHICH IS WHERE THE TOTAL COMES FROM. A curated code the
     * snapshot has never heard of would break this arithmetic, and should: it
     * would be a destination the box offers and no provider can price.
     */
    #[Test]
    public function the_world_import_adds_every_airport_the_curated_files_do_not_name(): void
    {
        $this->seed(DestinationSeeder::class);

        $this->assertSame(187, Airport::query()->count());
        $this->assertSame(184, Destination::query()->count());

        $this->seed(WorldAirportSeeder::class);

        $this->assertSame(3270, Airport::query()->count());

        /* The import writes airports and never an opinion about one. */
        $this->assertSame(184, Destination::query()->count());

        /* Every curated code is in the snapshot, which is what makes 187 + 3,083 = 3,270. */
        $this->assertCount(187, array_unique(DestinationSeeder::curatedCodes()));
    }

    /**
     * THE RULE THE IMPORT HAS, and the reason it runs second.
     *
     * The snapshot calls JFK "John F. Kennedy International Airport" and files
     * Sydney's city as "Sydney (Mascot)"; the curated file calls them what a
     * person would. Re-seeding happens on every deploy, so an import that
     * upserted over the top of those would undo the editorial pass every time
     * the app was deployed — silently, and only in the places somebody had
     * bothered to improve.
     */
    #[Test]
    public function the_import_does_not_overwrite_a_row_somebody_wrote_by_hand(): void
    {
        $this->seed(DestinationSeeder::class);
        $this->seed(WorldAirportSeeder::class);

        $jfk = Airport::query()->where('iata', 'JFK')->firstOrFail();

        $this->assertSame('John F. Kennedy', $jfk->name);
        $this->assertSame('New York', $jfk->city);
        $this->assertNotNull($jfk->destination, 'JFK is a curated destination and keeps its vibes.');

        $sydney = Airport::query()->where('iata', 'SYD')->firstOrFail();

        $this->assertSame('Sydney', $sydney->city);

        /* And the world rows keep the snapshot's own spelling, which is the point of them. */
        $newark = Airport::query()->where('iata', 'EWR')->firstOrFail();

        $this->assertSame('Newark Liberty International Airport', $newark->name);
        $this->assertNull($newark->destination);
    }

    /**
     * `is_origin` IS A FACT ABOUT A PERSON, not about an airport, and the
     * snapshot has no opinion about it. Left alone on insert (the column's
     * `false` default) and left out of the update list entirely, so no refresh
     * of somebody else's data file can add a fourth origin or unset one of the
     * three.
     */
    #[Test]
    public function the_import_cannot_change_which_airports_are_origins(): void
    {
        $this->seed(DestinationSeeder::class);
        $this->seed(WorldAirportSeeder::class);

        $this->assertSame(
            ['AMS', 'DUS', 'EIN'],
            Airport::query()->where('is_origin', true)->orderBy('iata')->pluck('iata')->all(),
        );

        $this->assertSame('Amsterdam Schiphol', Airport::query()->where('iata', 'AMS')->firstOrFail()->name);
    }

    #[Test]
    public function importing_the_world_twice_changes_nothing(): void
    {
        $this->seed(DestinationSeeder::class);
        $this->seed(WorldAirportSeeder::class);

        $before = Airport::query()->count();
        $jfk = Airport::query()->where('iata', 'JFK')->firstOrFail()->only(['name', 'city']);

        $this->seed(WorldAirportSeeder::class);

        $this->assertSame($before, Airport::query()->count());
        $this->assertSame($jfk, Airport::query()->where('iata', 'JFK')->firstOrFail()->only(['name', 'city']));
    }

    /**
     * THE TWO SEEDERS ARE DISJOINT, and this is the test that says so out loud.
     *
     * "The curated row wins" could have been built as an overwrite that runs
     * afterwards, or as a merge that prefers one side. It is neither: the
     * import never writes a curated code AT ALL, so the 187 rows somebody wrote
     * and the 3,083 rows OurAirports supplied are two sets that do not
     * intersect, and no run order can produce a JFK with the snapshot's name in
     * it. The order in DatabaseSeeder is therefore about a complete table
     * rather than about correctness.
     *
     * WHAT THAT COSTS, stated plainly: WorldAirportSeeder ALONE does not
     * produce a working app — it leaves no AMS. It is not meant to. The
     * refresh-a-snapshot instruction in docs/GO-LIVE.md is `db:seed`, which is
     * DatabaseSeeder, which runs both.
     */
    #[Test]
    public function the_import_and_the_curated_pass_write_disjoint_sets_of_airports(): void
    {
        $this->seed(WorldAirportSeeder::class);

        $this->assertSame(3083, Airport::query()->count());
        $this->assertNull(
            Airport::query()->where('iata', 'JFK')->first(),
            'A curated code is never the import\'s to write, in either order.',
        );

        $this->seed(DestinationSeeder::class);

        $this->assertSame(3270, Airport::query()->count());
        $this->assertSame('John F. Kennedy', Airport::query()->where('iata', 'JFK')->firstOrFail()->name);
    }

    /**
     * Every row of the committed snapshot, checked for the shape the `airports`
     * table demands — three-letter code, two-letter country code, a city and a
     * country that are not empty, a coordinate that is on Earth.
     *
     * IT IS A TEST ABOUT THE NEXT SNAPSHOT rather than this one. Refreshing the
     * file is a documented operation (world_airports.README.md) and this is
     * what makes a refresh that went wrong fail here, in a sentence naming the
     * row, rather than halfway through a production `db:seed`.
     */
    #[Test]
    public function every_row_of_the_snapshot_is_the_shape_the_table_expects(): void
    {
        $this->seed(WorldAirportSeeder::class);

        $airports = Airport::query()->get(['iata', 'name', 'city', 'country', 'country_code', 'lat', 'lng']);

        $this->assertGreaterThan(2000, $airports->count());

        foreach ($airports as $airport) {
            $this->assertSame(3, mb_strlen($airport->iata), "[{$airport->iata}] is not an IATA code.");
            $this->assertSame(mb_strtoupper($airport->iata), $airport->iata);
            $this->assertSame(2, mb_strlen($airport->country_code), "[{$airport->iata}] has no country code.");
            $this->assertNotSame('', trim($airport->name), "[{$airport->iata}] has no name.");
            $this->assertNotSame('', trim($airport->city), "[{$airport->iata}] has no city.");
            $this->assertNotSame('', trim($airport->country), "[{$airport->iata}] has no country.");
            $this->assertGreaterThanOrEqual(-90, $airport->lat);
            $this->assertLessThanOrEqual(90, $airport->lat);
            $this->assertGreaterThanOrEqual(-180, $airport->lng);
            $this->assertLessThanOrEqual(180, $airport->lng);
        }
    }

    #[Test]
    public function the_six_demo_routes_land_on_the_owners_watchlist_in_order(): void
    {
        User::factory()->create(['email' => config('orbit.seed.email')]);

        $this->seed(DestinationSeeder::class);
        $this->seed(WatchlistSeeder::class);

        $this->assertSame(6, Route::query()->count());
        $this->assertSame(6, WatchlistItem::query()->count());

        $this->assertSame(
            ['AMS-LIS', 'AMS-OPO', 'AMS-NAP', 'EIN-BCN', 'AMS-FAO', 'DUS-AGP'],
            WatchlistItem::query()
                ->orderBy('position')
                ->with('route')
                ->get()
                ->map(fn (WatchlistItem $item): string => $item->route->code)
                ->all(),
        );
    }

    /**
     * `active` is the OWNER's toggle. A deploy that silently un-paused every
     * route they had paused would be the app arguing with its user.
     */
    #[Test]
    public function re_seeding_does_not_un_pause_a_route_the_owner_switched_off(): void
    {
        User::factory()->create(['email' => config('orbit.seed.email')]);

        $this->seed(DestinationSeeder::class);
        $this->seed(WatchlistSeeder::class);

        $item = WatchlistItem::query()->firstOrFail();
        $item->update(['active' => false]);

        $this->seed(WatchlistSeeder::class);

        $this->assertFalse($item->fresh()?->active);
        $this->assertSame(6, WatchlistItem::query()->count());
    }

    #[Test]
    public function the_watchlist_seeder_survives_there_being_no_account_yet(): void
    {
        $this->seed(DestinationSeeder::class);
        $this->seed(WatchlistSeeder::class);

        $this->assertSame(0, WatchlistItem::query()->count());
    }

    #[Test]
    public function the_fake_history_seeder_backfills_and_then_leaves_it_alone(): void
    {
        $frozen = '2026-08-14 06:10:00';

        Date::setTestNow($frozen);

        User::factory()->create(['email' => config('orbit.seed.email')]);
        $this->seed(DestinationSeeder::class);
        $this->seed(WatchlistSeeder::class);

        config(['orbit.history.backfill_days' => 10]);

        $this->seed(FakeHistorySeeder::class);

        $route = Route::query()->where('code', 'AMS-LIS')->firstOrFail();

        $this->assertSame(10, PriceObservation::query()->where('route_id', $route->id)->count());
        $this->assertSame(6, RouteStats::query()->count());

        // The clock is handed back, or everything after this writes last month.
        //
        // ASSERTED AGAINST THE FROZEN VALUE, NOT A LITERAL DATE, which is what
        // this line was reaching for: a hard-coded '2026-08-14' is a test that
        // passes on the day it is written. Reading it from the variable above
        // cannot rot at midnight.
        //
        // AND IT IS "RESTORED", NOT "CLEARED". FakeHistorySeeder's `finally`
        // deliberately puts back whatever it found rather than unfreezing —
        // its docblock explains at length why handing a test its own clock back
        // is the honest behaviour — so `hasTestNow()` is still true here. What
        // is worth asserting is that the seeder did not leave one of its
        // backfill dates in place.
        $this->assertSame($frozen, Date::now()->toDateTimeString());

        // Second run: today is re-polled, the backfill is not repeated.
        $this->seed(FakeHistorySeeder::class);
        $this->assertSame(10, PriceObservation::query()->where('route_id', $route->id)->count());

        Date::setTestNow();
    }

    /**
     * The line that keeps the day-1 honesty rule intact once real keys land.
     */
    #[Test]
    public function the_fake_history_seeder_refuses_to_run_against_a_real_provider(): void
    {
        User::factory()->create(['email' => config('orbit.seed.email')]);
        $this->seed(DestinationSeeder::class);
        $this->seed(WatchlistSeeder::class);

        config(['orbit.providers.price' => 'travelpayouts']);

        $this->seed(FakeHistorySeeder::class);

        $this->assertSame(0, PriceObservation::query()->count());
    }
}
