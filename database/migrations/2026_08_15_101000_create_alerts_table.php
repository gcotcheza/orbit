<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Everything Orbit has ever decided to tell the owner — the alert ledger.
 * Backs AlertPolicy's cooldown and the alert history (see per-column notes).
 * FKs are nullable/nullOnDelete — deleting a rule must not erase its alert
 * history. No unique key on (user, route, type, day): the 5%-drop rule can
 * fire twice in one day, on purpose.
 * Why: docs/BUSINESS-LOGIC.md §36.
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

            // route_deal | rule_match | weekly_digest (App\Domain\Alerts\AlertType). String, not a native enum: adding a kind must not need a migration on a table holding a year of history.
            // Why: docs/BUSINESS-LOGIC.md §36.
            $table->string('type', 32);

            // Score at the moment of decision, 0-100; NULL on a rule match or digest (neither has one) — a zero would read as "scored terribly".
            // Why: docs/BUSINESS-LOGIC.md §36.
            $table->unsignedSmallInteger('score')->nullable();

            /* Cents, like every other price in this app. NULL on the digest. */
            $table->unsignedInteger('price_cents')->nullable();

            /* Everything the mail showed, frozen — a months-old row must answer with the numbers actually sent, not today's. */
            $table->json('payload');

            // `mail` today; a column, not an assumption — PLAN.md has web push after the PWA shell, and multi-channel needs a per-channel "did this go out" answer.
            // Why: docs/BUSINESS-LOGIC.md §36.
            $table->string('channel', 32);

            $table->timestamp('triggered_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            /*
             * THE COOLDOWN'S INDEX, in the order the question is asked: this
             * account, this route, this kind, recently.
             */
            $table->index(['user_id', 'route_id', 'type', 'triggered_at']);

            // The ledger's own index: `GET /api/alerts` and the digest want one account's rows newest-first with no route in the question — the cooldown index above can't serve that past its first column.
            // Why: docs/BUSINESS-LOGIC.md §36.
            $table->index(['user_id', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
