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

/*
 * 06:40 — AFTER the watchlist poll above, and that ordering is the whole
 * reason for the gap rather than a guess at how long a poll takes.
 *
 * A rule is about routes nobody is watching (design/README.md §4), so this is
 * where Orbit finds out what "somewhere sunny in spring" currently costs. It
 * runs second because App\Jobs\SweepRuleFares deliberately skips any route the
 * morning already priced: sweeping first would spend the rule's capped budget
 * re-fetching the watchlist, and the routes only a rule cares about would
 * never get their turn.
 *
 * Half an hour is comfortable room for 06:10's staggered fan-out — six routes
 * at config('orbit.poll.stagger_minutes') is fifteen minutes — and the skip
 * makes an overlap merely wasteful rather than wrong.
 */
Schedule::command('orbit:sweep-rules')
    ->dailyAt('06:40')
    ->timezone($timezone)
    ->withoutOverlapping();

/*
 * 06:55 — LAST OF THE THREE, and that ordering is the entire point of the time.
 *
 * This is the run that decides what is worth an alert (App\Jobs\EvaluateAlerts)
 * and it talks to no provider at all: every fare it reads was written by the
 * 06:10 poll and the 06:40 sweep. Running it first would not fail, which is
 * what makes the ordering worth stating — it would simply mail this morning's
 * verdict on yesterday's prices, every day, invisibly.
 *
 * Fifteen minutes after the sweep, which queues one capped fan-out of polls per
 * rule. A rule whose polls have not landed yet costs a matching route one day's
 * delay in being noticed, and never a wrong alert: nothing here invents a fare
 * it cannot see.
 *
 * ALERTS ARE STILL DECIDED DURING QUIET HOURS — they are DELIVERED after them.
 * The ledger records the decision at 06:55 and the notification is delayed to
 * the end of the window (App\Application\Alerts\DeliveryWindow), so a cooldown
 * measures from when the deal was found rather than from when somebody woke up.
 */
Schedule::command('orbit:alerts')
    ->dailyAt('06:55')
    ->timezone($timezone)
    ->withoutOverlapping();

/*
 * Sunday 09:00, which is docs/PLAN.md's and is a statement about a weekend
 * morning rather than about a time — hence the timezone, like everything else
 * in this file.
 *
 * LATER THAN THE WEEKDAY RUNS ON PURPOSE. Every other entry here is scheduled
 * to be finished before the owner is awake; this one is meant to be read over
 * coffee, and it is the only mail Orbit sends that nothing crossed a threshold
 * to earn.
 */
Schedule::command('orbit:digest')
    ->weeklyOn(0, '09:00')
    ->timezone($timezone)
    ->withoutOverlapping();

/*
 * 03:10 — the quietest hour, and nowhere near the morning's runs above.
 *
 * NOT A FAN-OUT, unlike its neighbours: this one does its own work, and its
 * work is a manifest read and a handful of unlinks. It has no reason to queue
 * behind anything.
 *
 * WHY IT IS ON THE SCHEDULE AT ALL, when the deploy runs it straight after the
 * asset build: `vite.config.js` sets `emptyOutDir: false`, which turns a
 * forgotten deploy step from "the pruning did not happen" into "the disk fills
 * up". A daily run means the worst case is a day of extra chunks. It is
 * idempotent — a run with no new build re-reads the same manifest, finds its
 * snapshot already recorded, and deletes nothing that is still referenced.
 */
Schedule::command('build:retain')
    ->dailyAt('03:10')
    ->timezone($timezone)
    ->withoutOverlapping();
