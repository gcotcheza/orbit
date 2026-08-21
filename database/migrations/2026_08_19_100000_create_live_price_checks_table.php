<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * What Google said about ONE route on ONE date, when somebody paid for it — the latest answer
 * per route and date, not a log (docs/BUSINESS-LOGIC.md §17).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_price_checks', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('route_id')->constrained()->cascadeOnDelete();

            /* The day you would FLY — the axis docs/BUSINESS-LOGIC.md §3 warns
               about mixing with the two below. */
            $table->date('departure_date');

            /* When Orbit asked. The cooldown is measured from here. */
            $table->timestamp('checked_at');

            /* App\Domain\Discovery\GoogleVerdict's own shape, cents. Null means
               asked and silent; a search that was never spent gets no row. */
            $table->json('google_verdict')->nullable();

            $table->timestamps();

            $table->unique(['route_id', 'departure_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_price_checks');
    }
};
