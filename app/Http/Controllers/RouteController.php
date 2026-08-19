<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Route;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Application\Routes\FareFreshness;
use App\Application\Routes\RouteSnapshot;
use App\Http\Requests\LookupRouteRequest;
use App\Http\Resources\LivePriceResource;
use App\Application\Routes\RouteSnapshots;
use App\Application\Routes\LivePriceChecks;
use App\Http\Resources\RouteDetailResource;

/**
 * One route, in full (design/README.md §2). Keyed on the code because
 * `/route/AMS-LIS` has to be bookmarkable; the two writes are POSTs because a
 * GET that spends metered provider calls is one a link preview will spend.
 */
final class RouteController extends Controller
{
    public function show(Request $request, string $code, RouteSnapshots $snapshots, FareFreshness $freshness, LivePriceChecks $liveChecks): JsonResponse
    {
        $route = self::find($code);

        return $this->present($request, $route, $snapshots->of($route), $freshness, $liveChecks, 200);
    }

    /**
     * Price a pair the owner has not committed to. The route is found or created
     * and the watchlist is untouched (docs/BUSINESS-LOGIC.md §1).
     */
    public function lookup(LookupRouteRequest $request, RouteSnapshots $snapshots, FareFreshness $freshness, LivePriceChecks $liveChecks): JsonResponse
    {
        $origin = $request->airport('origin');
        $destination = $request->airport('destination');

        $route = Route::query()->firstOrCreate(
            ['code' => Route::codeFor($origin->iata, $destination->iata)],
            [
                'origin_airport_id'      => $origin->id,
                'destination_airport_id' => $destination->id,
            ],
        );

        $created = $route->wasRecentlyCreated;

        $freshness->refreshIfStale($route);

        return $this->present($request, $route, $snapshots->of($route), $freshness, $liveChecks, $created ? 201 : 200);
    }

    /**
     * ⚠ The only tap in Orbit that spends a SerpAPI search, and the date is the
     * server's — the cheapest departure this document is showing.
     *
     * The two 503s are different facts, never a 200 (docs/API.md).
     */
    public function liveCheck(
        Request $request,
        string $code,
        RouteSnapshots $snapshots,
        FareFreshness $freshness,
        LivePriceChecks $liveChecks,
    ): JsonResponse {
        $route = self::find($code);
        $snapshot = $snapshots->of($route);
        $departure = $snapshot->cheapest?->departureDate;

        if ($departure === null) {
            abort(409, 'Orbit has no fare for this route to check.');
        }

        $result = $liveChecks->check($route, $departure);

        if ($result->check === null) {
            abort(503, $result->budgetRefused
                ? 'Orbit is holding its remaining live checks in reserve.'
                : 'Orbit could not reach Google just now. Nothing was spent — try again in a moment.');
        }

        return $this->present($request, $route, $snapshot, $freshness, $liveChecks, 200);
    }

    /* `abort()` rather than `firstOrFail()`, whose body names an internal class. */
    private static function find(string $code): Route
    {
        return Route::query()
            ->where('code', $code)
            ->with(['origin', 'destination', 'stats'])
            ->first() ?? abort(404, 'Unknown route.');
    }

    /**
     * The detail document, plus the two facts that are about this REQUEST rather
     * than about the route — `data` is the summary four screens share.
     */
    private function present(
        Request $request,
        Route $route,
        RouteSnapshot $snapshot,
        FareFreshness $freshness,
        LivePriceChecks $liveChecks,
        int $status,
    ): JsonResponse {
        $fetchedAt = $freshness->lastFetchedAt($route);
        $departure = $snapshot->cheapest?->departureDate;

        /* Read on every view, not only after the button: an answer paid for at
           lunchtime is what this screen shows at teatime. */
        $liveCheck = $departure === null ? null : $liveChecks->latest($route, $departure);

        return RouteDetailResource::make($snapshot, $liveCheck)
            ->additional(['meta' => [
                'watched'   => self::isWatched($request, $route),
                'liveCheck' => $liveCheck === null ? null : LivePriceResource::make($liveCheck)->toArray($request),

                'fares' => [
                    /* The only timestamp in this API — every other date names a
                       DAY. This one names a moment. */
                    'fetchedAt' => $fetchedAt?->setTimezone((string) config('orbit.timezone'))->toIso8601String(),
                    'fresh'     => $freshness->isFresh($fetchedAt),
                ],
            ]])
            ->response()
            ->setStatusCode($status);
    }

    private static function isWatched(Request $request, Route $route): bool
    {
        /** @var User|null $user */
        $user = $request->user();

        return $user !== null && $user->watchlistItems()->where('route_id', $route->id)->exists();
    }
}
