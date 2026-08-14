<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Airport;
use App\Models\Route;
use App\Models\User;
use App\Models\WatchlistItem;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The six routes the app opens on.
 *
 * They are the design's own set (design/README.md §1 — "6 routes orbiting"),
 * which is what makes the globe's auto-tour, the route rail and the watchlist
 * look like the screenshots on a fresh install. Real ones replace them the
 * moment the owner adds their own; nothing else in the app treats these six
 * specially.
 *
 * ROUTES ARE FACTS, WATCHLIST ROWS ARE CHOICES, and the two are seeded
 * differently on purpose:
 *
 *   - the route is `updateOrCreate`d, because "AMS-LIS is Amsterdam to Lisbon"
 *     cannot become wrong;
 *   - the watchlist row is `firstOrCreate`d, because `active` is the owner's
 *     toggle and a deploy that silently un-paused every route they had paused
 *     would be the app arguing with its user.
 */
final class WatchlistSeeder extends Seeder
{
    use WithoutModelEvents;

    /** @var list<string> */
    private const DEMO_ROUTES = [
        'AMS-LIS',
        'AMS-OPO',
        'AMS-NAP',
        'EIN-BCN',
        'AMS-FAO',
        'DUS-AGP',
    ];

    public function run(): void
    {
        /** @var string $email */
        $email = config('orbit.seed.email');

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->command?->warn("No account for {$email} yet; skipping the watchlist.");

            return;
        }

        foreach (self::DEMO_ROUTES as $position => $code) {
            [$originIata, $destinationIata] = explode('-', $code);

            $route = Route::query()->updateOrCreate(
                ['code' => $code],
                [
                    'origin_airport_id' => self::airportId($originIata),
                    'destination_airport_id' => self::airportId($destinationIata),
                ],
            );

            WatchlistItem::query()->firstOrCreate(
                ['user_id' => $user->id, 'route_id' => $route->id],
                ['active' => true, 'position' => $position],
            );
        }

        $this->command?->info(sprintf('%d routes watched by %s.', count(self::DEMO_ROUTES), $email));
    }

    private static function airportId(string $iata): int
    {
        $airport = Airport::query()->where('iata', $iata)->first();

        if ($airport === null) {
            // DestinationSeeder runs first and owns every code used above, so
            // this can only mean the two files have drifted apart.
            throw new RuntimeException("Airport {$iata} is not seeded; DestinationSeeder must run first.");
        }

        return $airport->id;
    }
}
