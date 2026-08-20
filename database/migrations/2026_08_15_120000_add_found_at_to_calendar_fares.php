<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * When the FARE was found, as distinct from when Orbit fetched it — the two are not the same fact; conflating them is
 * the bug this column fixes.
 *
 * Nullable, and existing rows stay null — there is no honest value to backfill, and `fetched_at` is NOT a substitute
 * for it.
 *
 * Not indexed: nothing filters or sorts on it; the staleness sweep keys on `fetched_at` instead
 * (docs/BUSINESS-LOGIC.md §2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_fares', function (Blueprint $table): void {
            /*
             * AFTER `fetched_at` — read side by side; ignored by SQLite, matters for Postgres (docs/BUSINESS-LOGIC.md §2).
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
