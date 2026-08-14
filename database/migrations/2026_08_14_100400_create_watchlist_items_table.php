<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The routes the owner asked to be told about.
 *
 * USER-SCOPED EVEN THOUGH THERE IS ONE USER. The column costs nothing today
 * and is the difference between "add a second account" being a migration and
 * being a rewrite of every query in the app. Scribly learned this the
 * expensive way (see the multi-user retrofit); Orbit starts with the column.
 *
 * `active` IS A PAUSE, NOT A DELETE. The design's toggle (§5) turns a row off
 * without removing it: polling and alerts stop, but the history already
 * gathered stays, so turning the route back on in March does not throw away
 * February. The API returns paused rows with `active: false` rather than
 * hiding them, which is what lets the watchlist screen draw the toggle at all.
 *
 * `position` is what "the user's watchlist order" means; without it the list
 * would re-order itself on any query whose sort the database is free to choose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'route_id']);
            $table->index(['user_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist_items');
    }
};
