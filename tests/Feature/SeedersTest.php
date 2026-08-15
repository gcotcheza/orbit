<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\Destination;
use App\Models\PriceObservation;
use App\Models\Route;
use App\Models\RouteStats;
use App\Models\User;
use App\Models\WatchlistItem;
use Database\Seeders\DestinationSeeder;
use Database\Seeders\FakeHistorySeeder;
use Database\Seeders\WatchlistSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

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
        Date::setTestNow('2026-08-14 06:10:00');

        User::factory()->create(['email' => config('orbit.seed.email')]);
        $this->seed(DestinationSeeder::class);
        $this->seed(WatchlistSeeder::class);

        config(['orbit.history.backfill_days' => 10]);

        $this->seed(FakeHistorySeeder::class);

        $route = Route::query()->where('code', 'AMS-LIS')->firstOrFail();

        $this->assertSame(10, PriceObservation::query()->where('route_id', $route->id)->count());
        $this->assertSame(6, RouteStats::query()->count());

        // The clock is handed back, or everything after this writes last month.
        $this->assertSame('2026-08-14', Date::now()->toDateString());

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
