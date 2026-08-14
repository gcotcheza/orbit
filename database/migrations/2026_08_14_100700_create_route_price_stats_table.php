<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a route usually costs — the statistics provider's answer, cached.
 *
 * ONE ROW PER ROUTE, refreshed weekly by App\Jobs\RefreshRouteStats. It is
 * stored rather than fetched on demand because it is a paid, rate-limited call
 * whose answer moves over months, and every screen in the app needs it: the
 * spotlight's "% below usual", the detail chart's dashed reference line, and
 * 75 of the deal score's 100 points.
 *
 * FIVE COLUMNS IN THE ORDER THEY SORT, and App\Domain\Pricing\PriceStats
 * refuses to be constructed out of order — a p25 above the median would make
 * the score reward expensive fares, silently, forever.
 *
 * `refreshed_at` is what tells a reader whether a "usual price" is from this
 * month or from whenever the provider last answered.
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
