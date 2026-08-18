<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Run by the `scheduler` container (`php artisan schedule:work`, see
| docker-compose.yml). The fare entries are FAN-OUT COMMANDS that queue one job
| per route onto redis, where Horizon picks them up — the scheduler's job is to
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
 * THE FAR MONTHS, ONCE A WEEK — Orbit maintains eleven months of calendar
 * (`orbit.poll.horizon_days`) and the entry above fetches the near six of them
 * every morning. This is the run that fills in months 7 to 11.
 *
 * WEEKLY BECAUSE A FARE ELEVEN MONTHS OUT MOVES ON AN AIRLINE'S TIMETABLE, not
 * on this morning's cache churn — and because nothing but the calendar screen
 * reads those cells. No deal score, no alert threshold and no statistic is
 * computed from them (config/orbit.php: `poll.window_days` and
 * `selfstats.cross_section_days` are both the near window), so a far month that
 * is six days old costs a person nothing but a price that has moved a little.
 *
 * 04:10 AND NOT 06:10, WHICH IS ENTIRELY ABOUT THE REQUEST BUDGET. This run
 * costs twelve provider calls per watched route where the daily poll costs
 * seven, and Travelpayouts allows ~200 an hour per IP. In the 06:00 hour, next
 * to the rule sweep's 120, it would be 9 × 12 + 120 = 228 and over the limit; in
 * an otherwise empty 04:00 hour it is 108, and the ordinary morning below is
 * left exactly as it was. The whole table is in config/orbit.php's `poll`
 * section, including the watchlist size at which the ORDINARY morning breaches.
 *
 * WHICH DAY IS CONFIGURED, not fixed: `orbit.poll.far_refresh_weekday`, 0 for
 * Sunday through 6 for Saturday, exactly as weeklyOn() reads it. Saturday,
 * because eleven months out is holiday planning and holiday planning happens at
 * a weekend.
 *
 * IT DOES NOT REPLACE THAT MORNING'S POLL and must not be made to: the daily
 * entry still runs on the far day, four hours later, and both write the same
 * day's observation from the same near window (App\Jobs\PollRoutePrices bounds
 * it deliberately). Two idempotent upserts, in a fixed order, for 63 requests a
 * week — which is the price of never having a Saturday whose history row means
 * something different from every other day's.
 */
Schedule::command('orbit:poll-fares --far')
    ->weeklyOn((int) config('orbit.poll.far_refresh_weekday'), '04:10')
    ->timezone($timezone)
    ->withoutOverlapping();

/*
 * 04:40 — ROUND TRIPS, AND THE HOUR WAS PICKED BEFORE THERE WAS AN ENTRY TO PUT
 * IN IT. config/orbit.php's `returns` section did the arithmetic when the table
 * shipped unscheduled; this is that recommendation, taken.
 *
 * ONE REQUEST PER WATCHED ROUTE, FLAT — nine today — because
 * `/v2/prices/latest` with `period_type=year` answers for the whole horizon in a
 * single call, where the one-way calendar is billed per calendar month and costs
 * seven a morning or twelve on the far run. Against Travelpayouts' ~200 an hour
 * per IP:
 *
 *     06:00 hour   poll 63 + sweep 120 = 183, + 9 = 192   ⚠ 8 requests of room
 *     04:00 hour   far poll 108 (Saturdays)  , + 9 = 117  ← this one
 *
 * The 06:00 hour is the one that breaks first as the watchlist grows (config/
 * orbit.php: at twelve watched routes it is already 204 without this), so the
 * nine go where there is room rather than where the other polls are.
 *
 * 04:40 AND NOT EARLIER IN THAT HOUR, which is about the per-minute limit rather
 * than the hourly one. Saturday's far poll fans out nine jobs at
 * config('orbit.poll.stagger_minutes'), so it is still queueing until 04:34;
 * starting at 04:20 would interleave two fan-outs and hand the provider two
 * bursts in the same minutes, which is the exact thing the stagger exists to
 * prevent. 04:40 begins after the last far job is away. Six mornings a week the
 * hour is empty anyway.
 *
 * ITS OWN FAN-OUT ENDS AT 05:04 — nine jobs at a three-minute stagger — which is
 * sixteen minutes before `orbit:discover` at 05:20, so the two runs sharing the
 * 05:00 hour are sequential rather than overlapping. Two of the nine requests
 * land in that hour; config/orbit.php's `poll` section has what it costs.
 *
 * WHY IT IS SCHEDULED NOW, WHEN App\Console\Commands\PollReturns SAID THE PR
 * THAT ADDS THE FIRST READER WOULD ADD THIS. Nothing reads `return_fares` yet
 * and that part has not changed. What changed is that the polling has been
 * happening every morning regardless, driven by a cron OUTSIDE this repository —
 * so the provider calls the old decision was protecting are already being spent,
 * and the only thing the outside runner adds is a way for the accumulation to
 * stop on a box nobody is watching, silently, in the weeks before the screens
 * land. The argument that moved is about WHO HOLDS THE CLOCK, not about the
 * budget. This entry puts it in the stack that is deployed, tested and reviewed;
 * the external runner is deleted when this ships.
 *
 * withoutOverlapping() as everywhere else here: two runs writing the same
 * (route, departure date, nights) at once would race on the upsert.
 */
