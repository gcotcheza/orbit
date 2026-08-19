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
 * THREE ACTIONS, ONE BODY, AND WHY THE TWO WRITES ARE POSTS
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
 * `liveCheck()` IS THE SAME ARGUMENT AGAIN, ONE VENDOR OVER. It spends at most
 * one SerpAPI search out of two hundred and fifty a MONTH — the most expensive
 * single request this app can make — so it is a POST, throttled, and it is the
 * only path in Orbit that spends that budget from a person's tap. Nothing
 * schedules it and nothing takes a list. See App\Application\Routes\
 * LivePriceChecks for the four guardrails around it.
 *
 * THEY SHARE `present()` DELIBERATELY. The detail screen adopts a lookup's
 * answer without re-fetching, exactly as the watchlist screen adopts a write's
 * row (WatchlistItemController), so the bodies are not merely similar — they
 * have to be the same shape or the screen would have three ways to read itself.
 * That is also why the live check answers the WHOLE detail document rather than
 * a bare price: the screen swaps its headline by adopting a document, exactly
 * as it already does after a lookup.
 */
final class RouteController extends Controller
{
    public function show(Request $request, string $code, RouteSnapshots $snapshots, FareFreshness $freshness, LivePriceChecks $liveChecks): JsonResponse
    {
        $route = self::find($code);

        return $this->present($request, $route, $snapshots->of($route), $freshness, $liveChecks, 200);
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
    public function lookup(LookupRouteRequest $request, RouteSnapshots $snapshots, FareFreshness $freshness, LivePriceChecks $liveChecks): JsonResponse
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
                'origin_airport_id'      => $origin->id,
                'destination_airport_id' => $destination->id,
            ],
        );

        $created = $route->wasRecentlyCreated;

        $freshness->refreshIfStale($route);

        return $this->present($request, $route, $snapshots->of($route), $freshness, $liveChecks, $created ? 201 : 200);
    }

    /**
     * Go and ask Google what this route really costs today — the button under
     * a fare that may already be gone.
     *
     * =========================================================================
     * THE ONLY PLACE IN ORBIT A TAP SPENDS A SERPAPI SEARCH
     * =========================================================================
     * The budget is 250 a MONTH on a free plan, with fifty of them reserved and
     * about five a night already going to discovery, so this endpoint is built
     * to spend as little of it as it can get away with:
     *
     *   - THE COOLDOWN FIRST. A check made for this route and date inside
     *     `orbit.live_check.cooldown_hours` is served from the row and costs
     *     nothing, whether it is a second tap or a second visit.
     *   - THEN THE QUOTA, read from SerpAPI's free account endpoint before a
     *     single search is spent, failing closed on anything it cannot read.
     *   - AND THE RESERVE, which is the one that refuses. See
     *     App\Application\Routes\LivePriceChecks.
     *
     * =========================================================================
     * THE DATE IS THE SERVER'S AND NOT THE REQUEST'S, WHICH IS A GUARDRAIL
     * =========================================================================
     * There is no body. The date checked is the CHEAPEST DEPARTURE this screen
     * is showing — the same `cheapest` the detail document publishes, read from
     * the same snapshot — for two reasons that both matter. It cannot disagree
     * with the fare being questioned: a client-supplied date would let the
     * answer be about a different flight than the one under it, which is this
     * app's oldest mistake with a "checked live" label on top. And it cannot be
     * used to spend the month: with no date to vary, the cooldown covers every
     * repeat of the only question this endpoint can be asked about a route.
     *
     * 503 WHEN THE BUDGET SAYS NO, and it is deliberately not a 200 with an
     * empty answer. The screen has to be able to tell "Google says €150" from
     * "nobody asked Google" — the second one leaves the cached price standing,
     * demoted, with a sentence saying the check is held in reserve. A body that
     * looked like an answer and was not is precisely the failure mode this
     * whole feature exists to remove.
     *
     * 409 WHEN THERE IS NOTHING TO CHECK. A route with no fares in the window
     * has no departure to ask about; that is not an error in the request and
     * not a route that is missing, it is a question with no subject.
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

        if ($liveChecks->check($route, $departure) === null) {
            abort(503, 'Orbit is holding its remaining live checks in reserve.');
        }

        /*
         * THE SNAPSHOT IS REBUILT rather than reused, because the check that
         * has just run wrote a row this document has to carry — `present()`
         * reads the stored answer back through the same path a plain view of
         * the screen does, so there is exactly one way a live check reaches a
         * client and no chance of a freshly made one being published on terms
         * a re-view would not repeat.
         */
        return $this->present($request, $route, $snapshots->of($route), $freshness, $liveChecks, 200);
    }

    /**
     * The route by its code, or a 404 the client can act on.
     *
     * `abort()` rather than `firstOrFail()`, whose 404 body is Laravel's "No
     * query results for model [App\Models\Route]" — a message that names an
     * internal class and that the client cannot show a person. The client
     * branches on the status; the string is for a developer reading a network
     * tab.
     */
    private static function find(string $code): Route
    {
        return Route::query()
            ->where('code', $code)
            ->with(['origin', 'destination', 'stats'])
            ->first() ?? abort(404, 'Unknown route.');
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
        RouteSnapshot $snapshot,
        FareFreshness $freshness,
        LivePriceChecks $liveChecks,
        int $status,
    ): JsonResponse {
        $fetchedAt = $freshness->lastFetchedAt($route);
        $departure = $snapshot->cheapest?->departureDate;

        /*
         * WHAT GOOGLE SAID, IF ANYBODY HAS ASKED IT LATELY — one indexed row,
         * and null on every screen where nobody has.
         *
         * READ ON EVERY VIEW AND NOT ONLY AFTER THE BUTTON. A check somebody
         * paid a metered search for at lunchtime is what this screen shows at
         * teatime; offering to buy the same answer again would be the app
         * forgetting what it knows, at 250 searches a month. `latest()` applies
         * the cooldown, so an answer past its six hours is not published as
         * "live" — it simply is not published, and the button comes back.
         *
         * ABOUT THE CHEAPEST DEPARTURE, and null when there is none: a check
         * belongs to a flight, and a stored answer for the 12th says nothing
         * about a screen that is now showing the 19th.
         */
        $liveCheck = $departure === null ? null : $liveChecks->latest($route, $departure);

        return RouteDetailResource::make($snapshot)
            ->additional(['meta' => [
                'watched' => self::isWatched($request, $route),

                /*
                 * IN `meta` BESIDE `fares`, and for the same reason it is: this
                 * is how fresh what you are looking at is and what the screen
                 * should offer to do about it. `data` is the shared route
                 * summary four screens read, and three of them have neither the
                 * room to draw a live check nor a button to make one.
                 */
                'liveCheck' => $liveCheck === null ? null : LivePriceResource::make($liveCheck)->toArray($request),

                'fares' => [
                    /*
                     * A TIMESTAMP, in the owner's timezone, and the only one in
                     * this API — every other date here is a bare `YYYY-MM-DD`
                     * because it names a DAY (docs/API.md's two axes). This one
                     * names a moment: "checked at 06:12 this morning" is what
                     * the screen says with it when a refresh could not be made.
                     */
                    'fetchedAt' => $fetchedAt?->setTimezone((string) config('orbit.timezone'))->toIso8601String(),
                    'fresh'     => $freshness->isFresh($fetchedAt),
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
