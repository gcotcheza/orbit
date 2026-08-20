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
 * Route, not a static file: (1) precache list needs the current build's content-hashed assets from Vite's manifest at request time; (2) MUST revalidate (no-cache) or the worker can never be updated once
 * installed; (3) sends Service-Worker-Allowed for when the script moves off root (docs/BUSINESS-LOGIC.md §35).
 *
 * Public (no auth), and must be: the worker registers from the login screen too (same shell), so a 302-to-login served
 * as JS would install a login page as a worker. Nothing here is secret (docs/BUSINESS-LOGIC.md §35).
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
                // JSON_UNESCAPED_SLASHES: paths read as paths — `\/build\/`
                // is legal JS but unreadable exactly when someone needs it.
                (string) json_encode($assets->precacheUrls(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ],
            (string) File::get(resource_path(self::SOURCE)),
        );

        $response = response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',

            /*
             * `no-cache`: store but revalidate (304 via ETag below). Also opts out of Cloudflare's default `.js` edge caching —
             * without it, a stale worker could serve for hours after deploy (docs/BUSINESS-LOGIC.md §35).
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
