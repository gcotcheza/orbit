<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * What a place is LIKE — the half of a destination that is not geography.
 *
 * Separate from `airports` because the two answer different questions and
 * change for different reasons: an airport's coordinates are a fact, and
 * whether Faro counts as "sunny in March" is an editorial judgement this app
 * makes. Keeping them apart also means an ORIGIN airport carries no vibes
 * columns it would never fill.
 *
 * THIS TABLE EXISTS FOR PR10, the natural-language rule engine: "cheap weekend
 * somewhere sunny in spring" is answered by filtering `vibes` and reading
 * `warmth` for March-May. It is seeded now because the seed data is the same
 * either way and a rule engine with nothing to match against cannot be built.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('airport_id')->unique()->constrained()->cascadeOnDelete();

            /*
             * A JSON ARRAY OF TAGS rather than a tags table with a pivot.
             *
             * There are ~70 destinations and a closed vocabulary of nine words
             * that only this codebase writes; nothing joins on a vibe, nothing
             * counts them, and nobody edits them through a UI. A join table
             * would be three more objects to keep in step in exchange for a
             * flexibility this app has no use for. Postgres can index a jsonb
             * array the day a query actually needs it.
             */
            $table->json('vibes');

            /*
             * How warm it is, month by month: {"1": 2, ..., "12": 3} on a 1-5
             * scale where 1 is "pack a coat" and 5 is "beach".
             *
             * A RATING RATHER THAN A TEMPERATURE, because the question the app
             * asks is "is it sunny there in spring", and answering that from
             * degrees would mean putting the same editorial judgement in a
             * query instead of in the data. Twelve small integers, keyed by
             * month number as a string (JSON has no integer keys).
             */
            $table->json('warmth');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinations');
    }
};
