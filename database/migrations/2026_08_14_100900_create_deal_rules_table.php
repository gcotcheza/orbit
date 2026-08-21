<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * A trip somebody described in English, and what this app read out of it. Both `raw_text` and
 * `criteria` are stored, and neither is derivable from the other (docs/BUSINESS-LOGIC.md §11).
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

            /* Paused rules stay on the list, dimmed — like paused watchlist
               rows, the switch to bring one back is on the row it turned off. */
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
