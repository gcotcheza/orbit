<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Airport;
use Illuminate\Http\JsonResponse;
use App\Http\Resources\DestinationOptionResource;

/**
 * Everywhere Orbit knows how to fly to — the whole list in one request, keyed off the
 * `destinations` table, and never a validation list (docs/BUSINESS-LOGIC.md §36).
 */
final class DestinationController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // One query, four columns, no eager load: `destinations` carries nothing this list
        // prints, so it is an `exists` check only (docs/BUSINESS-LOGIC.md §36).
        $airports = Airport::query()
            ->whereHas('destination')
            ->orderBy('city')
            ->get(['iata', 'city', 'country', 'country_code']);

        return DestinationOptionResource::collection($airports)
            ->additional(['meta' => ['count' => $airports->count()]])
            ->response()
            // Private, not shared: a shared cache holding an authenticated response is how one
            // account's answer reaches another.
            ->header('Cache-Control', 'private, max-age=3600');
    }
}
