<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

/**
 * `GET /manifest.webmanifest` — what "Add to Home Screen" reads.
 *
 * A route, not a static file — nginx has no mime type for .webmanifest.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * Most keys here are inert on iOS (this app's only real target); declared anyway for Android/desktop.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $manifest = [
            'name'        => (string) config('orbit.pwa.name'),
            'short_name'  => (string) config('orbit.pwa.short_name'),
            'description' => (string) config('orbit.pwa.description'),

            /*
             * `/` — the globe. No `?source=pwa` tracking param, deliberately.
             * Why: docs/BUSINESS-LOGIC.md §36.
             */
            'start_url' => '/',
            'scope'     => '/',
            'id'        => '/',

            'display' => 'standalone',

            /*
             * Portrait — design/README.md's frame and camera choreography assume it.
             * Why: docs/BUSINESS-LOGIC.md §36.
             */
            'orientation' => 'portrait',

            'theme_color'      => (string) config('orbit.pwa.theme_color'),
            'background_color' => (string) config('orbit.pwa.background_color'),

            'lang' => 'en-GB',
            'dir'  => 'ltr',

            'icons' => [
                // Vector first: a browser that can use it never rasterises.
                [
                    'src'     => '/icon.svg',
                    'sizes'   => 'any',
                    'type'    => 'image/svg+xml',
                    'purpose' => 'any',
                ],
                [
                    'src'     => '/icons/icon-192.png',
                    'sizes'   => '192x192',
                    'type'    => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src'     => '/icons/icon-512.png',
                    'sizes'   => '512x512',
                    'type'    => 'image/png',
                    'purpose' => 'any',
                ],
                /*
                 * A separate rendering, not the same file tagged twice — maskable needs a smaller glyph to survive the crop.
                 * Why: docs/BUSINESS-LOGIC.md §36.
                 */
                [
                    'src'     => '/icons/icon-maskable-192.png',
                    'sizes'   => '192x192',
                    'type'    => 'image/png',
                    'purpose' => 'maskable',
                ],
                [
                    'src'     => '/icons/icon-maskable-512.png',
                    'sizes'   => '512x512',
                    'type'    => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
        ];

        return response()
            ->json($manifest, 200, [
                // The point of this controller. See the note above.
                'Content-Type' => 'application/manifest+json',

                /*
                 * An hour, not longer — icon paths could rename in a bad deploy, and this response carries no session.
                 * Why: docs/BUSINESS-LOGIC.md §36.
                 */
                'Cache-Control' => 'public, max-age=3600',
            ], JSON_UNESCAPED_SLASHES);
    }
}
