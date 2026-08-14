<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per route per day: the cheapest fare anywhere in the poll window
 * that morning.
 *
 * THIS IS THE ONE TABLE NOBODY CAN SELL US. Statistics providers say what a
 * route usually costs; only our own accruing observations say which way it is
 * moving right now, which is the whole difference between "cheap, book it" and
 * "cheap and still falling, wait". It is also why docs/PLAN.md has the
 * "tracking N days" honesty note — a chart drawn from six points must say so.
 *
 * `observed_on` IS A DATE, NOT A TIMESTAMP, and unique per route. That
 * uniqueness is what makes the poller idempotent: running it twice on a bad
 * morning overwrites the day's figure instead of putting a second point on the
 * sparkline and bending the trend.
 *
 * IT IS THE OWNER'S DATE, not UTC's. The poll runs at 06:10 Europe/Amsterdam,
 * which in summer is 04:10 UTC and on the same calendar day either way — but
 * a retry at 00:30 local would be the PREVIOUS day in UTC and would overwrite
 * yesterday. App\Jobs\PollRoutePrices resolves the date in config('orbit
 * .timezone') for exactly that reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_price_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->date('observed_on');
            $table->unsignedInteger('price_cents');
            $table->timestamps();

            $table->unique(['route_id', 'observed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_price_history');
    }
};
