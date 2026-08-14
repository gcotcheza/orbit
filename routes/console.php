<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Run by the `scheduler` container (`php artisan schedule:work`, see
| docker-compose.yml). Both entries are FAN-OUT COMMANDS that queue one job per
| route onto redis, where Horizon picks them up — the scheduler's job is to
| decide WHEN, and nothing that talks to a rate-limited third party should be
| running inside the process that keeps the clock.
|
| EVERY TIME IS EUROPE/AMSTERDAM, from config('orbit.timezone'). The app stores
| UTC and always will, but "06:10" is a statement about the owner's morning,
| and without the timezone it would drift an hour twice a year — polling at
| 08:10 local through the summer, which is after they have looked at their
| phone.
|
| THIS FILE IS LOADED ON EVERY ARTISAN COMMAND, including `migrate` against an
| empty database. That is why the schedule names commands rather than
| enumerating routes: a query here would run before the tables it reads exist.
|
*/

$timezone = (string) config('orbit.timezone');

/*
 * 06:10 — before the owner is awake, after the airlines' overnight fare loads
 * have settled. The command staggers the per-route jobs by
 * config('orbit.poll.stagger_minutes') so the providers see a trickle rather
 * than a burst.
 *
 * withoutOverlapping() is belt-and-braces on a single scheduler container: a
 * poll that somehow ran long must not have a second one started on top of it,
 * because both would write the same day's observation.
 */
Schedule::command('orbit:poll-fares')
    ->dailyAt('06:10')
    ->timezone($timezone)
    ->withoutOverlapping();

/*
 * Monday 05:40, ahead of that morning's poll so the week's scores are read
 * against the week's statistics.
 *
 * WEEKLY BECAUSE THE ANSWER IS MONTHLY. A route's usual price is built from
 * months of fares; asking daily would spend rate limit to move a median by a
 * euro, and the deal score is deliberately most sensitive to this number —
 * which is an argument for it being stable, not for it being fresh.
 */
Schedule::command('orbit:refresh-stats')
    ->weeklyOn(1, '05:40')
    ->timezone($timezone)
    ->withoutOverlapping();
