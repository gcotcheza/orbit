<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RuleController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\AirportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\RuleParseController;
use App\Http\Controllers\WatchlistController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\RouteCalendarController;
use App\Http\Controllers\WatchlistItemController;
use App\Http\Controllers\Auth\CurrentUserController;

/*
 * Web routes: auth surface, read API, write API, then the SPA shell. DELIBERATELY ABSENT: registration, password
 * reset, email verification (AuthenticationTest asserts this) (docs/BUSINESS-LOGIC.md §36).
 */

/*
 * DO NOT REMOVE the `login` name: Laravel's guest redirect resolves route('login') eagerly, before checking for JSON —
 * with no route named `login`, every unauthenticated request 500s (docs/BUSINESS-LOGIC.md §36).
 */
Route::view('/login', 'app')->name('login');

Route::middleware('guest')->group(function (): void {
    /*
     * Throttled by email+IP (AppServiceProvider) — whole brute-force surface, one account. Answers JSON not a redirect: a
     * 302 here would be followed by fetch() and read as a 200 login (docs/BUSINESS-LOGIC.md §36).
     */
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    /*
     * Password change (session guard, `auth` not `auth:sanctum`), throttled since this is the only authenticated route that checks a secret. `profile/` not
     * bare `password/`: AuthenticationTest forbids any route URI starting with `password` (docs/BUSINESS-LOGIC.md §36).
     */
    Route::put('/api/profile/password', [PasswordController::class, 'update'])
        ->middleware('throttle:password-change')
        ->name('password.update');
});

/*
 * Boot-time "who is signed in" check; the SPA picks app vs login screen off the answer. `auth:sanctum` in the `web`
 * group: session guard fires before token lookup, no Origin/Referer heuristic (docs/BUSINESS-LOGIC.md §36).
 */
Route::middleware('auth:sanctum')->get('/api/me', CurrentUserController::class)->name('me');

/*
 * The read API (docs/API.md is the contract). Lives in routes/web.php, not routes/api.php (this app has none): the
 * `web` group boots the session unconditionally, unlike Sanctum's `api` group (docs/BUSINESS-LOGIC.md §36).
 */
Route::middleware('auth:sanctum')->prefix('api')->group(function (): void {
    Route::get('/watchlist', WatchlistController::class)->name('watchlist');

    /*
     * `[A-Z]{3}-[A-Z]{3}` route-code shape only (App\Models\Route::codeFor) — anything else is a malformed request, not a
     * miss (docs/BUSINESS-LOGIC.md §36).
     */
    Route::get('/routes/{code}', [RouteController::class, 'show'])
        ->where('code', '[A-Z]{3}-[A-Z]{3}')
        ->name('routes.show');

    Route::get('/routes/{code}/calendar', RouteCalendarController::class)
        ->where('code', '[A-Z]{3}-[A-Z]{3}')
        ->name('routes.calendar');

    /*
     * Computed at request time rather than cached (see App\Application\Rules\RuleViews) — a cached count would go stale
     * the moment after computing (docs/BUSINESS-LOGIC.md §36).
     */
    Route::get('/rules', [RuleController::class, 'index'])->name('rules.index');

    /*
     * Returns the WHOLE destination list, no `?q=` — client fetches once and filters locally (see
     * App\Http\Controllers\DestinationController) (docs/BUSINESS-LOGIC.md §36).
     */
    Route::get('/destinations', DestinationController::class)->name('destinations');

    /*
     * NOT preloaded like /destinations — 3,270 airports would be 200KB upfront, so this is a query. Throttled (only read
     * route that is) because a keystroke can fire it, not for cost (docs/BUSINESS-LOGIC.md §36).
     */
    Route::get('/airports', AirportController::class)
        ->middleware('throttle:airport-search')
        ->name('airports');

    /*
     * No screen reads this yet, deliberately (alerts screen stays settings-only this PR) — exists so the mail pipeline is
     * inspectable from outside the database (docs/BUSINESS-LOGIC.md §36).
     */
    Route::get('/alerts', AlertController::class)->name('alerts');

    /*
     * Precomputed by App\Jobs\DiscoverDeals (05:20 sweep) — deliberately NOT throttled, since by request time it's one
     * indexed query over ~10 rows, not a live provider call (docs/BUSINESS-LOGIC.md §36).
     */
    Route::get('/discoveries', DiscoveryController::class)->name('discoveries');
});

