<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Everything Orbit has ever decided to tell the owner — the alert ledger.
 *
 * IT IS THE COOLDOWN, and that is its first job. App\Domain\Alerts\AlertPolicy
 * refuses to mention the same route twice inside
 * config('orbit.alerts.cooldown_hours') unless the price has fallen a further
 * few percent, and both halves of that sentence are read from here: `triggered_at`
 * says when, `price_cents` says how much. A cooldown held in memory or in the
 * cache would forget itself on the first deploy and mail the owner about every
 * route they have ever watched.
 *
 * IT IS ALSO THE HISTORY, which is why `payload` exists. `GET /api/alerts` and
 * the Sunday digest both read rows that may be months old, and a row that only
 * pointed at a route would answer "AMS-OPO, €44" with today's numbers rather
 * than the ones the mail actually quoted. The payload is what was SAID, frozen:
 * the price, the usual price, the percentage under it, the departure date, and
 * — for a rule — the chips the rule was reduced to that morning.
 *
 * TRIGGERED AND DELIVERED ARE TWO COLUMNS, not one, because quiet hours make
 * them genuinely different moments: a deal found at 06:55 inside a 22:00–08:00
 * window is decided now and delivered at 08:00. The cooldown runs from the
 * DECISION — otherwise the quiet window would silently stretch it by however
 * long somebody was asleep — and `delivered_at` stays null until a channel
 * confirms (App\Infrastructure\Notify\MarkAlertsDelivered), so a row with a
 * trigger and no delivery is either mail in flight or mail switched off. Both
 * are things worth being able to see.
 *
 * BOTH FOREIGN KEYS ARE NULLABLE AND NEITHER CASCADES.
 *   - `route_id` is null on the weekly digest, which is about no route in
 *     particular.
 *   - `deal_rule_id` is null on everything but a rule match — and it is
 *     `nullOnDelete` because docs/API.md promises that deleting a rule leaves
 *     the routes and fares it surfaced alone. Erasing the history of what a
 *     deleted rule once found would be the same mistake: the mail was sent, and
 *     a ledger that rewrote itself when a question was withdrawn would be a
 *     record of nothing.
 *
 * NO UNIQUE KEY ON (user, route, type, day). Two alerts about one route on one
 * day is exactly what the 5%-drop rule is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('deal_rule_id')->nullable()->constrained()->nullOnDelete();

            /*
             * route_deal | rule_match | weekly_digest — App\Domain\Alerts\
             * AlertType, which App\Models\Alert casts this column to. A string
             * and not a native enum type for the reason `user_settings.
             * sensitivity` is an int: adding a kind (a push-only nudge, a price
             * -drop-only alert) must not need a migration on a table that by
             * then holds a year of history.
             */
            $table->string('type', 32);

            /*
             * The deal score at the moment of the decision, 0-100. NULL on a
             * rule match and on the digest, because neither has one: a rule's
             * threshold is its own maximum price and the digest is not a
             * judgement at all. A zero here would read as "scored terribly".
             */
            $table->unsignedSmallInteger('score')->nullable();

            /* Cents, like every other price in this app. NULL on the digest. */
            $table->unsignedInteger('price_cents')->nullable();

            /* Everything the mail showed. See the note above. */
            $table->json('payload');

            /*
             * `mail` today. It is a column rather than an assumption because
             * docs/PLAN.md has web push after the PWA shell, and the day a
             * second channel exists "did this deal already go out" becomes a
             * question with a per-channel answer.
             */
            $table->string('channel', 32);

            $table->timestamp('triggered_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            /*
             * THE COOLDOWN'S INDEX, in the order the question is asked: this
             * account, this route, this kind, recently.
             */
            $table->index(['user_id', 'route_id', 'type', 'triggered_at']);

            /*
             * AND THE LEDGER'S OWN. `GET /api/alerts` and the digest's
             * "this week" callout both want one account's rows newest first,
             * with no route in the question — a query the index above cannot
             * serve past its first column, because `route_id` sits between.
             */
            $table->index(['user_id', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
