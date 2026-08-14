<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\CurrentUserController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Five routes. Four of them are the entire authentication surface and the fifth
| is the single-page app.
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
