<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * How and when the owner wants to be told (design/README.md §6). A separate table, one row per
 * account, and the defaults in this file are the only copy (docs/BUSINESS-LOGIC.md §36).
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
            // Wall-clock times in the owner's zone, not UTC — storing UTC would shift "no pings
            // after 10pm" by an hour every DST change.

            // Defaults include seconds: Postgres normalises `time` to HH:MM:SS on the way out and
            // SQLite does not; trimmed in UserSettings::quietStartAt().
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
