<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Every place a route can start or end.
 *
 * IATA IS THE REAL KEY and the surrogate id is a convenience: the code is what
 * the URL carries (`/route/AMS-LIS`), what the provider APIs speak and what the
 * design prints on the boarding-pass rows. It is unique here so a second
 * "AMS" cannot be created by a careless seeder run and quietly split a route's
 * history in two.
 *
 * LAT/LNG ARE DOUBLES, not decimals. They are read straight into the globe's
 * camera and great-circle maths, where they are floats anyway — a decimal
 * column would only mean Eloquent handing the client a string that JavaScript
 * has to parse back. Six decimal places of precision is ~10cm; a double gives
 * fifteen significant digits, which is more than an airport needs.
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
             * Whether this airport is one the owner departs FROM.
             *
             * The design's add-route form offers exactly three buttons — AMS,
             * EIN, DUS (design/README.md §5) — and this is what makes that
             * list data rather than a hard-coded array in a Vue component.
             * Amsterdam is also a destination for nobody, so the flag is not
             * merely the inverse of having a `destinations` row.
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
