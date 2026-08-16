<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The second lane: which argument a discovery is making, and the memory that
 * lets it make the new one.
 *
 * TWO CHANGES IN ONE MIGRATION BECAUSE THEY ARE ONE FEATURE. The column without
 * the table is a label nothing can earn — the relative lane cannot select a
 * candidate without a remembered baseline — and the table without the column is
 * a measurement nothing reads. Rolling back half of this would leave the job
 * writing a lane it cannot compute.
 *
 * =============================================================================
 * 1. `discoveries.lane` — WHICH CLAIM THIS ROW IS MAKING
 * =============================================================================
 * `absolute`  "€18 to Vilnius is a steal, period." Ranked by what a kilometre
 *             buys, against every other fare in the sweep.
 * `relative`  "€60 to Dublin is a steal FOR DUBLIN." Measured against what that
 *             route itself usually costs.
 *
 * A STRING AND NOT A BOOLEAN, and not because a third lane is planned — there
 * is no third lane planned. `is_relative` would be a column whose meaning is
 * "not the other one", and the day anything is added it becomes a column that
 * has to be read alongside a second column to know what a row is. The enum that
 * backs it is App\Domain\Discovery\Lane.
 *
 * DEFAULT `absolute`, WHICH IS WHAT MAKES THIS A ONE-LINE DEPLOY. Every row
 * already in the table was found by the €/km funnel, so the default is not a
 * placeholder — it is the correct value for all of them, and the backfill is
 * therefore nothing at all. The table's steady state is about ten rows with a
 * 36-hour life, so even a wrong default would have aged out by the next
 * afternoon; it is right anyway.
 *
 * NOT IN THE UNIQUE KEY. `(code, departure_date)` stays exactly as it was: one
 * live row per route and departure, whichever lane found it. A route that both
 * lanes could claim is ONE offer and must be one card — the job resolves which
 * lane gets it before the upsert (absolute wins, since it is the stronger claim)
 * rather than letting the database hold two rows for one flight.
 *
 * =============================================================================
 * 2. `discovery_baselines` — WHAT A ROUTE USUALLY COSTS
 * =============================================================================
 * ⚠ WHY THIS IS NOT `calendar_fares`, WHICH IS THE OBVIOUS PLACE FOR IT.
 *
 * `calendar_fares.route_id` is a foreign key to `routes`, so writing a window
 * there means MINTING A `routes` ROW for every route this lane explores — and
 * the discoveries table's own migration exists partly to refuse that. Three
 * things break at once:
 *
 *   - `routes` is the table `POST /api/watchlist` and the rule engine treat as
 *     "pairs this app knows about", and this lane would add a few speculative
 *     rows to it every night;
 *   - `POST /api/routes/lookup` answers 201 when it creates the pair, which is
 *     what "look before you watch" is built on and what the e2e journey asserts
 *     when it taps a discovery — pre-creating the row turns that into a 200;
 *   - `App\Application\Rules\RuleMatches` reads `calendar_fares` for any route
 *     code a rule names, and a rule match feeds §10, WHICH SENDS MAIL. §16 says
 *     discovery never interrupts anybody, and a table this lane writes into
 *     silently becoming an input to the alert pipeline is exactly the kind of
 *     coupling that sentence forbids.
 *
 * So the memory is discovery's own, and it is the smallest thing that works: not
 * a window per route, just the ONE NUMBER the lane reads plus the two facts that
 * say whether it may be trusted. A window per route would be `calendar_fares`
 * rebuilt under another name, with a pruning problem — nothing would ever
 * re-poll these routes, so the cells would age silently forever.
 *
 * ONE ROW PER ROUTE CODE, OVERWRITTEN. A baseline is a current belief, not a
 * history: nothing plots how Dublin's usual price moved, and keeping that would
 * be a second `route_price_history` for routes nobody watches.
 *
 * NO FOREIGN KEYS AT ALL, WHICH IS THE SAME CALL `discoveries.code` MAKES. The
 * code is the identity the whole app navigates on, and a baseline is a fact
 * about a PAIR rather than about a row in `routes` — which is precisely the row
 * this design refuses to create.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discoveries', function (Blueprint $table): void {
            /*
             * SEVEN CHARACTERS FITS 'absolute'... it does not, and that is worth
             * a line: `absolute` is eight. The column is sized for the enum's
             * longest case with room, not for the route code next to it.
             */
            $table->string('lane', 16)->default('absolute')->after('code');
        });

        Schema::create('discovery_baselines', function (Blueprint $table): void {
            $table->id();

            /* `AMS-DUB` — App\Models\Route::codeFor's spelling. */
            $table->string('code', 7)->unique();

            /*
             * THE MEDIAN OF THE ROUTE'S OWN NEAR WINDOW, IN CENTS — the number
             * the lane divides by. It is a median rather than a mean for the
             * reason SelfStatsProvider and CandidateScorer::median both give: a
             * fare distribution has a long right tail and a mean would inflate
             * every discount this lane claims.
             */
            $table->unsignedInteger('median_cents');

            /*
             * HOW MANY PRICED DEPARTURE DATES THAT MEDIAN CAME FROM, AND IT IS
             * NOT DECORATION. Travelpayouts' calendar coverage is patchy and
             * these are obscure pairs, so a window can come back with four
             * dates; a "usual price" built on four numbers is not one.
             * `orbit.discovery.lanes.relative.min_baseline_days` is the floor,
             * and a baseline under it is treated as ABSENT — which puts the
             * route back in the exploration pool to be re-measured, rather than
             * disqualifying it forever.
             */
            $table->unsignedSmallInteger('sample_days');

            /*
             * WHEN IT WAS MEASURED. A baseline is a measurement and measurements
             * expire — routes reprice when a carrier arrives or a season turns,
             * and a discount computed against a median from the spring is
             * arithmetic against a number that stopped being true. The staleness
             * rule is in config; this is the fact it reads.
             */
            $table->timestamp('measured_at');

            $table->timestamps();

            /*
             * THE READ IS BY CODE AND THE `unique()` ABOVE ALREADY INDEXES IT,
             * so there is no second index here. The job fetches every baseline
             * it might need in one `whereIn` per run over a table whose steady
             * state is hundreds of rows.
             */
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_baselines');

        Schema::table('discoveries', function (Blueprint $table): void {
            $table->dropColumn('lane');
        });
    }
};
