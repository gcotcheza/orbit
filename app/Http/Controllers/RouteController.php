<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Routes\FareFreshness;
use App\Application\Routes\RouteSnapshots;
use App\Http\Requests\LookupRouteRequest;
use App\Http\Resources\RouteDetailResource;
use App\Models\Route;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One route, in full (design/README.md §2), and the way to reach one Orbit has
 * never priced.
 *
 * KEYED ON THE CODE, not on the id: the SPA's URL is `/route/AMS-LIS` and a
 * detail screen has to be bookmarkable and shareable, which an autoincrement
 * cannot be. An unknown code is a 404 — and under `/api/` bootstrap/app.php
 * renders that as JSON, which is what the client's fetch() can act on.
 *
 * NOT SCOPED TO THE WATCHLIST. PR10's rules surface routes nobody watches yet
 * and tapping one has to open this screen; the watchlist is a list, not a
 * permission. There is one account, so there is nothing here to scope to.
 *
 * =============================================================================
 * TWO ACTIONS, ONE BODY, AND WHY THE SECOND ONE IS A POST
 * =============================================================================
 * `show()` is a read and costs a handful of queries. `lookup()` answers with
 * exactly the same document, and getting to it may cost a route row, six or
 * seven metered provider calls and two or three seconds of somebody's evening
 * (App\Application\Routes\FareFreshness) — which is a write however it is
 * spelled, and is why it is a POST in the write group, behind CSRF and behind
 * its own throttle, rather than a `?refresh=1` on the read above it. A GET that
 * can spend money is a GET a browser prefetch, a link preview or a retry will
 * eventually spend money on.
 *
 * THEY SHARE `present()` DELIBERATELY. The detail screen adopts a lookup's
 * answer without re-fetching, exactly as the watchlist screen adopts a write's
 * row (WatchlistItemController), so the two bodies are not merely similar —
 * they have to be the same shape or the screen would have two ways to read
 * itself.
 */
final class RouteController extends Controller
{
    public function show(Request $request, string $code, RouteSnapshots $snapshots, FareFreshness $freshness): JsonResponse
    {
        /*
         * `abort()` rather than `firstOrFail()`, whose 404 body is Laravel's
         * "No query results for model [App\Models\Route]" — a message that
         * names an internal class and that the client cannot show a person.
         * The client branches on the status; the string is for a developer
         * reading a network tab.
         */
        $route = Route::query()
            ->where('code', $code)
            ->with(['origin', 'destination', 'stats'])
            ->first() ?? abort(404, 'Unknown route.');

        return $this->present($request, $route, $snapshots, $freshness, 200);
    }

    /**
     * Price a pair the owner has not committed to — "look before you watch".
     *
     * THE ROUTE IS FOUND OR CREATED, AND THE WATCHLIST IS NOT TOUCHED. Those
     * two facts are the endpoint: a route is a fact about the world and a
     * watchlist row is this account's relationship to one
     * (docs/BUSINESS-LOGIC.md §1), so bringing the first into existence to look
     * at it commits to nothing. Nothing lists unwatched routes, nothing polls
     * them in the morning, and the row that would change that is one tap away
     * on the screen this answers.
     *
     * 201 WHEN THIS REQUEST CREATED THE ROUTE, 200 when it was already there —
     * watched, dropped last spring, or swept up by a rule. The client does not
     * branch on it; it is the honest answer to "did this make something", and
     * it is what makes the idempotency assertable.
     *
     * THE FETCH IS INSIDE THE REQUEST when the route has no fresh fares, which
     * is the one place in Orbit that happens. See FareFreshness for the rule,
     * what it costs and what bounds it.
     */
    public function lookup(LookupRouteRequest $request, RouteSnapshots $snapshots, FareFreshness $freshness): JsonResponse
    {
        $origin = $request->airport('origin');
        $destination = $request->airport('destination');

        /*
         * FIND OR CREATE, exactly as WatchlistItemController::store() does and
         * for the same reason: the pair may already exist, and re-using the row
         * is what hands back the price history it already carries rather than
         * starting the route's life over.
         */
        $route = Route::query()->firstOrCreate(
            ['code' => Route::codeFor($origin->iata, $destination->iata)],
            [
                'origin_airport_id' => $origin->id,
                'destination_airport_id' => $destination->id,
            ],
        );

        $created = $route->wasRecentlyCreated;

        $freshness->refreshIfStale($route);

        return $this->present($request, $route, $snapshots, $freshness, $created ? 201 : 200);
    }

    /**
     * The detail document, plus the two facts that are about this REQUEST
     * rather than about the route.
     *
     * IN `meta` AND NOT IN `data`, which is not tidiness. `data` is the shared
     * route summary (App\Http\Resources\RouteSummaryResource) that four screens
     * read and that the watchlist, the globe and the spotlight card all take
     * whole; whether the person asking happens to watch this route, and how old
     * the fares are, are facts about the asking. The calendar endpoint already
     * carries its `meta` the same way.
     *
     * WHY THE CLIENT NEEDS BOTH. `watched` draws the "Add to watchlist" button
     * — and its absence is what keeps a watched route's detail screen exactly
     * what it was. `fares.fresh` is what lets the screen ask for a refresh when
     * it is looking at something Orbit last priced a long time ago; the rule it
     * applies is "stale AND unwatched", because a watched route is polled every
     * morning and a stale one is a poll to fix rather than a request to make.
     */
    private function present(
        Request $request,
        Route $route,
        RouteSnapshots $snapshots,
        FareFreshness $freshness,
        int $status,
    ): JsonResponse {
        $fetchedAt = $freshness->lastFetchedAt($route);

        return RouteDetailResource::make($snapshots->of($route))
            ->additional(['meta' => [
                'watched' => self::isWatched($request, $route),
                'fares' => [
                    /*
                     * A TIMESTAMP, in the owner's timezone, and the only one in
                     * this API — every other date here is a bare `YYYY-MM-DD`
                     * because it names a DAY (docs/API.md's two axes). This one
                     * names a moment: "checked at 06:12 this morning" is what
                     * the screen says with it when a refresh could not be made.
                     */
                    'fetchedAt' => $fetchedAt?->setTimezone((string) config('orbit.timezone'))->toIso8601String(),
                    'fresh' => $freshness->isFresh($fetchedAt),
                ],
            ]])
            ->response()
            ->setStatusCode($status);
    }

    /**
     * Whether the account asking has this route on its watchlist.
     *
     * SCOPED TO THE USER rather than asked of the route, for the same reason
     * WatchlistItemController's lookup is: there is one account today, and
     * "whose list is it on" is not a question to answer by assuming.
     */
    private static function isWatched(Request $request, Route $route): bool
    {
        /** @var User|null $user */
        $user = $request->user();

        return $user !== null && $user->watchlistItems()->where('route_id', $route->id)->exists();
    }
}
