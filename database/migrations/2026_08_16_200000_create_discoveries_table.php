<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * The routes Orbit went looking for on its own — "the insanely cheap ones you
 * never thought to watch".
 *
 * =============================================================================
 * THE ONE TABLE IN THIS APP THAT IS DELIBERATELY NOT HISTORY
 * =============================================================================
 * Every other fare table here accrues. `calendar_fares` maintains eleven months
 * and prunes what goes stale; `route_price_history` keeps a row per morning
 * forever because the deal score's trend is built on it; `return_fares` holds
 * a horizon. All three answer questions ABOUT A ROUTE OVER TIME.
 *
 * A discovery is not about a route. It is about a MOMENT — "this was going for
 * €27 on Thursday" — and the moment is the whole of it. Nothing downstream
 * computes a statistic from these rows, no score reads them, and a discovery
 * from last March would be a card offering a flight that left months ago. So
 * App\Jobs\DiscoverDeals prunes on every run: expired rows, superseded rows,
 * and anything past `orbit.discovery.max_rows`. The table's steady state is
 * about ten rows.
 *
 * IT IS A CACHE WITH A SCHEMA, and it is a table rather than the cache store
 * for two reasons. It is READ BY AN API ENDPOINT on the app's own launch path
 * (`GET /api/discoveries`), where a Redis miss would be an empty screen rather
 * than a slow one; and every row carries a verdict that cost a metered request
 * to obtain, which is not a thing to keep somewhere `cache:clear` can take it.
 *
 * =============================================================================
 * NO `route_id`, AND THAT IS THE DESIGN
 * =============================================================================
 * The obvious shape is a foreign key to `routes`. It is wrong here, and the
 * reason is the whole point of the feature: a discovery is a route NOBODY IS
 * WATCHING and that Orbit has usually never priced. Creating a `routes` row for
 * each would mean the nightly job manufacturing five rows a night in the table
 * that `POST /api/watchlist` and the rule engine both treat as "pairs this app
 * knows about" — and App\Http\Controllers\RouteController's `lookup` would stop
 * being able to answer 201, because everything would already exist.
 *
 * So the airports are named by FOREIGN KEY (they are real rows, and the card
 * needs the city and country) and the ROUTE is named by the `code` string that
 * `App\Models\Route::codeFor` would produce. Tapping a discovery navigates to
 * `/route/AMS-AGP`, which prices the pair through the ordinary lookup flow and
 * creates the route row THEN — at the moment a person expressed interest, which
 * is what that endpoint has always meant.
 *
 * `code` IS THEREFORE THE HAND-OFF AND IS STORED RATHER THAN DERIVED. It is one
 * string concatenation, and the copy in this column is what a client navigates
 * to, what the unique key below is built on, and what the `[A-Z]{3}-[A-Z]{3}`
 * route constraint has to accept. Deriving it in three places is how one of
 * them ends up lower-cased.
 *
 * =============================================================================
 * THE THREE TIMESTAMPS, WHICH ARE THREE DIFFERENT QUESTIONS
 * =============================================================================
 *   `found_at`       when the PROVIDER found this price. Nullable, and null
 *                    means "not known" — never "fresh". It is the same field
 *                    `calendar_fares` and `return_fares` carry and it means the
 *                    same thing; see the add_found_at_to_calendar_fares
 *                    migration for the €36-that-was-really-€56 it was bought
 *                    with. On this table it is stricter than anywhere else:
 *                    App\Domain\Discovery\DiscoveryPolicy DISCARDS a candidate
 *                    whose age is unknown, because "insanely cheap" is a claim
 *                    about right now.
 *   `discovered_at`  when ORBIT decided this was worth showing. The sort key
 *                    for "newest set" and the thing `expires_at` is measured
 *                    from.
 *   `departure_date` when you would FLY. The third axis, and the one
 *                    docs/BUSINESS-LOGIC.md §3 warns about mixing with the
 *                    other two.
 *
 * `expires_at` IS STORED RATHER THAN COMPUTED FROM `discovered_at` because the
 * READ has to filter on it. `where expires_at > now()` is an index range scan;
 * `where discovered_at > now() - interval '36 hours'` is the same question
 * spelled as arithmetic the planner has to do per row, with the interval
 * duplicated into every caller and into the prune. One column, one truth, and
 * retuning `orbit.discovery.expires_after_hours` changes what NEW rows promise
 * without silently rewriting what the existing ones already claimed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discoveries', function (Blueprint $table): void {
            $table->id();

            /*
             * THE AIRPORTS ARE REAL ROWS AND THE ROUTE IS NOT — see the
             * docblock. `restrictOnDelete` rather than `cascadeOnDelete`:
             * airports come from a checked-in snapshot and are never deleted in
             * normal operation, so a delete that would orphan a discovery is a
             * seeder doing something surprising and should say so rather than
             * quietly taking rows with it.
             */
            $table->foreignId('origin_airport_id')->constrained('airports')->restrictOnDelete();
            $table->foreignId('destination_airport_id')->constrained('airports')->restrictOnDelete();

            /* `AMS-AGP` — the hand-off into the existing lookup flow. */
            $table->string('code', 7);

            $table->date('departure_date');
            $table->unsignedInteger('price_cents');

            /*
             * THE RANKING NUMBER, STORED, WHICH IS THE ONE DERIVED VALUE IN THIS
             * SCHEMA THAT EARNS A COLUMN.
             *
             * It is price ÷ great-circle distance, and both inputs are here or
             * one join away — so by the usual rule it should be computed on
             * read. Three things override that. The API SORTS on it, and sorting
             * on an expression that needs a two-airport join and a haversine in
             * SQL is a query no index can help. The distance depends on
             * `airports.lat`/`lng`, which a seeder can correct — and a
             * discovery's €/km must go on meaning what it meant when the row was
             * written and the verdict was earned. And it is what the row was
             * SELECTED for: recomputing it later would let the stored ordering
             * and the stored reason drift apart.
             *
             * CENTS PER KILOMETRE AS A FLOAT, not a rounded integer. The whole
             * spread of interest is 10.8 to 30.0 millieuros per kilometre — i.e.
             * 0.0108 to 0.030 of a cent — so any integer unit small enough to
             * order by would be a unit nobody can read.
             */
            $table->double('cents_per_km');

            /*
             * WHERE THIS FARE SAT IN ITS OWN WINDOW, 0 to 100 — or NULL when the
             * verification stage could not fetch one.
             *
             * NULLABLE BECAUSE THE HONEST ANSWER IS SOMETIMES "WE DID NOT FIND
             * OUT". A provider that failed mid-run leaves the candidate ranked
             * on the sweep alone, and the card then says less rather than
             * something it cannot support.
             */
            $table->double('percentile')->nullable();

            /*
             * WHAT THE FINALIST SAVES AGAINST THE MIDDLE OF ITS OWN WINDOW, in
             * cents. Nullable for the same reason `percentile` is. It is stored
             * rather than recomputed because the window it was measured against
             * is gone by the time anybody reads the row — that is the point of
             * a cross-sectional statistic — so the number is the only surviving
             * evidence for the claim the card makes.
             */
            $table->unsignedInteger('savings_cents')->nullable();

            /*
             * GOOGLE'S SECOND OPINION, or NULL when it was not asked.
             *
             * JSON, and the whole verdict rather than a boolean: `{level,
             * lowest, typical_low, typical_high, confirmed}`. `confirmed` is
             * derivable from the rest and is stored anyway — the badge reads it,
             * and a screen that recomputed a verdict from parts is how two
             * places come to disagree about one fare. It is the same argument
             * App\Http\Resources\AlertResource makes for reading a stored
             * payload rather than today's statistics.
             *
             * NULL IS "NOT ASKED OR WOULD NOT SAY", AND IT IS THE ORDINARY CASE.
             * No SERPAPI_KEY, quota under the reserve, a run past its cap, a
             * thin route Google has no `price_insights` for — all null, all
             * fine, none of them an error. What must never happen is a row
             * claiming a verdict nobody obtained, which is why this is nullable
             * and has no default.
             */
            $table->json('google_verdict')->nullable();

            /* The provider's own age for the price. Null means NOT KNOWN. */
            $table->timestamp('found_at')->nullable();

            /* When Orbit decided. The newest-set sort key. */
            $table->timestamp('discovered_at');

            /* When it stops being shown — see the docblock for why it is stored. */
            $table->timestamp('expires_at');

            $table->timestamps();

            /*
             * ONE LIVE ROW PER ROUTE AND DEPARTURE DATE, WHICH IS THE UPSERT KEY.
             *
             * The same candidate surviving two consecutive runs must UPDATE
             * rather than accumulate — its price will have moved, its verdict
             * will have been re-earned, and two cards for the same flight is the
             * feature looking broken. Departure date is in the key because the
             * same route genuinely can be discovered twice for different days,
             * and those are two different offers.
             *
             * It also serves the `code` prefix, which is how the prune finds a
             * route's superseded rows.
             */
            $table->unique(['code', 'departure_date']);

            /*
             * THE READ. `GET /api/discoveries` is "everything still live,
             * cheapest per kilometre first", so the filter column leads and the
             * sort column follows — which lets one index answer both halves.
             */
            $table->index(['expires_at', 'cents_per_km']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discoveries');
    }
};
