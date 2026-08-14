<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Routes\RouteSnapshots;
use App\Http\Resources\RouteDetailResource;
use App\Models\Route;
use Illuminate\Http\JsonResponse;

/**
 * One route, in full (design/README.md §2).
 *
 * KEYED ON THE CODE, not on the id: the SPA's URL is `/route/AMS-LIS` and a
 * detail screen has to be bookmarkable and shareable, which an autoincrement
 * cannot be. An unknown code is a 404 — and under `/api/` bootstrap/app.php
 * renders that as JSON, which is what the client's fetch() can act on.
 *
 * NOT SCOPED TO THE WATCHLIST. PR10's rules surface routes nobody watches yet
 * and tapping one has to open this screen; the watchlist is a list, not a
 * permission. There is one account, so there is nothing here to scope to.
 */
final class RouteController extends Controller
{
    public function show(string $code, RouteSnapshots $snapshots): JsonResponse
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

        return RouteDetailResource::make($snapshots->of($route))->response();
    }
}
