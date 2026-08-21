<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Models\Route;
use RuntimeException;
use App\Models\Airport;
use App\Models\WatchlistItem;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

/**
 * The six routes the app opens on — the design's own set, replaced the
 * moment the owner adds their own (docs/BUSINESS-LOGIC.md §36).
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
                    'origin_airport_id'      => self::airportId($originIata),
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
            // Can only mean DestinationSeeder and this file have drifted apart.
            throw new RuntimeException("Airport {$iata} is not seeded; DestinationSeeder must run first.");
        }

        return $airport->id;
    }
}
