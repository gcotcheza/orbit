<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * How and when the owner wants to be told (design/README.md §6).
 *
 * A separate table, not columns on `users`: keeps the framework's hot-path table narrow, and preferences (which will
 * grow) are read on a schedule, not per request (docs/BUSINESS-LOGIC.md §36).
 *
 * One row per account, enforced by a unique on `user_id` (a HasOne), not by convention (docs/BUSINESS-LOGIC.md §36).
 *
 * The defaults in this file are the only copy: UserSettings::for() creates the row with no attributes and re-reads it,
 * so there's no second list to drift (docs/BUSINESS-LOGIC.md §36).
 *
 * `sensitivity` is an int, not an enum column, since the score it maps to (config/orbit.php) is a tuning decision that
 * shouldn't need a migration (docs/BUSINESS-LOGIC.md §36).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->boolean('email_alerts')->default(true);
            $table->boolean('push_alerts')->default(false);
            $table->boolean('weekly_digest')->default(true);

            $table->boolean('quiet_hours')->default(true);
            // Wall-clock times in the owner's zone (config('orbit.timezone')), not UTC — storing UTC would shift "no pings after
            // 10pm" by an hour every DST change (docs/BUSINESS-LOGIC.md §36).

            // Defaults include seconds: Postgres normalises `time` to HH:MM:SS on the way out, SQLite doesn't. Trimmed to HH:MM
            // only in UserSettings::quietStartAt()/quietEndAt() (docs/BUSINESS-LOGIC.md §36).
            $table->time('quiet_start')->default('22:00:00');
            $table->time('quiet_end')->default('08:00:00');

            $table->unsignedSmallInteger('sensitivity')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
