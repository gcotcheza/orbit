<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * One row per route per day. `observed_on` is a DATE and unique per route — that is what makes
 * the poller idempotent — and it is the OWNER'S date, not UTC's (docs/BUSINESS-LOGIC.md §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_price_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->date('observed_on');
            $table->unsignedInteger('price_cents');
            $table->timestamps();

            $table->unique(['route_id', 'observed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_price_history');
    }
};
