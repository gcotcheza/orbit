<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * What a round trip usually costs, per route AND duration band — the band is the axis the
 * question is asked along, so it is part of the key (docs/BUSINESS-LOGIC.md §15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_price_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('nights_min');
            $table->unsignedSmallInteger('nights_max');
            $table->unsignedInteger('min_cents');
            $table->unsignedInteger('p25_cents');
            $table->unsignedInteger('median_cents');
            $table->unsignedInteger('p75_cents');
            $table->unsignedInteger('max_cents');

            // How many fares the five columns were computed from: a summary of six is not
            // the same claim as a summary of sixty, and only this column says which.
            $table->unsignedInteger('sample_count');
            $table->timestamp('refreshed_at');
            $table->timestamps();

            $table->unique(['route_id', 'nights_min', 'nights_max']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_price_stats');
    }
};
