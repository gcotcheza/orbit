<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * What a route usually costs, cached: one row per route, refreshed weekly. FIVE COLUMNS IN THE
 * ORDER THEY SORT, and PriceStats refuses to be built out of order (docs/BUSINESS-LOGIC.md §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_price_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('route_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('min_cents');
            $table->unsignedInteger('p25_cents');
            $table->unsignedInteger('median_cents');
            $table->unsignedInteger('p75_cents');
            $table->unsignedInteger('max_cents');
            $table->timestamp('refreshed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_price_stats');
    }
};
