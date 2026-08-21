<?php

declare(strict_types=1);

namespace App\Application\Routes;

/**
 * A snapshot plus the one thing that belongs to the WATCHLIST ROW rather than the route.
 * Kept off RouteSnapshot: a route has no opinion about being watched.
 */
final readonly class WatchedRoute
{
    public function __construct(
        public RouteSnapshot $snapshot,
        public bool $active,
    ) {}
}
