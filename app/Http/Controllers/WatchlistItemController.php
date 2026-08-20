<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Route;
use Illuminate\Http\Request;
use App\Jobs\PollRoutePrices;
use App\Models\WatchlistItem;
use App\Jobs\RefreshRouteStats;
use Illuminate\Http\JsonResponse;
use App\Application\Routes\WatchedRoute;
use Illuminate\Database\Eloquent\Builder;
use App\Application\Routes\RouteSnapshots;
use App\Http\Requests\AddWatchedRouteRequest;
use App\Http\Resources\WatchlistRouteResource;
use App\Http\Requests\UpdateWatchedRouteRequest;

/**
 * Adding, pausing and dropping a watched route (design/README.md §5).
 * Separate from WatchlistController (the tuned read stays untouched by writes).
 * Every write answers in the list's own row shape (WatchlistRouteResource), so
 * the screen replaces rather than re-fetches. Keyed on route code, not row id —
 * the client already has `code` for every row.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final class WatchlistItemController extends Controller
{
    /**
     * Start watching a pair. 201, with the route's summary as it stands — for
     * a new one that's `confident: false` and no prices until the jobs below run.
     */
    public function store(AddWatchedRouteRequest $request, RouteSnapshots $snapshots): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // The lookup rather than the check — see App\Http\Requests\RoutePairRequest,
        // which both this write and the route lookup take their pair from.
        $origin = $request->airport('origin');
        $destination = $request->airport('destination');

        // Find or create: a route is a fact about the world, not a possession — reusing an existing row hands back price history already paid for.
        // Why: docs/BUSINESS-LOGIC.md §36.
        $route = Route::query()->firstOrCreate(
            ['code' => Route::codeFor($origin->iata, $destination->iata)],
            [
                'origin_airport_id'      => $origin->id,
                'destination_airport_id' => $destination->id,
            ],
        );

        $item = WatchlistItem::query()->create([
            'user_id'  => $user->id,
            'route_id' => $route->id,
            'active'   => true,
            // Onto the end of the owner's order. `-1` so the first route added
            // to an empty list gets position 0, like the seeder's.
            'position' => (int) ($user->watchlistItems()->max('position') ?? -1) + 1,
        ]);

        // Queued, not synchronous: the tap should get a row back now, not after two round trips to the provider. The row renders "no opinion yet" until the poll lands.
        // Why: docs/BUSINESS-LOGIC.md §36.
        PollRoutePrices::dispatch($route->id);
        RefreshRouteStats::dispatch($route->id);

        return $this->present($route, $item->active, $snapshots, 201);
    }

    /**
     * Pause or resume. The row, its history and its position all stay.
     */
    public function update(UpdateWatchedRouteRequest $request, string $code, RouteSnapshots $snapshots): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $item = self::item($user, $code);
        $item->update(['active' => $request->boolean('active')]);

        return $this->present($item->route, $item->active, $snapshots, 200);
    }

    /**
     * Stop watching. 204 — there is nothing left to describe.
     * The route and its history survive — only the watchlist row goes; nothing else in the app treats an unwatched route as deleted.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    public function destroy(Request $request, string $code): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        self::item($user, $code)->delete();

        return new JsonResponse(status: 204);
    }

    /**
     * This account's watchlist row for a route code, or a 404 that says so.
     * Scoped to the user, not merely filtered by code — "whose is it" must never be assumed on a write.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    private static function item(User $user, string $code): WatchlistItem
    {
        // See RouteController for why abort() rather than firstOrFail(): the
        // framework's own 404 body names an internal class.
        return $user->watchlistItems()
            ->whereHas('route', function (Builder $route) use ($code): void {
                /** @var Builder<Route> $route */
                $route->where('code', $code);
            })
            ->first() ?? abort(404, 'Not watching that route.');
    }

    private function present(Route $route, bool $active, RouteSnapshots $snapshots, int $status): JsonResponse
    {
        $watched = new WatchedRoute($snapshots->of($route), $active);

        return WatchlistRouteResource::make($watched)
            ->response()
            ->setStatusCode($status);
    }
}
