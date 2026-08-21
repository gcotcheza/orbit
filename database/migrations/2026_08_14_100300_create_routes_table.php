<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * A city pair. `code` is DENORMALISED from the two airport ids on purpose, and there is no
 * `active` column — being watched belongs to the watchlist (docs/BUSINESS-LOGIC.md §1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 7)->unique();
            $table->foreignId('origin_airport_id')->constrained('airports')->cascadeOnDelete();
            $table->foreignId('destination_airport_id')->constrained('airports')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['origin_airport_id', 'destination_airport_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routes');
    }
};
