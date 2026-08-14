<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\CurrentUserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\RouteCalendarController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\WatchlistController;
use App\Http\Controllers\WatchlistItemController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Four routes are the entire authentication surface, three more are the read
| API the screens are built on, five more are the writes those screens make,
| and the last one is the single-page app.
|
| WHAT IS DELIBERATELY ABSENT: registration, password reset, email
| verification, account management. docs/PLAN.md locks Orbit to a single user,
| created by `db:seed` (see Database\Seeders\SingleUserSeeder), and a route
| that does not exist cannot be misconfigured, rate-limit-bypassed or left
| enabled by a later refactor. AuthenticationTest asserts that nothing is
| registered at /register, /forgot-password, /reset-password, /verify-email or
| /confirm-password, and that a POST to any of them is refused — asserted
| against the route table rather than against a 404, because the catch-all at
| the bottom of this file answers every unclaimed GET with the shell.
|
*/

/*
 * The login screen is a Vue view like every other screen, so this serves the
 * same shell the catch-all at the bottom of this file serves, and could have
 * been left to it.
 *
 * IT IS DECLARED ANYWAY, FOR ITS NAME. Laravel's default guest redirect is
 * `route('login')` — ApplicationBuilder::withMiddleware sets it before this
 * app's own configuration runs — and Authenticate::unauthenticated() resolves
 * that URL EAGERLY, before it has looked at whether the response should be
 * JSON. In an app with no route named `login`, every unauthenticated request
 * to a guarded route is therefore a 500 from the URL generator rather than a
 * 401, and the JSON-rendering rule in bootstrap/app.php never gets a chance to
 * apply. One named route is a smaller answer than overriding the redirect.
 */
Route::view('/login', 'app')->name('login');

Route::middleware('guest')->group(function (): void {
    /*
     * Throttled by email+IP — see AppServiceProvider. One account means one
     * password to guess, so this route is the whole brute-force surface.
     *
     * Answers JSON, not a redirect: the caller is fetch() from a page that must
     * not navigate. A 302 would be followed and handed back as the shell's HTML
     * with a 200, i.e. as a successful login.
     */
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});

/*
 * Who is signed in, if anyone.
 *
 * The SPA calls this once on boot to decide between the app and the login
 * screen, so its GUEST answer matters as much as its authenticated one: under
 * `/api/` bootstrap/app.php renders exceptions as JSON, so that answer is a
 * 401 with a body rather than a redirect fetch() would follow.
 *
 * `auth:sanctum` rather than `auth`, and in the `web` group rather than an api
 * one. Sanctum's guard tries config('sanctum.guard') — the session guard —
 * before it looks for a token, so a first-party cookie authenticates here with
 * no token anywhere; and being in the `web` group means the session is booted
 * unconditionally rather than on Sanctum's Origin/Referer heuristic. See
 * bootstrap/app.php.
 */
Route::middleware('auth:sanctum')->get('/api/me', CurrentUserController::class)->name('me');

/*
|--------------------------------------------------------------------------
| The read API
|--------------------------------------------------------------------------
|
| Three endpoints, and between them they are the entire data supply for the
| globe home, the route detail, the price calendar and the watchlist screens.
| Their exact shapes are docs/API.md — that file is the contract those screens
| are built against, and it is written before they are.
|
| IN routes/web.php AND NOT routes/api.php, which this app does not have. The
| reasoning is the same as for /api/me above: the `web` group boots the session
| unconditionally, while Sanctum's `api` group decides whether to by sniffing
| Origin/Referer — a heuristic that on a single-origin SPA can only ever turn a
| signed-in user into a 401. The `/api/` PREFIX is what matters and is kept: it
| is what bootstrap/app.php renders exceptions as JSON under, and what the SPA
| catch-all at the bottom of this file refuses to swallow.
|
| ALL THREE ARE READS. The writes are the group below.
|
*/
Route::middleware('auth:sanctum')->prefix('api')->group(function (): void {
    Route::get('/watchlist', WatchlistController::class)->name('watchlist');

    /*
     * `AMS-LIS`, and the pattern says so.
     *
     * Without the constraint every misspelling reaches the controller and
     * comes back as a 404 from a database round trip — and, more to the point,
     * `/api/routes/../../something` would be a routing question rather than a
     * pattern violation. Two three-letter groups is exactly what a route code
     * is (App\Models\Route::codeFor), so anything else is not a miss, it is a
     * malformed request.
     */
    Route::get('/routes/{code}', [RouteController::class, 'show'])
        ->where('code', '[A-Z]{3}-[A-Z]{3}')
        ->name('routes.show');

    Route::get('/routes/{code}/calendar', RouteCalendarController::class)
        ->where('code', '[A-Z]{3}-[A-Z]{3}')
        ->name('routes.calendar');
});

