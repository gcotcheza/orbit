<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

/**
 * Everything a fresh box needs to be Orbit: the account, the places, the
 * routes, and — only while a fake provider is serving fares — their prices.
 *
 * STILL NO FICTION IN THE PRICE TABLES. The rule this file was written with
 * has not moved: a deal score is a judgement about real money and rows that
 * pretend to be observations would put invention into an alert. What fills
 * `route_price_history` below is not a fixture — it is the ORDINARY POLLER,
 * running against whichever adapter config/orbit.php selects, and
 * FakeHistorySeeder refuses outright to run once that adapter is a real one.
 * See that file for the distinction and why it holds.
 *
 * ORDER MATTERS AND IS THE ONLY REASON THESE ARE SEPARATE CALLS: the watchlist
 * needs an account and airports, and the poller needs a watchlist.
 *
 * THE TWO AIRPORT SEEDERS ARE IN THE ORDER THEY ARE FOR A REASON THAT IS NOT
 * DEPENDENCY. DestinationSeeder writes the 184 airports somebody sat down and
 * described; WorldAirportSeeder imports the other 3,083 from a third-party
 * snapshot and skips every code the first one owns. Running the import second
 * is what makes "the curated row wins" true by construction rather than by a
 * correction pass afterwards — see WorldAirportSeeder.
 *
 * This runs on every deploy, so everything it calls has to be idempotent.
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
            /*
             * LAST, AND IT NEEDS BOTH OF THE TWO ABOVE. A discovery run scores
             * candidates against the airports table and then verifies its
             * shortlist through the price provider, so it wants the world
             * imported and the fares reachable. It is a no-op unless a FAKE
             * sweep provider is configured — see DiscoverySeeder, where the
             * guard is the whole file.
             */
            DiscoverySeeder::class,
        ]);
    }
}
