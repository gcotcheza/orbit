<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Discovery;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;
use App\Http\Resources\DiscoveryResource;

/**
 * The current set of discoveries — "the insanely cheap routes you never
 * thought to watch".
 *
 * Pure read of a precomputed table; all the expensive work (sweeps, window fetches, Google searches) happens at 05:20
 * in App\Jobs\DiscoverDeals.
 *
 * No parameters: nothing to page (~10 rows, orbit.discovery.max_rows bounded), filter, or sort (order IS the ranking —
 * see Discovery::scopeLive).
 *
 * Empty `data: []` is a real, common answer — every orbit.discovery threshold is a floor, not a quota, precisely so
 * this can happen.
 *
 * Behind auth:sanctum like every other read; rows aren't sensitive, but this API has one auth rule and no exception
 * worth carving out (docs/BUSINESS-LOGIC.md §16).
 */
final class DiscoveryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /*
         * Owner's clock, not UTC — matches App\Jobs\DiscoverDeals. `live` compares departure DATE to today; UTC would hide
         * today's discovery for a reader still on yesterday's date locally (docs/BUSINESS-LOGIC.md §16).
         */
        $now = Date::now((string) config('orbit.timezone'))->toImmutable();

        $discoveries = Discovery::query()
            /*
             * Eager-load: resource reads both origin and destination per row, so lazy loading would be an N+1 on a list meant to
             * render in one paint (docs/BUSINESS-LOGIC.md §16).
             */
            ->with(['origin', 'destination'])
            ->live($now)
            ->get();

        return DiscoveryResource::collection($discoveries)
            ->additional(['meta' => [
                'count' => $discoveries->count(),

                /*
                 * "Found this morning", not "checked when opened"; null on an empty set is the honest "we did not find anything"
                 * answer.
                 *
                 * Owner-timezone timestamp, like meta.fares.fetchedAt — every other date here is a bare YYYY-MM-DD (a day, not a
                 * moment) (docs/BUSINESS-LOGIC.md §16).
                 */
                'discoveredAt' => $discoveries
                    ->max('discovered_at')
                    ?->setTimezone((string) config('orbit.timezone'))
                    ->toIso8601String(),
            ]])
            ->response();
    }
}
