<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Services\Pwa\BuildAssets;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;

/**
 * `GET /sw.js` — a route, not a static file: the precache list needs the current build, and
 * the worker must revalidate. Public by necessity (docs/BUSINESS-LOGIC.md §35).
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
             * `no-cache`: store but revalidate (304 via ETag below). Also opts out of Cloudflare's
             * default `.js` edge caching, which would serve a stale worker for hours.
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
