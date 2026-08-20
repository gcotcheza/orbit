<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Every place a route can start or end. IATA is the real key and the surrogate id a
 * convenience; lat/lng are doubles, not decimals (docs/BUSINESS-LOGIC.md §36).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airports', function (Blueprint $table): void {
            $table->id();
            $table->char('iata', 3)->unique();
            $table->string('name');
            $table->string('city');
            $table->string('country');
            $table->char('country_code', 2);
            $table->double('lat');
            $table->double('lng');

            /*
             * Whether this airport is one the owner departs FROM — what makes the design's
             * three buttons data rather than a hard-coded array in a Vue component.
             */
            $table->boolean('is_origin')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airports');
    }
};
