<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * The cheapest ROUND-TRIP fare for each (departure date, stay length).
 *
 * The third fare table: `calendar_fares` prices one-way, `route_price_ history` tracks lookup day — neither answers
 * "what does a week cost" (docs/BUSINESS-LOGIC.md §15).
 *
 * A separate table, not a nullable `nights` on `calendar_fares` — the unique key, retention rule, and every existing
 * read all differ (docs/BUSINESS-LOGIC.md §15).
 *
 * `nights` is stored, `return_date` is not — same fact twice, and nights is the query axis, indexes as a plain
 * integer, and derives the date exactly (docs/BUSINESS-LOGIC.md §15).
 *
 * Unsigned small integer: floors at 0 (same-day returns are real fares); a negative stay is corrupt and the column
 * type refuses it (docs/BUSINESS-LOGIC.md §15).
 *
 * One row per (route, departure_date, nights), overwritten every poll — same rule as `calendar_fares`; no history is
 * kept, nothing needs it (docs/BUSINESS-LOGIC.md §15).
 *
 * Retention is one clock, not two, unlike `calendar_fares` (which polls at two speeds) — this table is fetched in one
 * request per horizon (docs/BUSINESS-LOGIC.md §15).
 *
 * `found_at` is nullable and means "age unknown", never "fresh" — see `add_found_at_to_calendar_fares` for why that
 * distinction matters here too (docs/BUSINESS-LOGIC.md §15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_fares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->date('departure_date');
            $table->unsignedSmallInteger('nights');
            $table->unsignedInteger('price_cents');
            $table->timestamp('fetched_at');
            $table->timestamp('found_at')->nullable();
            $table->timestamps();

            // The provider's own grain: `/v2/prices/latest` is unique per (depart_date, return_date) — this key agrees with the
            // API, and its prefixes serve reads (docs/BUSINESS-LOGIC.md §15).
            $table->unique(['route_id', 'departure_date', 'nights']);

            // The other way round, for the real question: stay length filters first, which the unique index can't serve without
            // scanning every candidate week (docs/BUSINESS-LOGIC.md §15).
            $table->index(['route_id', 'nights', 'departure_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_fares');
    }
};
