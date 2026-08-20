<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Airport;
use Illuminate\Http\JsonResponse;
use App\Http\Resources\DestinationOptionResource;

/**
 * Everywhere Orbit knows how to fly to — the add-route form's typeahead.
 *
 * The whole list in one request, not `?q=` search: 184 destinations from two checked-in seed files is a few KB, so
 * filtering in the browser beats a round trip (docs/BUSINESS-LOGIC.md §36).
 *
 * The other 3,086 airports aren't in here — they have no `destinations` row and are searched one at a time via
 * AirportController instead (docs/BUSINESS-LOGIC.md §36).
 *
 * What counts as a destination is the `destinations` table, not `airports` — so an origin like Amsterdam is never
 * offered as a flight to itself (docs/BUSINESS-LOGIC.md §36).
 *
 * Not a validation list: AddWatchedRouteRequest still accepts any `airports` code, deliberately, so narrowing this
 * dropdown can't break a URL somebody typed (docs/BUSINESS-LOGIC.md §36).
 */
final class DestinationController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // One query, four columns, no eager load: `destinations` carries nothing this list prints (vibes/warmth belong to the
        // rule parser), so it's an `exists` check only (docs/BUSINESS-LOGIC.md §36).
        $airports = Airport::query()
            ->whereHas('destination')
            ->orderBy('city')
            ->get(['iata', 'city', 'country', 'country_code']);

        return DestinationOptionResource::collection($airports)
            ->additional(['meta' => ['count' => $airports->count()]])
            ->response()
            // Private, not shared: a shared cache holding an authenticated response is how one account's answer reaches another,
            // even though the content is the same for everyone (docs/BUSINESS-LOGIC.md §36).
            ->header('Cache-Control', 'private, max-age=3600');
    }
}
