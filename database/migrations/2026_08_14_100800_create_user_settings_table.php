<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * How and when the owner wants to be told (design/README.md §6).
 *
 * A SEPARATE TABLE RATHER THAN COLUMNS ON `users`. `users` is the framework's
 * table — it is what Auth, the session guard and Sanctum read, and it is the
 * one table a Laravel upgrade may want to add a column to. Alert preferences
 * are the product's, they will grow (per-channel quiet hours, digest day), and
 * they are read by the alert engine on a schedule rather than on every
 * authenticated request. Keeping them apart means the hot path stays narrow
 * and the growth happens somewhere it costs nothing.
 *
 * ONE ROW PER ACCOUNT, enforced by the unique on `user_id` rather than by
 * convention: this is a HasOne, and a second row would make "the settings"
 * mean whichever one the database returned first.
 *
 * THE DEFAULTS IN THIS FILE ARE THE ONLY COPY. App\Models\UserSettings::for()
 * creates the row with no attributes and re-reads it, so the values below are
 * what a brand-new account gets — there is no second list of defaults in a
 * model, a seeder or a config file to drift from them. They are docs/PLAN.md's:
 * email on (the only channel that works before the PWA lands), push off until
 * a device has actually subscribed, the Sunday digest on, quiet hours on
 * 22:00-08:00, and sensitivity 0 = Relaxed, i.e. only the insane deals.
 *
 * `sensitivity` IS AN INT AND NOT AN ENUM COLUMN. It is an ordered scale —
 * 0 quietest to 2 loudest — and the score it maps to lives in
 * config/orbit.php (`alerts.sensitivities` -> `score.tiers`), because that
 * threshold is a tuning decision and must not need a migration to change.
 * The range is enforced by App\Http\Requests\UpdateSettingsRequest, which
 * validates against the config'd list, so adding a fourth level is one line of
 * config rather than a check constraint plus a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->boolean('email_alerts')->default(true);
            $table->boolean('push_alerts')->default(false);
            $table->boolean('weekly_digest')->default(true);

            $table->boolean('quiet_hours')->default(true);
            /*
             * WALL-CLOCK TIMES IN THE OWNER'S ZONE (config('orbit.timezone')),
             * not UTC. "No pings after ten at night" is a statement about the
             * bedside table, and storing it in UTC would move it by an hour
             * every March and October. Nothing else in this app stores local
             * time; this is the exception and it is the right one.
             *
             * The defaults are written with seconds because Postgres normalises
             * a `time` to `HH:MM:SS` on the way out while SQLite hands back the
             * string it was given — the same engine difference that bit
             * App\Jobs\PollRoutePrices. Both are trimmed to `HH:MM` in exactly
             * one place: UserSettings::quietStartAt()/quietEndAt().
             */
            $table->time('quiet_start')->default('22:00:00');
            $table->time('quiet_end')->default('08:00:00');

            $table->unsignedSmallInteger('sensitivity')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