/*
 * The write API (docs/API.md). In the `web` group deliberately, for CSRF protection — `resources/js/lib/http.js` sends
 * the XSRF header on every request, so it costs the client nothing (docs/BUSINESS-LOGIC.md §36).
 */
Route::middleware('auth:sanctum')->prefix('api')->group(function (): void {
    /*
     * LOOK BEFORE YOU WATCH: prices+creates a route row. POST not GET, deliberately — a prefetch/preview must never trigger a paid provider call. No
     * `{code}`: body shares RoutePairRequest validation with POST /api/watchlist (docs/BUSINESS-LOGIC.md §36).
     */
    Route::post('/routes/lookup', [RouteController::class, 'lookup'])
        ->middleware('throttle:route-lookup')
        ->name('routes.lookup');

    /*
     * ⚠ Most expensive write here: one tap = one of 250 monthly SerpAPI searches.
     * No body; the date is the server's (docs/BUSINESS-LOGIC.md §17).
     */
    Route::post('/routes/{code}/live-price', [RouteController::class, 'liveCheck'])
        ->where('code', '[A-Z]{3}-[A-Z]{3}')
        ->middleware('throttle:live-check')
        ->name('routes.live-price');

    Route::post('/watchlist', [WatchlistItemController::class, 'store'])->name('watchlist.store');

    // Same `[A-Z]{3}-[A-Z]{3}` route-code shape constraint as the reads above; malformed, not a miss.
    // Why: docs/BUSINESS-LOGIC.md §36.
    Route::patch('/watchlist/{code}', [WatchlistItemController::class, 'update'])
        ->where('code', '[A-Z]{3}-[A-Z]{3}')
        ->name('watchlist.update');

    Route::delete('/watchlist/{code}', [WatchlistItemController::class, 'destroy'])
        ->where('code', '[A-Z]{3}-[A-Z]{3}')
        ->name('watchlist.destroy');

    /*
     * PUT not PATCH: alerts screen always sends the whole preferences object (see App\Http\Requests\UpdateSettingsRequest
     * — an optional boolean can't be turned off) (docs/BUSINESS-LOGIC.md §36).
     */
    Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

    /*
     * POST that writes nothing, deliberately: a GET would leak the owner's free-text sentence into access logs/history.
     * Throttled (20/min) — becomes Anthropic-backed once `orbit.nlp.parser` is configured (docs/BUSINESS-LOGIC.md §36).
     */
    Route::post('/rules/parse', RuleParseController::class)
        ->middleware('throttle:rules-parse')
        ->name('rules.parse');

    Route::post('/rules', [RuleController::class, 'store'])->name('rules.store');

    /*
     * Numeric id, not a code: two rules can be the identical sentence with different chips removed, so there's no natural
     * key. Non-numeric id is malformed, not a miss (docs/BUSINESS-LOGIC.md §36).
     */
    Route::patch('/rules/{id}', [RuleController::class, 'update'])
        ->whereNumber('id')
        ->name('rules.update');

    Route::delete('/rules/{id}', [RuleController::class, 'destroy'])
        ->whereNumber('id')
        ->name('rules.destroy');
});

/*
 * SPA catch-all. MUST keep `api`/`up`/`horizon` excluded from the lookahead (JSON 404s stay 404s, health check, queue dashboard) so this route never swallows them. `/sanctum/csrf-cookie` needs no
 * exclusion: it registers before this file loads and routes match in registration order (docs/BUSINESS-LOGIC.md §36).
 */
Route::get('/{any?}', fn () => view('app'))
    ->where('any', '^(?!api|up|horizon).*$')
    ->name('spa');
