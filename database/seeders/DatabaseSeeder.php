<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

/**
 * Everything a fresh box needs to be Orbit, in dependency order
 * (docs/BUSINESS-LOGIC.md §36, "Database and config").
 */
final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            SingleUserSeeder::class,
            DestinationSeeder::class,
            WorldAirportSeeder::class,
            WatchlistSeeder::class,
            FakeHistorySeeder::class,
            // Last, and it needs both of the two above (docs/BUSINESS-LOGIC.md §36).
            DiscoverySeeder::class,
        ]);
    }
}
