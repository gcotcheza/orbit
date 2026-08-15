<?php

use App\Http\Middleware\AuthenticateSession;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',

        /*
         * The PWA shell — the manifest, the service worker and the offline
         * page — registered with NO middleware group.
         *
         * `Route::group([], ...)` is the whole point: none of the three reads a
         * session, a CSRF token or a user, and a browser revalidates /sw.js on
         * EVERY navigation. Inside the `web` group each of those checks would
         * write a `sessions` row for a visitor who is not one and answer with a
         * Set-Cookie, which also stops Cloudflare holding the response. The
         * full reasoning is in routes/pwa.php.
         *
         * -------------------------------------------------------------------
         * WHY THE SPA ROUTE IS DEMOTED TO A FALLBACK ON THE LINE ABOVE
         *
         * `then:` runs AFTER routes/web.php has been loaded (see
         * ApplicationBuilder::buildRoutingCallback), and the router answers
         * with the first route that matches in registration order. The last
         * thing web.php registers is the SPA catch-all — `/{any?}` with a
         * negative lookahead that excludes `api`, `up` and `horizon` — so
         * without this line /manifest.webmanifest, /sw.js and /offline would
         * every one of them be answered with the app's HTML shell, at 200. The
         * failure is silent in the worst way: `navigator.serviceWorker
         * .register()` rejects with a MIME-type error nobody sees, and the OS
         * reads a page of HTML where it expected a manifest and installs a
         * bookmark.
         *
         * Laravel has a first-class notion of "the route that answers when
         * nothing else did" — a fallback — and both matchers (the collection at
         * runtime and the compiled one behind `route:cache`) try every other
         * route before any fallback. The SPA catch-all IS that route: it exists
         * to hand vue-router the paths the server does not claim. Saying so
         * here is what lets these three be claimed, and what stops the next
         * server-owned path from having to be added to a regex in web.php.
         *
         * The name lookups are refreshed first because the framework does not
         * build them until `booted`, which is after all routes are loaded —
         * without it `getByName('spa')` is null here. The refresh is a rebuild
         * from the collection and the framework runs it again later, so doing
         * it early costs nothing.
         *
         * If this ever stops working, it stops loudly:
         * tests/Feature/PwaShellTest asserts all three routes reach their
         * controllers rather than the shell.
         * -------------------------------------------------------------------
         */
        then: function (): void {
            $routes = Route::getRoutes();

            $routes->refreshNameLookups();
            $routes->getByName('spa')?->fallback();

            Route::group([], __DIR__.'/../routes/pwa.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * TRUSTED PROXIES — a list, deliberately not `at: '*'`.
         *
         * The request arrives having crossed two proxies: Cloudflare, then the
         * host's nginx, which proxy_passes to 127.0.0.1:3085 — the compose
         * stack's nginx sidecar, which speaks FastCGI to php-fpm. PHP therefore
         * sees a private container IP as REMOTE_ADDR and plain http as the
         * scheme, and without this every generated URL would be http:// and
         * every `secure` cookie would be dropped by the browser. That is what
         * trusting a proxy is FOR here, and it has to keep working.
         *
         * WHY NOT `*`. Trusting every proxy also means trusting whatever
         * X-Forwarded-For the request happens to carry, and Symfony then
         * answers `$request->ip()` with the LEFTMOST entry of that header — a
         * value the client wrote. Any login throttle keyed on the IP then
         * counts nothing, because a guesser who varies the header per attempt
         * gets a fresh bucket every time. The same over-trust lets
         * X-Forwarded-Host decide getHost(), i.e. the host in every URL this
         * app generates. This is not hypothetical: it is the finding that was
         * fixed in health-tracker's security audit, and Orbit starts on the
         * other side of it.
         *
         * The protection is in two halves and needs both:
         *
         *   1. deploy/nginx/flights-ghiecode.conf RESETS the forwarding headers
         *      instead of appending to them: X-Forwarded-For is set to
         *      $remote_addr, which the realip module has already rewritten to
         *      the true client from CF-Connecting-IP. Nothing a client sends
         *      survives that hop.
         *   2. this list, which says only a PRIVATE address is allowed to speak
         *      for somebody else. php-fpm is reachable over the compose bridge
         *      and nothing else — the sidecar's FastCGI requests arrive from the
         *      bridge gateway (172.x.0.1) — so RFC1918 covers the real hop.
         *
         * RFC1918 RATHER THAN TODAY'S /16, deliberately. Compose assigns the
         * bridge subnet from Docker's default pool at network-create time, so
         * the exact range changes on any `docker compose down && up`. Pinning
         * today's value would be a config that silently breaks HTTPS and secure
         * cookies the day the network is recreated; the /12 covers the whole
         * pool and is still not the internet.
         *
         * LOOPBACK IS ABSENT ON PURPOSE. Nothing reaches php-fpm over
         * 127.0.0.1: the published port belongs to the SIDECAR, and the sidecar
         * talks to app:9000 across the bridge. Leaving it out means a request
         * that somehow originates on the host itself cannot dictate its own
         * client IP either.
         */
        $middleware->trustProxies(at: [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            'fc00::/7',
        ]);

        /*
         * TRUSTED HOSTS. With a trusted proxy in front, `Host` (or
         * `X-Forwarded-Host`) decides what `URL::to()` produces — so without an
         * allowlist a single request can plant an attacker's origin in anything
         * built from it, including the links in an alert email.
         *
         * EACH ENTRY IS A REGEX, AND AN UNANCHORED ONE. Symfony wraps every
         * pattern as `{...}i` and runs preg_match against the host, so the bare
         * string `flights.ghiecode.io` would also match
         * `flights-ghiecode.io.attacker.example` — the dots are wildcards and
         * there is no ^ or $. Hence the escaping and the anchors, which is
         * exactly what Laravel's own default pattern does.
         *
         * `subdomains: false` because there are none: this app answers to one
         * production name. Anything else asking for a URL from this app is
         * asking under a name we do not serve, and gets a 400 rather than a
         * poisoned link.
         *
         * The middleware is inert under `local` and under the test runner —
         * Laravel's own guard, so a feature test still reaches the app on
         * `localhost`.
         */
        $middleware->trustHosts(at: [
            '^flights\.ghiecode\.io$',
        ], subdomains: false);

        /*
         * SANCTUM IN COOKIE/SESSION MODE — no tokens, nothing in localStorage.
         *
         * The SPA and the API are the same origin (flights.ghiecode.io), so the
         * browser's own httpOnly, SameSite=Lax, Secure session cookie is the
         * credential. A bearer token would have to be stored somewhere
         * JavaScript can read, which is the one place a credential should never
         * be — and would buy nothing, because there is no third-party client.
         *
         * This line prepends EnsureFrontendRequestsAreStateful to the `api`
         * middleware group, which is what makes a first-party request to an
         * `api` route carry the session and be CSRF-checked instead of being
         * treated as an anonymous token call.
         *
         * WHY THE JSON THE SPA ACTUALLY CALLS IS NOT IN THAT GROUP. `/api/me`
         * is declared in routes/web.php and runs in the `web` group, where the
         * session is UNCONDITIONAL. EnsureFrontendRequestsAreStateful decides
         * whether to boot a session by matching the request's Origin/Referer
         * against config('sanctum.stateful') — a heuristic that is correct in a
         * browser and silently false for anything that does not send those
         * headers. On a single-origin app that heuristic can only ever turn a
         * signed-in user into a 401; being in the `web` group removes the
         * question. See routes/web.php.
         *
         * It is set anyway, because an `api` route file is the obvious place
         * for the next person to add an endpoint, and Sanctum's default for one
         * is token-only.
         */
        $middleware->statefulApi();

        /*
         * AuthenticateSession — THE MIDDLEWARE THAT MAKES A PASSWORD CHANGE
         * MEAN SOMETHING ON A DEVICE THIS ONE CANNOT REACH.
         *
         * It keeps a copy of the user's password hash in each session and
         * compares it against the real one on every request; a session whose
         * copy has gone stale is logged out. That is the ONLY code in the
         * framework that reads that copy, and Orbit registered it in no group,
         * under no alias and on no route — so `Auth::logoutOtherDevices()` was
         * a call that returned successfully and evicted nobody. This is the
         * silent no-op health-tracker shipped for months and its security audit
         * found; Orbit starts on the other side of it.
         *
         * APPENDED TO `web` AND TO NOTHING ELSE. That group is where the
         * session is, and it is where the SPA's own JSON endpoints live too —
         * routes/web.php declares `/api/me` and the rest inside it deliberately
         * (see the Sanctum note above), so an evicted session gets a 401 on its
         * next read and resources/js/lib/http.js sends that browser to the login
         * screen. The `api` route file has no session and must stay that way.
         *
         * SAFE ON THE WHOLE GROUP rather than only the authenticated half: its
         * first line returns early when the request has no user, so /login and
         * the guest boot probe pay a null check and nothing else.
         *
         * IT IS ORBIT'S SUBCLASS AND NOT THE FRAMEWORK'S, because this app has
         * Sanctum and the framework's reads `auth.defaults.guard` — which
         * `auth:sanctum` rewrites mid-request. App\Http\Middleware\
         * AuthenticateSession pins it to the session guard at both ends; the
         * class says what breaks otherwise, and both failures are severe.
         *
         * WHAT IT COSTS: one comparison per request, and one genuine behaviour
         * change — a browser holding a remember-me cookie minted before a
         * password change is signed out rather than let back in, because the
         * recaller carries the old hash in its third segment. That is the point
         * of the exercise. App\Http\Controllers\Auth\PasswordController re-issues
         * the changing device's own cookie so that it is the one that survives.
         */
        $middleware->web(append: [
            AuthenticateSession::class,
        ]);

        /*
         * AND IT RUNS BEFORE `auth`, WHICH IT DOES NOT BY DEFAULT.
         *
         * The router does not run middleware in the order they are written: it
         * sorts them through Kernel::$middlewarePriority, where
         * `Contracts\Auth\Middleware\AuthenticatesRequests` (the `auth`
         * middleware) sits three places ABOVE
         * `Contracts\Session\Middleware\AuthenticatesSessions` (the class
         * appended above). Appending to the `web` group does not change that,
         * and `auth:sanctum` is what rewrites the default guard mid-request.
         *
         * WHY IT MATTERS EVEN THOUGH THE SUBCLASS ALREADY SURVIVES THAT:
         *
         *   - the session key is read on the way in, and the key is
         *     `password_hash_` + whatever the default guard is at that moment.
         *     Running before anything can call `shouldUse()` is what makes that
         *     read `password_hash_web` on every route rather than
         *     `password_hash_sanctum` on some of them — one key, and it is the
         *     one App\Http\Middleware\AuthenticateSession keeps current;
         *   - a session this middleware decides to kill should be dead before
         *     anything downstream builds a response out of the user on it. That
         *     is health-tracker's own rationale for the same placement.
         *
         * It still lands AFTER `ValidateCsrfToken`, which is not in the priority
         * list and keeps its written position in the group — a request with no
         * token is refused for that before this ever looks at it.
         *
         * The list is matched on the exact class name first and on interfaces
         * second (Illuminate\Routing\SortedMiddleware), so naming the concrete
         * class here wins over the contract entry further down the default list.
         */
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: AuthenticateSession::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * WHEN AN EXCEPTION IS JSON RATHER THAN A REDIRECT.
         *
         * The whole UI is a Vue SPA talking to `/api/*` with fetch, from a page
         * that must not navigate. A guest hitting one of those endpoints has to
         * get 401 with a body rather than a 302 that fetch() follows and hands
         * back as the login page's HTML — i.e. as a 200 that looks like
         * success. `expectsJson()` covers any caller that asks for JSON
         * explicitly without matching the prefix.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
