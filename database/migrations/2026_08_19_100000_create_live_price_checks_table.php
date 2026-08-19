<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * What Google said about ONE route on ONE date, when somebody asked it to.
 *
 * =============================================================================
 * WHY A ROW AT ALL, WHEN THE ANSWER IS SHOWN AND THEN THE SCREEN IS CLOSED
 * =============================================================================
 * Because the answer cost a metered search out of 250 a MONTH, and a number
 * that expensive must not be thrown away when a phone locks. Three things read
 * it, in order of how soon they exist:
 *
 *   1. THE COOLDOWN. A second tap on the same route and date inside
 *      `orbit.live_check.cooldown_hours` is served from here and spends
 *      nothing. Without a row, a person tapping twice pays twice.
 *   2. THE NEXT VIEW. Re-opening the screen inside the same window shows the
 *      live figure it already has rather than a button offering to buy it
 *      again.
 *   3. ALERTS, LATER. `App\Domain\Alerts\AlertPolicy` already holds a mail
 *      whose fare is stale near departure; the obvious next question is whether
 *      anybody has CHECKED that fare live, and this is where the answer will
 *      be. Nothing reads it that way today and this table does not pretend
 *      otherwise.
 *
 * A TABLE RATHER THAN THE CACHE STORE, for the reason `discoveries` gives in
 * its own migration and one more: `cache:clear` is a routine deploy step, and
 * it must not be able to make somebody's phone spend a search it already spent.
 *
 * =============================================================================
 * IT HAS A `route_id`, WHERE `discoveries` DELIBERATELY HAS NONE
 * =============================================================================
 * The opposite decision to that table's, and from the same reasoning. A
 * discovery is a pair nobody watches and Orbit has usually never priced, so
 * manufacturing `routes` rows for five of them a night would be a nightly job
 * inventing routes. A live check can only ever be made FROM THE DETAIL SCREEN
 * of a route that is already there — `GET /api/routes/{code}` 404s otherwise,
 * and `POST /api/routes/lookup` is what creates the row before this screen can
 * be drawn at all. The foreign key is free here, and it is what makes
 * `cascadeOnDelete` right: a route that goes takes its checks with it, because
 * a check about a route that no longer exists answers no question.
 *
 * =============================================================================
 * ONE ROW PER ROUTE AND DEPARTURE DATE — THE LATEST ANSWER, NOT A LOG
 * =============================================================================
 * The unique key is the upsert key, exactly as it is on `calendar_fares` and
 * `discoveries`. What every reader above wants is the MOST RECENT answer for
 * that flight; a log would need a "newest" query on every view and would grow a
 * row per tap forever for a feature whose whole point is that it is asked
 * rarely. When the cooldown has expired and somebody checks again, the row is
 * overwritten and `checked_at` moves — which is the honest record of "what
 * Orbit last verified, and when".
 *
 * THE DEPARTURE DATE IS IN THE KEY because a route's cheapest departure moves:
 * a check of the 12th says nothing about the 19th, and serving one for the
 * other from the cooldown would be this app's oldest mistake — a real number,
 * about a different flight — with a "checked live" label on it.
 *
 * =============================================================================
 * A ROW EXISTS EVEN WHEN GOOGLE SAID NOTHING, AND THAT IS THE POINT
 * =============================================================================
 * `google_verdict` is NULLABLE and null means "asked, no usable answer" — a
 * thin route with no `price_insights` block, a timeout, a 429. SerpAPI counted
 * that search either way (App\Jobs\DiscoverDeals says the same thing about its
 * own budget), so the row is written anyway and the cooldown applies to it. A
 * schema that only recorded successes would let one silent route be re-asked
 * every six hours forever.
 *
 * WHAT IS NEVER STORED IS A VERDICT NOBODY OBTAINED. No default, no
 * placeholder: absent means absent, and App\Http\Resources\LivePriceResource
 * draws nothing from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_price_checks', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('route_id')->constrained()->cascadeOnDelete();

            /* The day you would FLY — the axis docs/BUSINESS-LOGIC.md §3 warns
               about mixing with the two below. */
            $table->date('departure_date');

            /*
             * WHEN ORBIT ASKED GOOGLE. Not when Google found the fare — Google's
             * live answer has no `found_at` and needs none, which is the entire
             * difference between this table and `calendar_fares`. The cooldown
             * is measured from here and the screen's "checked 2 hours ago" is
             * this column read aloud.
             */
            $table->timestamp('checked_at');

            /*
             * GOOGLE'S ANSWER, in the shape App\Domain\Discovery\GoogleVerdict
             * already publishes and `discoveries.google_verdict` already stores:
             * `{level, lowest, typical_low, typical_high, confirmed}`, cents.
             *
             * THE SAME SHAPE ON PURPOSE. It is one value object, one adapter and
             * one set of keys; a second spelling of "what Google said" would be
             * the thing that drifts the first time the check client is retuned.
             */
            $table->json('google_verdict')->nullable();

            $table->timestamps();

            /*
             * THE UPSERT KEY AND THE READ, and they are the same two columns —
             * every reader of this table asks "what do we have for THIS route on
             * THIS date", one row, by unique index.
             */
            $table->unique(['route_id', 'departure_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_price_checks');
    }
};
