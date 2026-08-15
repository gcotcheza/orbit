<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Routes\RouteSnapshots;
use App\Application\Routes\WatchedRoute;
use App\Http\Requests\AddWatchedRouteRequest;
use App\Http\Requests\UpdateWatchedRouteRequest;
use App\Http\Resources\WatchlistRouteResource;
use App\Jobs\PollRoutePrices;
use App\Jobs\RefreshRouteStats;
use App\Models\Route;
use App\Models\User;
use App\Models\WatchlistItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Adding, pausing and dropping a watched route (design/README.md §5).
 *
 * SEPARATE FROM WatchlistController, which stays the single-action GET it was
 * written as. Splitting reads from writes here is not ceremony: the read is
 * the app's launch request and is tuned for it (four queries for any number of
 * routes), while these three are one-row operations a person triggers by
 * tapping something. Keeping them apart means neither grows the other's
 * concerns, and the read's route declaration does not have to change to add a
 * write.
 *
 * EVERY WRITE ANSWERS WITH THE ROW IN THE LIST's OWN SHAPE
 * (WatchlistRouteResource), so the screen replaces the item it was holding
 * rather than re-fetching the list. That matters most for the optimistic
 * toggle: the response is what the server actually believes, and the row
 * adopts it.
 *
 * KEYED ON THE ROUTE CODE, not on the watchlist row's id. The client already
 * has `code` for every row (docs/API.md calls it "the only id the client
 * needs"), and a URL that reads `/api/watchlist/AMS-LIS` is one somebody can
 * check in a network tab.
 */
final class WatchlistItemController extends Controller
{
    /**
     * Start watching a pair. 201, with the route's summary as it stands —
     * which for a new one is `confident: false` and no prices at all, until
     * the two jobs below have run.
     */
    public function store(AddWatchedRouteRequest $request, RouteSnapshots $snapshots): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // The lookup rather than the check — see App\Http\Requests\RoutePairRequest,
        // which both this write and the route lookup take their pair from.
        $origin = $request->airport('origin');
        $destination = $request->airport('destination');

        /*
         * FIND OR CREATE. A route is a fact about the world, not a possession:
         * the pair may already exist because it was watched and dropped, or
         * because PR10's rules surfaced it. Re-using the row is what hands the
         * owner back the price history they already paid for.
         */
        $route = Route::query()->firstOrCreate(
            ['code' => Route::codeFor($origin->iata, $destination->iata)],
            [
                'origin_airport_id' => $origin->id,
                'destination_airport_id' => $destination->id,
            ],
        );

        $item = WatchlistItem::query()->create([
            'user_id' => $user->id,
            'route_id' => $route->id,
            'active' => true,
            // Onto the end of the owner's order. `-1` so the first route added
            // to an empty list gets position 0, like the seeder's.
            'position' => (int) ($user->watchlistItems()->max('position') ?? -1) + 1,
        ]);

        /*
         * QUEUED, NOT SYNCHRONOUS. Both jobs call a provider, and the person
         * who just tapped "Add route" should get their row back now rather
         * than after two HTTP round trips to Travelpayouts. The screen is
         * built for the gap: the row renders its "no opinion yet" state until
         * the poll lands, which is the same state every genuinely new route
         * has (docs/API.md, day-1 honesty).
         */
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
     *
     * THE ROUTE AND ITS HISTORY SURVIVE, and only the watchlist row goes.
     * Every observation under it was a real morning's fare, it cost a provider
     * call to gather, and adding the pair back next spring should not start
     * from nothing. Nothing else in the app treats an unwatched route as
     * deleted — the detail screen is deliberately not scoped to the watchlist.
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
     *
     * SCOPED TO THE USER, not merely filtered by code. There is one account
     * today; the row this returns is the one about to be written to, and
     * "whose is it" is not a question a write should answer by assuming.
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
