<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Route;
use Illuminate\Http\Request;
use App\Models\WatchlistItem;
use Illuminate\Http\JsonResponse;
use App\Application\Routes\WatchedRoute;
use App\Application\Routes\RouteSnapshots;
use App\Http\Resources\WatchlistRouteResource;

/**
 * Everything the owner is watching — it feeds three screens, which is why the list carries
 * full summaries. ORDER IS THE OWNER'S, from `watchlist_items.position`.
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
                'count'  => $watched->count(),
                'active' => $watched->filter(fn (WatchedRoute $route): bool => $route->active)->count(),
            ]])
            ->response();
    }
}
