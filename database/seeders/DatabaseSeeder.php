<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * There is one thing to seed, and it is the account.
 *
 * No demo fares, no fictional routes. Orbit's whole job is to say whether a
 * price is unusually low, and that judgement is made against price history it
 * has accrued — inventing any of it would put fiction into a deal score and
 * into the alert that follows from one. When there are no provider keys yet
 * the FAKE PROVIDERS (docs/PLAN.md, PR5) serve realistic data through the same
 * port the real ones do, which is a swappable adapter rather than rows in the
 * database pretending to be observations.
 *
 * This runs on every deploy, so everything it calls has to be idempotent.
 */
final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(SingleUserSeeder::class);
    }
}
