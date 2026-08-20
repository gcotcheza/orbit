<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * When the FARE was found, as distinct from when Orbit fetched it. Nullable, existing rows stay
 * null, and `fetched_at` is NOT a substitute for it (docs/BUSINESS-LOGIC.md §2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_fares', function (Blueprint $table): void {
            /*
             * AFTER `fetched_at` so the two read side by side — ignored by SQLite, honoured by
             * Postgres.
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
