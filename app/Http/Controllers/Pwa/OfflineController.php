<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * `GET /offline` — the page the service worker shows when a navigation cannot
 * reach the network.
 *
 * A route rather than a flat HTML file so that it is compiled, tested and
 * versioned like every other view, and so the precache entry is a URL the
 * router owns rather than a path that has to exist on disk.
 *
 * PUBLIC, and it has to be: the whole point of the page is that it renders when
 * nothing else can, and a guest navigation while offline would otherwise fall
 * through auth middleware to a redirect the worker cannot follow.
 *
 * It is also the one HTML response in this app that is ALLOWED to be cached,
 * because it contains no data: no fares, no routes, no name. A month-old copy
 * says exactly what a fresh one says.
 */
final class OfflineController extends Controller
{
    public function __invoke(): Response
    {
        /** @var View $view */
        $view = view('offline');

        return response($view->render(), 200, [
            'Content-Type' => 'text/html; charset=utf-8',

            // A day at the edge. The service worker keeps its own copy for as
            // long as the build version holds, so this header only governs the
            // very first fetch and any browser that has no worker yet.
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
