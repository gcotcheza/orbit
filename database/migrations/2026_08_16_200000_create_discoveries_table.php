<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * The routes Orbit went looking for on its own — "the insanely cheap ones you
 * never thought to watch".
 *
 * The one fare table here deliberately NOT history — a discovery is about a MOMENT, so DiscoverDeals prunes aggressively (~10 rows steady state); a real
 * table, not cache, since an endpoint reads it on the launch path (docs/BUSINESS-LOGIC.md §16).
 *
 * No `route_id`, by design — a discovery is a route nobody's watching, so airports are FK'd but the route is a stored
 * `code` string; the ordinary lookup flow creates the real route only when tapped (docs/BUSINESS-LOGIC.md §16).
 *
 * Three different timestamps — `found_at` (price age), `discovered_at` (sort key), `departure_date` (flight date); see §3 on not mixing them.
 * `expires_at` is stored so the read stays an index range scan, not arithmetic (docs/BUSINESS-LOGIC.md §16).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discoveries', function (Blueprint $table): void {
            $table->id();

            // Airports are real rows, the route is not (see docblock) —
            // `restrictOnDelete`: airports never delete in normal ops, so an
            // orphaning delete should error, not silently cascade.
            $table->foreignId('origin_airport_id')->constrained('airports')->restrictOnDelete();
            $table->foreignId('destination_airport_id')->constrained('airports')->restrictOnDelete();

            /* `AMS-AGP` — the hand-off into the existing lookup flow. */
            $table->string('code', 7);

            $table->date('departure_date');
            $table->unsignedInteger('price_cents');

            // Stored, not computed on read — the API sorts on it, and it
            // must keep meaning what it meant when earned (float: too fine a
            // spread for an integer unit).
            // Why: docs/BUSINESS-LOGIC.md §16.
            $table->double('cents_per_km');

            // 0-100, or NULL when verification couldn't fetch one — the honest answer is sometimes "we didn't find out"
            // (docs/BUSINESS-LOGIC.md §16).
            $table->double('percentile')->nullable();

            // What the finalist saves vs. its window's median, in cents — stored because the window itself is gone by read time
            // (docs/BUSINESS-LOGIC.md §16).
            $table->unsignedInteger('savings_cents')->nullable();

            // Google's second opinion, or NULL when not asked. Whole JSON
            // verdict stored (not just a bool) so no two places can recompute
            // and disagree; NULL ("not asked/wouldn't say") is the ordinary case.
            // Why: docs/BUSINESS-LOGIC.md §16.
            $table->json('google_verdict')->nullable();

            /* The provider's own age for the price. Null means NOT KNOWN. */
            $table->timestamp('found_at')->nullable();

            /* When Orbit decided. The newest-set sort key. */
            $table->timestamp('discovered_at');

            /* When it stops being shown — see the docblock for why it is stored. */
            $table->timestamp('expires_at');

            $table->timestamps();

            // One live row per (route, departure date) — the upsert key. A
            // repeat candidate updates rather than duplicates; also serves
            // the `code` prefix the prune uses to find superseded rows.
            // Why: docs/BUSINESS-LOGIC.md §16.
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
