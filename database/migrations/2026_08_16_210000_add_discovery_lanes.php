<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Two changes in one migration because they are one feature: the lane cannot be earned without the
 * baseline table, and the table is read through the lane.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discoveries', function (Blueprint $table): void {
            /**
             * `lane` is a string (App\Domain\Discovery\Lane), not a boolean; it defaults to
             * `absolute` and is deliberately outside the (code, departure_date) unique key.
             */
            /* Sized 16, not 8: 'absolute' is eight characters, the longest enum case. */
            $table->string('lane', 16)->default('absolute')->after('code');
        });

        /**
         * Baselines live in their own table, not `calendar_fares` — writing there would mint
         * speculative `routes` rows and leak into the alert pipeline (docs/BUSINESS-LOGIC.md §16).
         */
        Schema::create('discovery_baselines', function (Blueprint $table): void {
            $table->id();

            /* `AMS-DUB` — App\Models\Route::codeFor's spelling. */
            $table->string('code', 7)->unique();

            /**
             * Median (not mean) cents from the route's near window, the same reasoning as
             * CandidateScorer::median: a long right tail would inflate discounts.
             */
            $table->unsignedInteger('median_cents');

            /**
             * Count of priced dates behind the median; below config's floor the baseline is treated
             * as ABSENT — re-explored, not disqualified forever.
             */
            $table->unsignedSmallInteger('sample_days');

            /**
             * Measurement timestamp; baselines expire, because a stale median is arithmetic against
             * a price that stopped being true.
             */
            $table->timestamp('measured_at');

            $table->timestamps();

            /**
             * No second index: unique() on `code` already covers the only read pattern, one whereIn
             * per job run over a few hundred rows.
             */
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_baselines');

        Schema::table('discoveries', function (Blueprint $table): void {
            $table->dropColumn('lane');
        });
    }
};
