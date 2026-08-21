<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use Illuminate\Http\Response;
use Illuminate\Contracts\View\View;
use App\Http\Controllers\Controller;

/**
 * `GET /offline` — a route rather than a flat file so it is compiled, tested and versioned.
 * PUBLIC by necessity, and the one HTML response allowed to be cached (it holds no data).
 */
final class OfflineController extends Controller
{
    public function __invoke(): Response
    {
        /** @var View $view */
        $view = view('offline');

        return response($view->render(), 200, [
            'Content-Type' => 'text/html; charset=utf-8',

            // A day at the edge: the worker keeps its own copy while the build version holds,
            // so this header only governs the first fetch.
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
