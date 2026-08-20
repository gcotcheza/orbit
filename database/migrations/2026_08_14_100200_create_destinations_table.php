<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * What a place is LIKE — the non-geography half of a destination, kept separate from `airports` because vibes are an editorial judgement, not a
 * geographic fact. Powers the PR10 natural-language rule engine (docs/BUSINESS-LOGIC.md §36).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('airport_id')->unique()->constrained()->cascadeOnDelete();

            /*
             * JSON array of tags, not a tags table + pivot — closed vocabulary of nine words, nothing joins/counts/edits them
             * through a UI (docs/BUSINESS-LOGIC.md §36).
             */
            $table->json('vibes');

            /*
             * How warm it is, month by month: {"1": 2, ..., "12": 3} on a 1-5 scale (1 pack a coat, 5 beach) — a rating, not a
             * temperature (docs/BUSINESS-LOGIC.md §36).
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
