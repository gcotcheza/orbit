<?php

declare(strict_types=1);

namespace App\Application\Routes;

/**
 * A snapshot plus the one thing that belongs to the WATCHLIST ROW rather than
 * to the route: whether the owner has it switched on.
 *
 * Kept off RouteSnapshot deliberately. A route has no opinion about being
 * watched — PR10's rules will produce snapshots for routes nobody watches at
 * all — and a nullable `active` on the snapshot would be a field that means
 * "false" in one screen and "not applicable" in another.
 */
final readonly class WatchedRoute
{
    public function __construct(
        public RouteSnapshot $snapshot,
        public bool $active,
    ) {}
}
