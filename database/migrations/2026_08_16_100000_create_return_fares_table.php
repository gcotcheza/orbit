<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cheapest ROUND-TRIP fare for each (departure date, stay length).
 *
 * THE THIRD FARE TABLE, AND IT ANSWERS A QUESTION THE OTHER TWO CANNOT.
 * `calendar_fares` is indexed by the day you FLY OUT and holds a one-way price;
 * `route_price_history` is indexed by the day we LOOKED. Neither can be made to
 * answer "what does a week in Lisbon in March cost", because a one-way fare
 * pinned to a departure date is a fare for a PAIR of dates with the second one
 * hidden — and on long-haul the hidden half is most of the money: measured on
 * 2026-08-16, the cheapest one-way was 60% (AMS-LIS), 69% (AMS-JFK) and 58%
 * (AMS-BKK) of the cheapest return.
 *
 * A SEPARATE TABLE RATHER THAN A NULLABLE `nights` ON `calendar_fares`, for the
 * same reason those two are separate from each other: the unique key is
 * different (a round-trip has two date facts, a one-way has one), the retention
 * rule is different (see below), and every existing read of `calendar_fares`
 * would have had to grow a `where nights is null` clause it could forget.
 *
 * =============================================================================
 * `nights` IS STORED AND `return_date` IS NOT
 * =============================================================================
 * They are the same fact twice — nights is `return - departure`, the return
 * date is `departure + nights` — so exactly one of them may be a column or they
 * will disagree eventually. Nights is the one that earns it:
 *
 *   - IT IS THE AXIS EVERY QUERY USES. "6 to 8 nights" is what a person asks
 *     and what `deal_rules`' `tripLengthNights` has parsed since the rules
 *     engine shipped; nobody has ever asked for a return date directly.
 *   - IT INDEXES AS AN ORDINARY INTEGER. `where nights between 6 and 8` uses
 *     the index below. The same question asked of a return date is a
 *     subtraction of two dates — an EXPRESSION index, spelled differently on
 *     Postgres and on SQLite, which are both databases this app runs on.
 *   - THE DERIVATION BACK IS EXACT. App\Domain\Pricing\ReturnTrip::returnDate()
 *     is `departure->modify('+N days')`, and a screen that wants to print "out
 *     Fri 3, back Mon 6" calls it.
 *
 * UNSIGNED SMALL INTEGER, WHICH IS ALSO THE FLOOR AT ZERO. A same-day return is
 * a real fare (three of the 198 entries recorded on 2026-08-16 had one), so 0
 * is legal; a NEGATIVE stay is a return leg before its outbound and is corrupt,
 * which the column type refuses at the same moment ReturnTrip's constructor
 * does. The ceiling is `orbit.returns.max_nights` in the adapter, not here —
 * the longest real stay recorded was 56 nights (AMS-BKK).
 *
 * ONE ROW PER (route, departure_date, nights), OVERWRITTEN ON EVERY POLL, which
 * is `calendar_fares`' rule exactly: Orbit does not keep the history of what a
 * March fortnight looked like in August. Nothing in the design asks that
 * question and keeping it would be a few hundred rows per route per day forever
 * for a chart nobody has drawn.
 *
 * RETENTION IS ONE RULE AND NOT TWO, WHICH IS THE ONE PLACE THIS TABLE IS
 * SIMPLER THAN `calendar_fares`. That table is polled at two speeds — the near
 * six months every morning, months 7 to 11 once a week — so it needs two
 * staleness clocks (`poll.stale_after_days` and `poll.far_stale_after_days`)
 * and App\Jobs\PollRoutePrices prunes it in two passes. This one is fetched in
 * ONE request covering the WHOLE horizon (`period_type=year`; see
 * App\Infrastructure\Pricing\TravelpayoutsReturnProvider), so every row in it is
 * always exactly as fresh as every other row, and `orbit.returns.
 * stale_after_days` is the only clock there is. App\Jobs\PollReturnFares prunes
 * departures that have gone by, departures past the maintained horizon, and
 * rows the provider has stopped quoting — three deletes, one clock.
 *
 * `found_at` IS NULLABLE AND MEANS "the age is not known", never "fresh", and
 * it is emphatically not `fetched_at` under another name. See the
 * `add_found_at_to_calendar_fares` migration for the €36-that-was-really-€56
 * this distinction was bought with. It matters MORE here: `/v2/prices/latest`
 * serves a SEVEN-DAY-deep cache, so a round-trip fare is routinely days old
 * where a month-matrix fare is often hours old.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_fares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->date('departure_date');
            $table->unsignedSmallInteger('nights');
            $table->unsignedInteger('price_cents');
            $table->timestamp('fetched_at');
            $table->timestamp('found_at')->nullable();
            $table->timestamps();

            /*
             * THE PROVIDER'S OWN GRAIN, AND THEREFORE THE UPSERT KEY.
             * `/v2/prices/latest` returns one entry per (depart_date,
             * return_date) and they were unique in every recording — 119 of
             * 119, 56 of 56, 338 of 338 — so this constraint is the table
             * agreeing with the API rather than a rule imposed on it.
             *
             * It also serves the `route_id` and `(route_id, departure_date)`
             * prefixes, which is what the prunes in App\Jobs\PollReturnFares
             * and a "what can I do leaving that week" read scan on.
             */
            $table->unique(['route_id', 'departure_date', 'nights']);

            /*
             * THE OTHER WAY ROUND, FOR THE QUESTION THE SCREENS WILL ACTUALLY
             * ASK. "A week away in the next three months" filters on the stay
             * length FIRST and the departure date second, which the unique
             * index above cannot serve — its leading date column would have to
             * be scanned for every candidate week. Duration-band reads are the
             * whole reason this table exists, so they get their own index
             * rather than a plan that works while the table is small.
             */
            $table->index(['route_id', 'nights', 'departure_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_fares');
    }
};
