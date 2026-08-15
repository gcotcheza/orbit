<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * `GET /manifest.webmanifest` — what "Add to Home Screen" reads.
 *
 * A route rather than a file in `public/` for one reason that matters and one
 * that is convenience. The reason that matters is the CONTENT TYPE: the correct
 * one is `application/manifest+json`, nginx's stock `mime.types` has no entry
 * for `.webmanifest`, and its fallback — `application/octet-stream`, set as
 * `default_type` in docker/web/nginx.conf — is a download rather than a
 * manifest. The convenience is that the name and the colours then come from
 * config/orbit.php, which is also where resources/views/app.blade.php reads the
 * `theme-color` meta from, so the two cannot drift.
 *
 * ---------------------------------------------------------------------------
 * WHAT AN iPhone ACTUALLY USES
 *
 * Orbit is a phone app with one user, so it is worth being precise about which
 * of these keys do anything on the device it will live on:
 *
 *   USED     name/short_name (the label under the icon), display:standalone
 *            (no browser chrome — the whole reason the design has its own tab
 *            bar), start_url + scope (what counts as "inside the app"), icons
 *            (as a fallback if <link rel="apple-touch-icon"> is absent — it is
 *            not, so this is belt and braces).
 *   IGNORED  theme_color and background_color (iOS takes the status bar from
 *            the meta tags and generates no splash from these), and
 *            `purpose: maskable`.
 *
 * They are all declared anyway, because they are what Android and desktop
 * Chrome want and being installable properly on one platform is not a reason to
 * be installable badly on another.
 *
 * THERE IS NO INSTALL PROMPT TO FIRE on iOS — `beforeinstallprompt` does not
 * exist in WebKit. Installing is Share → Add to Home Screen, by hand; this file
 * is what makes the result look like an app instead of a bookmark.
 * ---------------------------------------------------------------------------
 */
final class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $manifest = [
            'name' => (string) config('orbit.pwa.name'),
            'short_name' => (string) config('orbit.pwa.short_name'),
            'description' => (string) config('orbit.pwa.description'),

            /*
             * `/` — the globe, which is the home screen in both senses.
             *
             * No `?source=pwa` tracking parameter: vue-router would have to
             * carry it forever or strip it on boot, and it would make the
             * installed app's URL differ from the one the owner bookmarked.
             */
            'start_url' => '/',
            'scope' => '/',
            'id' => '/',

            'display' => 'standalone',

            /*
             * PORTRAIT. design/README.md draws every screen inside a 372x760
             * phone frame, and the globe's camera choreography is composed for
             * that shape; a landscape launch would letterbox the one screen the
             * app is named after.
             */
            'orientation' => 'portrait',

            'theme_color' => (string) config('orbit.pwa.theme_color'),
            'background_color' => (string) config('orbit.pwa.background_color'),

            'lang' => 'en-GB',
            'dir' => 'ltr',

            'icons' => [
                // Vector first: a browser that can use it never rasterises.
                [
                    'src' => '/icon.svg',
                    'sizes' => 'any',
                    'type' => 'image/svg+xml',
                    'purpose' => 'any',
                ],
                [
                    'src' => '/icons/icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => '/icons/icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                /*
                 * A SEPARATE RENDERING, not the same file tagged twice. A
                 * maskable icon may be cropped to the circle inscribed in 80%
                 * of the square, so its glyph has to be smaller — public/
                 * icon.svg's orbit ellipse reaches 209 of the 256 half-width
                 * and would have its ends shaved off. Declaring one file as
                 * "any maskable" means shipping either a plain icon with a
                 * needlessly small glyph or a maskable one that is clipped.
                 */
                [
                    'src' => '/icons/icon-maskable-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
                [
                    'src' => '/icons/icon-maskable-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
        ];

        return response()
            ->json($manifest, 200, [
                // The point of this controller. See the note above.
                'Content-Type' => 'application/manifest+json',

                /*
                 * An hour. The manifest changes about once a year and is only
                 * read at install time, but it names icon paths — a day-long
                 * cache would outlive a bad deploy that renamed one, and this
                 * response is edge-cacheable precisely because it carries no
                 * session (see routes/pwa.php).
                 */
                'Cache-Control' => 'public, max-age=3600',
            ], JSON_UNESCAPED_SLASHES);
    }
}
