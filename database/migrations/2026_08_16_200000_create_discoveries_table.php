<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * The routes Orbit went looking for on its own — a fare table deliberately NOT history, and
 * deliberately without a `route_id` (docs/BUSINESS-LOGIC.md §16).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discoveries', function (Blueprint $table): void {
            $table->id();

            // Airports are real rows, the route is not: `restrictOnDelete`, so an orphaning
            // delete errors instead of silently cascading.
            $table->foreignId('origin_airport_id')->constrained('airports')->restrictOnDelete();
            $table->foreignId('destination_airport_id')->constrained('airports')->restrictOnDelete();

            /* `AMS-AGP` — the hand-off into the existing lookup flow. */
            $table->string('code', 7);

            $table->date('departure_date');
            $table->unsignedInteger('price_cents');

            // Stored, not computed on read — the API sorts on it and it must keep meaning
            // what it meant when earned (docs/BUSINESS-LOGIC.md §16).
            $table->double('cents_per_km');

            // 0-100, or NULL when verification could not fetch one — "we didn't find out"
            // is an honest answer (docs/BUSINESS-LOGIC.md §16).
            $table->double('percentile')->nullable();

            // What the finalist saves vs its window's median, in cents — stored because the
            // window itself is gone by read time (docs/BUSINESS-LOGIC.md §16).
            $table->unsignedInteger('savings_cents')->nullable();

            // Google's second opinion, or NULL when not asked. The whole JSON verdict is
            // stored so no two places can recompute and disagree (docs/BUSINESS-LOGIC.md §16).
            $table->json('google_verdict')->nullable();

            /* The provider's own age for the price. Null means NOT KNOWN. */
            $table->timestamp('found_at')->nullable();

            /* When Orbit decided. The newest-set sort key. */
            $table->timestamp('discovered_at');

            /* When it stops being shown — see the docblock for why it is stored. */
            $table->timestamp('expires_at');

            $table->timestamps();

            // One live row per (route, departure date) — the upsert key, and the `code`
            // prefix the prune uses to find superseded rows (docs/BUSINESS-LOGIC.md §16).
            $table->unique(['code', 'departure_date']);

            // Filter column leads, sort column follows — GET /api/discoveries
            // answers both halves ("live, cheapest per km") off one index.
            $table->index(['expires_at', 'cents_per_km']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discoveries');
    }
};
