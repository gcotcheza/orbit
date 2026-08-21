<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Application;
use App\Http\Middleware\AuthenticateSession;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',

        // PWA shell routes register with NO middleware group, so none starts a
        // session; the SPA catch-all is demoted to a fallback here (docs/BUSINESS-LOGIC.md §36).
        then: function (): void {
            $routes = Route::getRoutes();

            $routes->refreshNameLookups();
            $routes->getByName('spa')?->fallback();

            Route::group([], __DIR__.'/../routes/pwa.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // RFC1918, not `at: '*'` or today's exact bridge subnet — trusting
        // every proxy trusts a client-controlled IP/Host (docs/BUSINESS-LOGIC.md §36).
        $middleware->trustProxies(at: [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            'fc00::/7',
        ]);

        // Each entry is an ANCHORED regex — Symfony's unanchored match would
        // let a suffix like `.attacker.example` through (docs/BUSINESS-LOGIC.md §36).
        $middleware->trustHosts(at: [
            '^flights\.ghiecode\.io$',
        ], subdomains: false);

        // Sanctum in cookie/session mode. The SPA's own JSON calls avoid the
        // `api` group entirely — this is a safety net (docs/BUSINESS-LOGIC.md §36).
        $middleware->statefulApi();

        // The middleware that makes a password change mean something on a
        // device this one cannot reach (docs/BUSINESS-LOGIC.md §36).
        $middleware->web(append: [
            AuthenticateSession::class,
        ]);

        // Runs before `auth`, which it does not by default — the router
        // sorts by priority, not by registration order (docs/BUSINESS-LOGIC.md §36).
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: AuthenticateSession::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A guest hitting `/api/*` gets 401 with a body, never a redirect
        // fetch() follows into a 200 that looks like success (docs/BUSINESS-LOGIC.md §36).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
