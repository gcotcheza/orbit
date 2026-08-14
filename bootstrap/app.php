<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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
