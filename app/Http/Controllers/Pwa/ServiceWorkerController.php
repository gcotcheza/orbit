<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Services\Pwa\BuildAssets;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;

/**
 * `GET /sw.js` — the service worker script.
 *
 * ---------------------------------------------------------------------------
 * WHY A ROUTE AND NOT A FILE IN public/
 *
 * Three things a static file cannot do here.
 *
 * 1. THE PRECACHE LIST. It names the current build's content-hashed assets,
 *    which change every deploy. Generating it at request time from the Vite
 *    manifest means the worker and the page can never disagree about what the
 *    build produced; a committed file with hashes in it is wrong the moment
 *    somebody runs `npm run build`.
 *
 * 2. THE CACHE HEADERS. A service worker MUST revalidate — one cached for a
 *    year is an app that can never be updated, and there is no second lever to
 *    pull once it is on somebody's home screen. docker/web/nginx.conf could
 *    carry a location block for it; here the policy sits next to the reason
 *    for it, which is why that file deliberately leaves this path to PHP.
 *
 * 3. THE SCOPE HEADER. `Service-Worker-Allowed: /` is redundant while the
 *    script is served from the root and stops being redundant the day it moves.
 *    Sending it costs nothing and removes a class of silent breakage.
 *
 * The response is a few kilobytes and carries an ETag, so the revalidation a
 * browser performs on every navigation is a 304 with an empty body.
 * ---------------------------------------------------------------------------
 *
 * IT IS PUBLIC (no auth), and it has to be: the worker is registered from the
 * login screen as well as from the app — they are the same shell — and a
 * 302-to-login served as `application/javascript` would install a login page as
 * a worker. Nothing in the script is a secret: it is a caching policy and a list
 * of asset filenames the HTML already links to.
 */
final class ServiceWorkerController extends Controller
{
    private const SOURCE = 'js/service-worker.js';

    public function __invoke(Request $request, BuildAssets $assets): Response
    {
        $script = str_replace(
            ['__SW_VERSION__', '__SW_PRECACHE__'],
            [
                $assets->version(),
                // JSON_UNESCAPED_SLASHES so the paths in the file read as
                // paths; a worker full of `\/build\/` is legal JS and
                // unreadable at the moment anyone needs to read it.
                (string) json_encode($assets->precacheUrls(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ],
            (string) File::get(resource_path(self::SOURCE)),
        );

        $response = response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',

            /*
             * NO LONG-LIVED CACHE. `no-cache` means "you may store it, but ask
             * me before using it" — which, with the ETag below, makes the
             * per-navigation update check a 304.
             *
             * It is also what stops Cloudflare holding it: `.js` is in the
             * edge's default cacheable extension list, and an origin
             * Cache-Control of `no-cache` is what opts out. A worker cached at
             * the edge would mean deploying a new one and having the phone keep
             * the old app for hours.
             */
            'Cache-Control' => 'no-cache, must-revalidate',

            // Redundant at the root; correct if it ever moves. See above.
            'Service-Worker-Allowed' => '/',
        ]);

        $response->setEtag(md5($script));

        // Turns the update check into an empty 304 without changing any of the
        // headers above.
        $response->isNotModified($request);

        return $response;
    }
}
