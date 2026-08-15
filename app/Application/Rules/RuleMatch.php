<?php

declare(strict_types=1);

namespace App\Application\Rules;

use App\Domain\Pricing\DatedFare;
use App\Models\Route;

/**
 * One trip a rule would fire on: a route, the cheapest departure that fits,
 * and whether it is already on the watchlist.
 *
 * `watched` IS THE ONE FIELD THAT IS NOT ABOUT THE TRIP, and it is here for
 * the same reason App\Application\Routes\WatchedRoute exists: the rules
 * section of the watch screen offers a one-tap "watch" on every match, and a
 * button that adds something already on the list is a button that returns 422.
 * It belongs to the pair (this account, this route) rather than to either.
 *
 * IN Application AND NOT Domain, because it holds the Eloquent model. Same
 * boundary App\Application\Routes\RouteSnapshot draws and for the same reason:
 * the pure matcher (App\Domain\Rules\RuleMatcher) never sees a model, and this
 * is the layer that is allowed to.
 */
final readonly class RuleMatch
{
    public function __construct(
        public Route $route,
        public DatedFare $cheapest,
        public bool $watched,
    ) {}
}
