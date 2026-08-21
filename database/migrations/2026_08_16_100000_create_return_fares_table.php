<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * The cheapest ROUND-TRIP fare per (departure date, stay length) — its own table, with `nights`
 * stored and `return_date` derived, on one retention clock (docs/BUSINESS-LOGIC.md §15).
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

            // The provider's own grain: `/v2/prices/latest` is unique per (depart, return),
            // so this key agrees with the API and its prefixes serve reads.
            $table->unique(['route_id', 'departure_date', 'nights']);

            // The other way round, for the real question: stay length filters first, which
            // the unique index cannot serve without scanning every candidate week.
            $table->index(['route_id', 'nights', 'departure_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_fares');
    }
};
