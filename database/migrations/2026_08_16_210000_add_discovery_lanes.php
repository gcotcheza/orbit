<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Two changes in one migration because they're one feature: the lane column is a label nothing can earn without the baseline table, and the table is a
 * measurement nothing reads without the column — rolling back half would leave the job writing a lane it can't compute (docs/BUSINESS-LOGIC.md §16).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discoveries', function (Blueprint $table): void {
            /**
             * `lane` is a string (App\Domain\Discovery\Lane: absolute vs relative), not a boolean; defaults to `absolute` (correct
             * for every existing row); deliberately outside the (code, departure_date) unique key (docs/BUSINESS-LOGIC.md §16).
             */
            /* Sized 16, not 8: 'absolute' is eight characters — fits the longest enum case, not the route code. */
            $table->string('lane', 16)->default('absolute')->after('code');
        });

        /**
         * Baselines live in their own table, not `calendar_fares` — writing there would mint speculative `routes` rows and leak into the alert pipeline
         * (§10/§16). One row per code, overwritten (not a history); no foreign keys, same call as `discoveries.code` (docs/BUSINESS-LOGIC.md §16).
         */
        Schema::create('discovery_baselines', function (Blueprint $table): void {
            $table->id();

            /* `AMS-DUB` — App\Models\Route::codeFor's spelling. */
            $table->string('code', 7)->unique();

            /**
             * Median (not mean) cents from the route's near window — same reasoning as SelfStatsProvider/CandidateScorer::median:
             * a long right tail would inflate discounts (docs/BUSINESS-LOGIC.md §16).
             */
            $table->unsignedInteger('median_cents');

            /**
             * Count of priced dates behind the median; below config's min_baseline_days floor the baseline is treated as ABSENT
             * (re-explored, not disqualified forever) (docs/BUSINESS-LOGIC.md §16).
             */
            $table->unsignedSmallInteger('sample_days');

            /**
             * Measurement timestamp; baselines expire (a stale median is arithmetic against a price that stopped being true) — the
             * staleness rule lives in config (docs/BUSINESS-LOGIC.md §16).
             */
            $table->timestamp('measured_at');

            $table->timestamps();

            /**
             * No second index: unique() on `code` above already covers the only read pattern (one whereIn per job run over a table
             * with a steady state of hundreds of rows) (docs/BUSINESS-LOGIC.md §16).
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
