<?php

declare(strict_types=1);

namespace App\Application\Routes;

use App\Jobs\PollRoutePrices;
use App\Jobs\RefreshRouteStats;
use App\Models\CalendarFare;
use App\Models\Route;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Date;

/**
 * How old this route's fares are, and fetching new ones when somebody is
 * waiting for them.
 *
 * WHY THIS EXISTS AT ALL. Every fare in Orbit is gathered by the 06:10 poll,
 * which only ever looks at routes on the watchlist (docs/BUSINESS-LOGIC.md §4)
 * — so a route nobody watches has no prices, and the screen that shows it has
 * nothing to draw. That was fine while the only way to reach a route was to
 * watch it first; `POST /api/routes/lookup` is the owner asking to see a price
 * BEFORE making that commitment, and this class is the "then fetch it now" the
 * question implies.
 *
 * THE FRESHNESS RULE IS ONE NUMBER, `orbit.lookup.fresh_for_hours`, and it is
 * asked of the CALENDAR rather than of the route: `calendar_fares.fetched_at`
 * is stamped by App\Jobs\PollRoutePrices on every upsert, so the newest of them
 * is the last time anybody — the morning poll, a rule sweep, or a lookup —
 * asked the provider about this pair. Nothing new has to be recorded to know
 * that.
 *
 * AND ONE THING THAT CANNOT BE ASKED OF THE CALENDAR: whether we asked and got
 * NOTHING. Travelpayouts serves a cache of other people's searches and an empty
 * answer is a real answer (see the adapter) — it writes no rows, so a pair with
 * no fares would read as "stale" forever and be re-fetched, six or seven
 * provider calls at a time, on every single view of the screen. So the ATTEMPT
 * is remembered in the cache for the same window, and that memory is what the
 * second view reads.
 *
 * `add()` RATHER THAN `has()` + `put()`, atomically, for the same reason the
 * fare adapter's warning uses it: two views of the same route arriving together
 * must produce one fetch, not two. It doubles as the stampede guard.
 *
 * SYNCHRONOUS, AND THAT IS THE POINT. The two jobs below are the same ones
 * `POST /api/watchlist` queues; run inline they cost the request one provider
 * round trip per calendar month of the poll window (six or seven), which is the
 * one to three seconds the lookup screen shows "Checking current fares…" for.
 * Queueing them instead would answer with an empty route and no way for the
 * person looking at it to know when to look again — which is exactly the state
 * a watched route is allowed to be in for one morning and a looked-up one is
 * not, because nobody is coming back to it tomorrow.
 *
 * WHAT BOUNDS THE WAIT is the provider's own timeouts (`orbit.travelpayouts`:
 * 5 s to connect, 15 s to read, one retry) — a hung provider is therefore
 * minutes rather than forever, which is far too long for a person and is why
 * the client aborts its own request first and says so. The writes are upserts,
 * so a fetch whose answer nobody is waiting for any more still leaves the fares
 * behind for the next view.
 */
final readonly class FareFreshness
{
    public function __construct(private Cache $cache) {}

    /**
     * When the provider was last asked about this route, as far as the calendar
     * knows. Null when it has never answered with anything.
     */
    public function lastFetchedAt(Route $route): ?CarbonImmutable
    {
        /*
         * The ROW rather than `max('fetched_at')`, so the model's cast is what
         * turns the column into a date. `max()` hands back whatever the driver
         * stores — a string on SQLite, a timestamp on Postgres — and parsing
         * that by hand is one more place the two databases can disagree.
         */
        return CalendarFare::query()
            ->where('route_id', $route->id)
            ->orderByDesc('fetched_at')
            ->first(['fetched_at'])
            ?->fetched_at;
    }

    /**
     * Whether fares fetched at that moment are still worth showing without
     * asking again. Takes the timestamp rather than the route so a caller that
     * has already read it — every caller that publishes it — does not pay for
     * the query twice.
     */
    public function isFresh(?CarbonImmutable $fetchedAt): bool
    {
        if ($fetchedAt === null) {
            return false;
        }

        return $fetchedAt->greaterThanOrEqualTo(
            Date::now()->subHours(self::hours())->toImmutable(),
        );
    }

    /**
     * Price this route now, unless somebody already has.
     *
     * @return bool whether the provider was actually called — the caller needs
     *              it for nothing, and the tests need it for everything
     */
    public function refreshIfStale(Route $route): bool
    {
        if ($this->isFresh($this->lastFetchedAt($route))) {
            return false;
        }

        // See the class docblock: this is both the "we already asked and got
        // nothing" memory and the guard against two simultaneous views paying
        // for the same fetch twice.
        if (! $this->cache->add(self::key($route), true, self::hours() * 3600)) {
            return false;
        }

        /*
         * THE FULL POLL WINDOW, not a cheaper slice of it. `price.current` is
         * defined as the cheapest fare in the next six months (docs/API.md) and
         * the calendar screen pages across all of it — a route fetched three
         * months deep would look cheaper or dearer than a watched one for no
         * reason a reader could see, and its calendar would end in the middle.
         *
         * `dispatchSync` runs the job's handle() through the container here and
         * now. It is not a queued dispatch that happens to be fast: nothing is
         * serialised, nothing retries, and an exception is this request's.
         */
        PollRoutePrices::dispatchSync($route->id);

        /*
         * AND WHAT IT USUALLY COSTS, in the same breath. `self` statistics are
         * computed out of the two tables the poll above has just written, so
         * this is local arithmetic rather than a second provider call — and
         * without it the screen would draw a fare with no "usual €93" to
         * compare it to, which is the one number that makes a price mean
         * anything.
         */
        RefreshRouteStats::dispatchSync($route->id);

        return true;
    }

    private static function key(Route $route): string
    {
        return 'orbit:lookup:'.$route->code;
    }

    private static function hours(): int
    {
        return (int) config('orbit.lookup.fresh_for_hours');
    }
}
