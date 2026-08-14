<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Routes\RouteSnapshots;
use App\Application\Routes\WatchedRoute;
use App\Http\Resources\WatchlistRouteResource;
use App\Models\Route;
use App\Models\User;
use App\Models\WatchlistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything the owner is watching — the app's busiest endpoint.
 *
 * IT FEEDS THREE SCREENS, not one. The globe home draws its arcs, its route
 * rail and its spotlight card entirely from this response (which is why the
 * airports carry lat/lng), the watchlist screen draws its rows from it, and
 * both do so without a second request per route. That is the reason the list
 * carries the full summary rather than ids to follow up on.
 *
 * ORDER IS THE OWNER'S, from `watchlist_items.position` — see the relation on
 * App\Models\User. The globe's auto-tour steps through this array in order, so
 * a list that re-sorted itself between two loads would make the tour visit
 * routes in a different order each time the app was opened.
 */
final class WatchlistController extends Controller
{
    public function __invoke(Request $request, RouteSnapshots $snapshots): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $items = $user->watchlistItems()->get();

        $routes = Route::query()
            ->whereIn('id', $items->pluck('route_id')->all())
            ->with(['origin', 'destination', 'stats'])
            ->get();

        $built = $snapshots->for($routes);

        $watched = $items
            ->map(fn (WatchlistItem $item): WatchedRoute => new WatchedRoute($built[$item->route_id], $item->active))
            ->values();

        return WatchlistRouteResource::collection($watched)
            ->additional(['meta' => [
                'count' => $watched->count(),
                'active' => $watched->filter(fn (WatchedRoute $route): bool => $route->active)->count(),
            ]])
            ->response();
    }
}
