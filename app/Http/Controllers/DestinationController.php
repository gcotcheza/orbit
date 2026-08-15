<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\DestinationOptionResource;
use App\Models\Airport;
use Illuminate\Http\JsonResponse;

/**
 * Everywhere Orbit knows how to fly to — the add-route form's typeahead.
 *
 * WHY THE WHOLE LIST IN ONE REQUEST AND NOT A `?q=` SEARCH. There are a
 * hundred and eighty-four of them and the number is two checked-in files
 * (database/seeders/data/european_destinations.php and world_destinations.php),
 * not a growing table. The entire payload is a few kilobytes, so the form
 * fetches it once when it opens and filters in the browser — which means a
 * suggestion appears on the keystroke rather than after a round trip, and
 * typing "bilb" costs four requests fewer than a search endpoint would. A `?q=`
 * endpoint over 184 rows is a network hop bought with somebody's latency and
 * nothing else.
 *
 * THE OTHER 3,086 AIRPORTS ARE NOT IN HERE, and that is the whole shape of
 * world flights: they have no `destinations` row, no vibes and no warmth, and
 * they are searched one query at a time through
 * App\Http\Controllers\AirportController. Sending them all would be 200 KB
 * before anybody typed anything, for rows the rule engine can never match.
 *
 * WHAT COUNTS AS A DESTINATION IS THE `destinations` TABLE, not `airports`.
 * The three origins (AMS, EIN, DUS) are airports with no destinations row —
 * the seeder's own note says Amsterdam "is also a destination for nobody" —
 * and offering the owner a flight from Amsterdam to Amsterdam is the one
 * suggestion this list must never make.
 *
 * IT IS NOT A VALIDATION LIST. AddWatchedRouteRequest still accepts any code in
 * `airports`, which is broader than this, and deliberately: what a form offers
 * and what an API accepts are two decisions, and narrowing the second one to
 * match a dropdown would break a URL somebody typed. See docs/API.md.
 */
final class DestinationController extends Controller
{
    public function __invoke(): JsonResponse
    {
        /*
         * ONE QUERY, FOUR COLUMNS, AND NO EAGER LOAD. The `destinations` row is
         * what makes an airport a destination and carries nothing this list
         * prints — the vibes and the monthly warmth ratings belong to the rule
         * parser — so it is asked about with an `exists` and never fetched.
         */
        $airports = Airport::query()
            ->whereHas('destination')
            ->orderBy('city')
            ->get(['iata', 'city', 'country', 'country_code']);

        return DestinationOptionResource::collection($airports)
            ->additional(['meta' => ['count' => $airports->count()]])
            ->response()
            /*
             * PRIVATE, because it is behind a session even though its contents
             * are the same for everybody: a shared cache holding an
             * authenticated response is how one account's answer reaches
             * another. An hour is well under the time it takes this list to
             * change, which is a deploy.
             */
            ->header('Cache-Control', 'private, max-age=3600');
    }
}
