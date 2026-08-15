<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trip somebody described in English, and what this app read out of it.
 *
 * BOTH HALVES ARE STORED, and neither is derivable from the other:
 *
 *   - `raw_text` is what was typed. It is what the create screen puts back in
 *     the textarea when a rule is edited, it is the only record of what the
 *     owner actually meant, and it is the input a better parser would be
 *     replayed against. A rule that kept only the criteria could never be
 *     re-read by the model once a key exists.
 *   - `criteria` is what was understood, AFTER the owner removed any chips
 *     they disagreed with. It is what the matcher runs on, and it is not
 *     `parse(raw_text)`: the whole point of design/README.md §4's chips is
 *     that a person can correct the reading, and re-parsing the text on load
 *     would throw that correction away every time.
 *
 * `criteria` IS JSON AND NOT SIX COLUMNS. Nothing queries inside it — the
 * matcher loads a rule and answers in PHP (App\Domain\Rules\RuleMatcher) —
 * and the shape is a value object that has already changed once during this
 * PR. Six nullable columns would be six migrations the first time a criterion
 * gains a field. The same reasoning `destinations.vibes` is stored under.
 *
 * NO UNIQUE KEY ON (user_id, raw_text). Two rules with the same words but
 * different chips removed are two different rules, and a person re-typing a
 * sentence they already have is entitled to; the list is theirs to prune.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('raw_text');
            $table->json('criteria');

            /*
             * Paused rules stay on the list, dimmed, exactly like paused
             * watchlist rows — the switch that brings one back is on the row
             * it turned off.
             */
            $table->boolean('active')->default(true);
            $table->timestamps();

            /* The one query this table serves: this account's rules, newest first. */
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_rules');
    }
};
