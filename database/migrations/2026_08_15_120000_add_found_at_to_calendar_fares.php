<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the FARE was found, as distinct from when Orbit fetched it.
 *
 * =============================================================================
 * THE TWO TIMESTAMPS ARE NOT THE SAME FACT, AND CONFLATING THEM IS THE BUG THIS
 * COLUMN EXISTS TO FIX
 * =============================================================================
 * `fetched_at` is when ORBIT ASKED. `found_at` is when SOMEBODY'S SEARCH
 * ACTUALLY TURNED THIS PRICE UP. Travelpayouts does not fly planes and does not
 * quote fares: it serves a CACHE of results other people's searches produced
 * (see App\Infrastructure\Pricing\TravelpayoutsPriceProvider, and §2 of
 * docs/BUSINESS-LOGIC.md). So the morning poll can stamp `fetched_at` at 06:10
 * today and be handed a price that was last seen on Tuesday.
 *
 * Every screen in this app read `fetched_at` and said, in effect, "this is
 * today's price". The owner caught it: Orbit showed €36 for a date where
 * Skyscanner's live cheapest was €56. Nothing was broken — the €36 was a real
 * fare when it was found, days earlier, and had since gone. What was broken was
 * that nothing on the screen said so.
 *
 * NULLABLE, AND EXISTING ROWS STAY NULL. There is no honest value to backfill:
 * every row already in this table was written before the adapter passed the
 * field through, and its find time is not recoverable from anything we hold.
 * `fetched_at` is NOT a substitute — using it would state precisely the false
 * thing this column was added to stop stating. Null renders as NOTHING (no
 * "Seen …" line at all), never as a fabricated age, and the next poll fills the
 * row in for real.
 *
 * NOT AN INDEX. Nothing filters or sorts on it: the staleness sweep in
 * App\Jobs\PollRoutePrices is keyed on `fetched_at` (which is the right axis for
 * "has the provider stopped quoting this date"), and this column is read a
 * handful of rows at a time, alongside the price it belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_fares', function (Blueprint $table): void {
            /*
             * AFTER `fetched_at` so the two sit side by side in a `\d` — they
             * are read together and the whole point is telling them apart.
             * (Ignored by SQLite, which has no column order to speak of; the
             * test suite does not care and Postgres does.)
             */
            $table->timestamp('found_at')->nullable()->after('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_fares', function (Blueprint $table): void {
            $table->dropColumn('found_at');
        });
    }
};
