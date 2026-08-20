<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * The cheapest fare per DEPARTURE date — the other axis from route_price_history. One row per
 * (route, departure_date), overwritten every poll (docs/BUSINESS-LOGIC.md §3).
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
