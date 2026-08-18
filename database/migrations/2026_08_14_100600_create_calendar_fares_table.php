<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * The cheapest fare for each DEPARTURE date — the calendar heatmap, stored.
 *
 * THE OTHER AXIS FROM route_price_history, and the reason the two are separate
 * tables rather than one with a nullable column: that one is indexed by when
 * we LOOKED and answers "is this falling", this one is indexed by when you
 * would FLY and answers "which Tuesday is cheap". Every poll rewrites this
 * table's window and appends a single row to that one.
 *
 * ONE ROW PER (route, departure_date), overwritten on every poll. Orbit does
 * not keep the history of what next June looked like in April — nothing in the
 * design or the plan asks that question, and keeping it would mean 90 new rows
 * per route per day forever for a chart nobody drew.
 *
 * `fetched_at` is on the row rather than inferred from `updated_at` because it
 * is a fact about the FARE (how stale is this number) and the UI shows it;
 * `updated_at` is a fact about the row and any future backfill would move it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_fares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->date('departure_date');
            $table->unsignedInteger('price_cents');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['route_id', 'departure_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_fares');
    }
};