Schedule::command('orbit:poll-returns')
    ->dailyAt('04:40')
    ->timezone($timezone)
    ->withoutOverlapping();

/*
 * 05:20 — THE SURPRISE, and the only entry in this file that goes looking for
 * routes nobody has named.
 *
 * `orbit:discover` sweeps the three home airports for everywhere they currently
 * have a cheap cached fare to, ranks about a thousand of them by what a
 * kilometre costs, and then VERIFIES the best five against their own calendars
 * and — if there is SerpAPI quota — against Google. What survives lands in
 * `discoveries` for the search screen's "Deals from your airports" strip.
 *
 * THE 05:00 HOUR, AND IT IS ENTIRELY ABOUT THE REQUEST BUDGET — the same
 * argument the far poll's 04:10 makes. A run is 3 sweep requests plus 5 × ≤7
 * verification requests = 38, against Travelpayouts' ~200 an hour per IP. In
 * the 06:00 hour, next to the poll's 63 and the rule sweep's 120, it would be
 * 221 and over the limit; in the otherwise empty 05:00 hour it is 38, and the
 * ordinary morning below is left exactly as it was. On the far morning the
 * three runs sit in three separate clock hours — 04:10, 05:20, 06:10 — so the
 * worst hour of the week is unchanged by this feature. config/orbit.php's
 * `poll` section carries the whole table.
 *
 * 05:20 AND NOT 05:40, because Monday's `orbit:refresh-stats` is at 05:40 and
 * sharing a clock hour is only comfortable if the two do not overlap. The
 * stats refresh costs no provider requests at all (`ORBIT_STATS_PROVIDER=self`
 * reads Orbit's own tables), so the concern is worker contention rather than
 * rate limit — and twenty minutes is comfortable room for a sequential run of
 * forty requests.
 *
 * IT IS SCHEDULED IN THE PR THAT ADDS IT, WHICH `orbit:poll-returns` WAS NOT.
 * That was deliberate there and this is deliberate here: returns filled a table
 * with no readers, and this ships with `GET /api/discoveries` and the screen
 * that draws it, so the requests buy something the owner sees tomorrow morning.
 * (There IS a returns entry above now, at 04:40, and NOT because a reader
 * arrived — read that block. It is a different argument, about a cron outside
 * this repository already spending the calls.)
 *
 * AND IT CANNOT WAKE ANYBODY UP. Discovery surfaces, it never interrupts — no
 * mail, no notification, nothing in `alerts` (docs/BUSINESS-LOGIC.md §16). The
 * worst a bad run can do is put a disappointing card on a screen somebody chose
 * to open, which is what makes scheduling the least-verified data in the app
 * a safe thing to do at all.
 *
 * withoutOverlapping() because a run that took longer than a day would
 * otherwise have a second one spending the SerpAPI budget on top of it.
 */
Schedule::command('orbit:discover')
    ->dailyAt('05:20')
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
