<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * `POST /api/routes/lookup` — price a pair without committing to it.
 *
 * EVERY RULE IT HAS IS RoutePairRequest'S, AND THAT IS THE WHOLE CLASS. The one
 * rule it deliberately does NOT inherit is AddWatchedRouteRequest's "you are
 * already watching AMS-LIS": looking at a route is not adding it, and a screen
 * that refused to show a price for something already on the list would be
 * answering a question nobody asked. The endpoint touches no watchlist row
 * either way (docs/API.md).
 *
 * IT EXISTS RATHER THAN THE CONTROLLER TAKING THE ABSTRACT ONE because a form
 * request is resolved by its class name — an abstract parent cannot be — and
 * because the next rule this endpoint needs, if it ever needs one, belongs
 * here and not on the write that shares its fields.
 */
final class LookupRouteRequest extends RoutePairRequest {}
