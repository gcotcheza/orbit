<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Everything Orbit has ever decided to tell the owner. FKs are nullOnDelete, and there is
 * deliberately no unique key on (user, route, type, day) (docs/BUSINESS-LOGIC.md §10).
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

            // route_deal | rule_match | weekly_digest. A string, not a native enum: adding a kind
            // must not need a migration on a year of history.
            $table->string('type', 32);

            // Score at the moment of decision, 0-100; NULL on a rule match or digest (neither has
            // one) — a zero would read as "scored terribly" (docs/BUSINESS-LOGIC.md §10).
            $table->unsignedSmallInteger('score')->nullable();

            /* Cents, like every other price in this app. NULL on the digest. */
            $table->unsignedInteger('price_cents')->nullable();

            /* Everything the mail showed, frozen — a months-old row answers with what was sent. */
            $table->json('payload');

            // `mail` today; a column, not an assumption — multi-channel needs a per-channel "did
            // this go out" answer.
            $table->string('channel', 32);

            $table->timestamp('triggered_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            /*
             * THE COOLDOWN'S INDEX, in the order the question is asked: this account, this route,
             * this kind, recently.
             */
            $table->index(['user_id', 'route_id', 'type', 'triggered_at']);

            // The ledger's own index: one account's rows newest-first with no route in the
            // question, which the cooldown index cannot serve.
            $table->index(['user_id', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
