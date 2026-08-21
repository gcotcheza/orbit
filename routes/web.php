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
 * Web routes: auth surface, read API, write API, then the SPA shell. DELIBERATELY ABSENT:
 * registration, password reset, email verification (docs/BUSINESS-LOGIC.md §36).
 */

/*
 * DO NOT REMOVE the `login` name: Laravel resolves route('login') eagerly, and without it every
 * unauthenticated request 500s (docs/BUSINESS-LOGIC.md §36).
 */
Route::view('/login', 'app')->name('login');

Route::middleware('guest')->group(function (): void {
    /*
     * Throttled by email+IP — the whole brute-force surface. Answers JSON, not a redirect: a 302
     * would be followed by fetch() and read as a 200.
     */
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    /*
     * Password change on the session guard, throttled: it is the only authenticated route that
     * checks a secret. `profile/`, not bare `password/`.
     */
    Route::put('/api/profile/password', [PasswordController::class, 'update'])
        ->middleware('throttle:password-change')
        ->name('password.update');
});

/*
 * Boot-time "who is signed in". `auth:sanctum` inside the `web` group: the session guard fires
 * before any token lookup (docs/BUSINESS-LOGIC.md §36).
 */
Route::middleware('auth:sanctum')->get('/api/me', CurrentUserController::class)->name('me');

/*
 * The read API (docs/API.md is the contract). In routes/web.php because the `web` group boots the
 * session unconditionally (docs/BUSINESS-LOGIC.md §36).
 */
Route::middleware('auth:sanctum')->prefix('api')->group(function (): void {
    Route::get('/watchlist', WatchlistController::class)->name('watchlist');

    /*
     * `[A-Z]{3}-[A-Z]{3}` route-code shape only (App\Models\Route::codeFor) — anything else is a
     * malformed request, not a miss (docs/BUSINESS-LOGIC.md §36).
     */
    Route::get('/routes/{code}', [RouteController::class, 'show'])
        ->where('code', '[A-Z]{3}-[A-Z]{3}')
        ->name('routes.show');

    Route::get('/routes/{code}/calendar', RouteCalendarController::class)
        ->where('code', '[A-Z]{3}-[A-Z]{3}')
        ->name('routes.calendar');

    /*
     * Computed at request time rather than cached (see App\Application\Rules\RuleViews) — a cached
     * count would go stale the moment after computing (docs/BUSINESS-LOGIC.md §36).
     */
    Route::get('/rules', [RuleController::class, 'index'])->name('rules.index');

    /*
     * Returns the WHOLE destination list, no `?q=` — client fetches once and filters locally (see
     * App\Http\Controllers\DestinationController) (docs/BUSINESS-LOGIC.md §36).
     */
    Route::get('/destinations', DestinationController::class)->name('destinations');

    /*
     * NOT preloaded like /destinations — 3,270 airports would be 200KB upfront. Throttled because a
     * keystroke can fire it, not for cost.
     */
    Route::get('/airports', AirportController::class)
        ->middleware('throttle:airport-search')
        ->name('airports');

    /*
     * No screen reads this yet, deliberately (alerts screen stays settings-only this PR) — exists
     * so the mail pipeline is inspectable from outside the database (docs/BUSINESS-LOGIC.md §36).
     */
    Route::get('/alerts', AlertController::class)->name('alerts');

    /*
     * Precomputed by DiscoverDeals at 05:20 and deliberately NOT throttled: by request time it is
     * one indexed query over ~10 rows.
     */
    Route::get('/discoveries', DiscoveryController::class)->name('discoveries');
});

/*
 * The write API (docs/API.md). In the `web` group deliberately, for CSRF protection — lib/http.js
 * sends the header on every request.
 */
Route::middleware('auth:sanctum')->prefix('api')->group(function (): void {
    /*
     * LOOK BEFORE YOU WATCH: prices and creates a route row. POST, not GET, so a prefetch can never
     * trigger a paid provider call.
     */
    Route::post('/routes/lookup', [RouteController::class, 'lookup'])
        ->middleware('throttle:route-lookup')
        ->name('routes.lookup');

    /*
     * ⚠ Most expensive write here: one tap = one of 250 monthly SerpAPI searches. No body; the date
     * is the server's (docs/BUSINESS-LOGIC.md §17).
     */
    Route::post('/routes/{code}/live-price', [RouteController::class, 'liveCheck'])
        ->where('code', '[A-Z]{3}-[A-Z]{3}')
        ->middleware('throttle:live-check')
        ->name('routes.live-price');

    Route::post('/watchlist', [WatchlistItemController::class, 'store'])->name('watchlist.store');

    // Same `[A-Z]{3}-[A-Z]{3}` route-code shape constraint as the reads above; malformed, not a
    // miss (docs/BUSINESS-LOGIC.md §36).
    Route::patch('/watchlist/{code}', [WatchlistItemController::class, 'update'])
        ->where('code', '[A-Z]{3}-[A-Z]{3}')
        ->name('watchlist.update');

    Route::delete('/watchlist/{code}', [WatchlistItemController::class, 'destroy'])
        ->where('code', '[A-Z]{3}-[A-Z]{3}')
        ->name('watchlist.destroy');

    /*
     * PUT, not PATCH: the alerts screen always sends the whole preferences object, because an
     * optional boolean cannot be turned off.
     */
    Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

    /*
     * A POST that writes nothing: a GET would leak the owner's sentence into access logs.
     * Throttled, and Anthropic-backed once configured.
     */
    Route::post('/rules/parse', RuleParseController::class)
        ->middleware('throttle:rules-parse')
        ->name('rules.parse');

    Route::post('/rules', [RuleController::class, 'store'])->name('rules.store');

    /*
     * Numeric id, not a code: two rules can be the identical sentence with different chips removed,
     * so there is no natural key.
     */
    Route::patch('/rules/{id}', [RuleController::class, 'update'])
        ->whereNumber('id')
        ->name('rules.update');

    Route::delete('/rules/{id}', [RuleController::class, 'destroy'])
        ->whereNumber('id')
        ->name('rules.destroy');
});

/*
 * SPA catch-all. MUST keep `api`/`up`/`horizon` out of the lookahead so this never swallows them;
 * `/sanctum/csrf-cookie` registers earlier (docs/BUSINESS-LOGIC.md §36).
 */
Route::get('/{any?}', fn () => view('app'))
    ->where('any', '^(?!api|up|horizon).*$')
    ->name('spa');
