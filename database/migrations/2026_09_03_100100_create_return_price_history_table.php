<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * One morning's round-trip price per (route, duration band) — `route_price_history`'s
 * round-trip twin, and written before anything reads it (docs/BUSINESS-LOGIC.md §15, R7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_price_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('nights_min');
            $table->unsignedSmallInteger('nights_max');
            $table->date('observed_on');
            $table->unsignedInteger('price_cents');

            // The winning fare's own stay length and find time: the band is a range, and a
            // price that is six days old is a different fact from one found this morning.
            $table->unsignedSmallInteger('nights');
            $table->timestamp('found_at')->nullable();
            $table->timestamps();

            $table->unique(['route_id', 'nights_min', 'nights_max', 'observed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_price_history');
    }
};
