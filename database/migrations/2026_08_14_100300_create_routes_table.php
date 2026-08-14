<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A city pair. Everything Orbit measures hangs off one of these.
 *
 * `code` ("AMS-LIS") is DENORMALISED from the two airport ids on purpose. It
 * is what the SPA's URLs carry, what the provider adapters are asked about and
 * what a log line has to be readable with — resolving two joins to name the
 * thing an error is about is how logs stop being read. The unique index on it
 * doubles as the guard against the same pair being inserted twice, which is
 * what keeps a route's price history in one place.
 *
 * NO `active` COLUMN HERE. Whether a route is being watched belongs to the
 * watchlist, not to the route: PR10's rules will surface routes nobody has
 * ever watched, and those still need somewhere to keep a code and a history.
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
