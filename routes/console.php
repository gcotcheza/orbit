<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled work, run by the `scheduler` container. Fan-out only, and routes are referenced by
 * command name, never queried here (docs/BUSINESS-LOGIC.md §13).
 */

$timezone = (string) config('orbit.timezone');

/*
 * 06:10 — before the owner is awake. withoutOverlapping(): a long poll must not race a second
 * one writing the same day's observation (docs/BUSINESS-LOGIC.md §13).
 */
Schedule::command('orbit:poll-fares')
    ->dailyAt('06:10')
    ->timezone($timezone)
    ->withoutOverlapping();

/*
 * Weekly far refresh (months 7-11). 04:10 Saturday is about the ~200/hr provider budget, and it
 * does not replace that morning's 06:10 poll (docs/BUSINESS-LOGIC.md §27).
 */
Schedule::command('orbit:poll-fares --far')
    ->weeklyOn((int) config('orbit.poll.far_refresh_weekday'), '04:10')
    ->timezone($timezone)
    ->withoutOverlapping();

/*
 * 04:40 — round trips, one request per watched route. Not 04:20: Saturday's far-poll fan-out is
 * still queueing until 04:34 (docs/BUSINESS-LOGIC.md §28).
 */
Schedule::command('orbit:poll-returns')
    ->dailyAt('04:40')
    ->timezone($timezone)
    ->withoutOverlapping();

/*
 * 05:20 — surfaces only: no mail, no alert, ever. Not 05:40, to avoid Monday's
 * `orbit:refresh-stats` at that time (docs/BUSINESS-LOGIC.md §16).
 */
Schedule::command('orbit:discover')
    ->dailyAt('05:20')
    ->timezone($timezone)
    ->withoutOverlapping();

/*
 * Monday 05:40, ahead of that morning's poll so the week's scores read against the week's
 * stats. Weekly because the answer is monthly (docs/BUSINESS-LOGIC.md §13).
 */
Schedule::command('orbit:refresh-stats')
    ->weeklyOn(1, '05:40')
    ->timezone($timezone)
    ->withoutOverlapping();

/*
 * 06:40 — after the watchlist poll on purpose: `SweepRuleFares` skips any route the morning
 * already priced, so sweeping first would waste its capped budget (docs/BUSINESS-LOGIC.md §13).
 */
Schedule::command('orbit:sweep-rules')
    ->dailyAt('06:40')
    ->timezone($timezone)
    ->withoutOverlapping();

/*
 * 06:55 — last of the three: it reads only what 06:10/06:40 wrote. Running it first would
 * silently mail yesterday's prices; delivered after quiet hours (docs/BUSINESS-LOGIC.md §10).
 */
Schedule::command('orbit:alerts')
    ->dailyAt('06:55')
    ->timezone($timezone)
    ->withoutOverlapping();

/*
 * Sunday 09:00 — later than the weekday runs on purpose (meant to be read over coffee), and the
 * only mail Orbit sends that nothing crossed a threshold to earn (docs/BUSINESS-LOGIC.md §13, §10).
 */
Schedule::command('orbit:digest')
    ->weeklyOn(0, '09:00')
    ->timezone($timezone)
    ->withoutOverlapping();

/*
 * 03:10, the quietest hour — a safety net for `emptyOutDir: false`: a forgotten deploy step
 * means the disk fills up, not that pruning did not happen (docs/BUSINESS-LOGIC.md §13).
 */
Schedule::command('build:retain')
    ->dailyAt('03:10')
    ->timezone($timezone)
    ->withoutOverlapping();