/*
|--------------------------------------------------------------------------
| The write API
|--------------------------------------------------------------------------
|
| Everything the watchlist and alerts screens (design/README.md §5 and §6) can
| change: which routes are watched, whether each one is paused, and how the
| owner wants to be told. Their shapes are docs/API.md, same as the reads.
|
| IN THE `web` GROUP, WHICH IS WHY THEY ARE CSRF-PROTECTED. That is the reason
| this app has no routes/api.php at all: Laravel's `api` group has no CSRF
| middleware because a token-authenticated client does not need one, and these
| endpoints are called by a browser carrying a session cookie — exactly the
| case CSRF exists for. `resources/js/lib/http.js` sends the XSRF header on
| every request, so the protection costs the client nothing.
|
| A SEPARATE GROUP FROM THE READS ABOVE, and separate controllers behind it.
| The read is the app's launch request and is tuned as one; these are one-row
| operations behind a tap. Neither should have to grow the other's concerns.
|
*/
Route::middleware('auth:sanctum')->prefix('api')->group(function (): void {
    Route::post('/watchlist', [WatchlistItemController::class, 'store'])->name('watchlist.store');

    /*
     * The same `[A-Z]{3}-[A-Z]{3}` constraint the reads carry, for the same
     * reason: a code is either that shape or it is malformed, and a write is
     * the last place to let a path segment reach a query unexamined.
     */
    Route::patch('/watchlist/{code}', [WatchlistItemController::class, 'update'])
        ->where('code', '[A-Z]{3}-[A-Z]{3}')
        ->name('watchlist.update');

    Route::delete('/watchlist/{code}', [WatchlistItemController::class, 'destroy'])
        ->where('code', '[A-Z]{3}-[A-Z]{3}')
        ->name('watchlist.destroy');

    /*
     * PUT rather than PATCH: the alerts screen sends the whole preferences
     * object every time. See App\Http\Requests\UpdateSettingsRequest for why
     * an optional boolean is a switch that cannot be turned off.
     */
    Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
});

/*
 * The SPA shell. Every path that is not one of the above is answered with the
 * same HTML, and vue-router decides what it means.
 *
 * The pattern is a NEGATIVE LOOKAHEAD rather than a list of screens, because
 * the screens are a client-side concern and this file should not need editing
 * every time one is added. What it must never swallow is a path the server
 * owns: `api` (JSON, and a 404 there has to stay a 404 rather than become a
 * 200 of HTML), `up` (the health endpoint registered by bootstrap/app.php) and
 * `horizon` (the queue dashboard, whose routes come from its own provider).
 *
 * `/sanctum/csrf-cookie` is NOT in the lookahead and does not need to be:
 * Sanctum's service provider registers it while the framework boots, which is
 * before this file is loaded, and the router matches in registration order.
 * AuthenticationTest asserts that it still answers 204.
 */
Route::get('/{any?}', fn () => view('app'))
    ->where('any', '^(?!api|up|horizon).*$')
    ->name('spa');
