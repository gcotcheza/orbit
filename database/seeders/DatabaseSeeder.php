<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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
            WatchlistSeeder::class,
            FakeHistorySeeder::class,
        ]);
    }
}
