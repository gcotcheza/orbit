<?php

declare(strict_types=1);

namespace App\Application\Routes;

use App\Models\Route;
use Carbon\CarbonImmutable;
use App\Models\CalendarFare;
use App\Jobs\PollRoutePrices;
use App\Jobs\RefreshRouteStats;
use Illuminate\Support\Facades\Date;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Route freshness and fetch-on-demand for POST /api/routes/lookup: freshness is read off
 * `calendar_fares.fetched_at`, and both jobs run dispatchSync (docs/BUSINESS-LOGIC.md §1).
 */
final readonly class FareFreshness
{
    public function __construct(private Cache $cache) {}

    /**
     * When the provider was last asked about this route, as far as the calendar knows. Null when it
     * has never answered with anything.
     */
    public function lastFetchedAt(Route $route): ?CarbonImmutable
    {
        /**
         * Row, not max('fetched_at'): max() returns a driver-native value that would need
         * hand-parsing, where the model cast handles it.
         */
        return CalendarFare::query()
            ->where('route_id', $route->id)
            ->orderByDesc('fetched_at')
            ->first(['fetched_at'])
            ?->fetched_at;
    }

    /**
     * Whether a fetch at that moment is still fresh. Takes the timestamp rather than the route so a
     * caller that already read it doesn't pay for the query twice.
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

        // Cache add(): remembers "asked and got nothing" and guards a duplicate simultaneous fetch
        // (docs/BUSINESS-LOGIC.md §1).
        if (! $this->cache->add(self::key($route), true, self::hours() * 3600)) {
            return false;
        }

        /**
         * window_days, not horizon_days: a lookup's calls are sequential with somebody waiting.
         * dispatchSync runs handle() inline, so an exception here is this request's.
         */
        PollRoutePrices::dispatchSync($route->id, (int) config('orbit.poll.window_days'));

        /**
         * Self statistics computed from the two tables the poll just wrote — local arithmetic, not
         * a second provider call, or the screen has no "usual €93".
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
