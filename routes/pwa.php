<?php

declare(strict_types=1);

use App\Http\Controllers\Pwa\ManifestController;
use App\Http\Controllers\Pwa\OfflineController;
use App\Http\Controllers\Pwa\ServiceWorkerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The PWA shell — three routes, registered with NO middleware group
|--------------------------------------------------------------------------
|
| Loaded from bootstrap/app.php's `then:` callback, which runs outside both the
| `web` and `api` groups. Global middleware (TrustProxies, TrustHosts) still
| applies: that is framework-wide, not group-scoped.
|
| WHY THEY ARE PUBLIC
|
| The manifest is read by the OS at "Add to Home Screen", which is not an
| authenticated context. The service worker is registered from the login screen
| as much as from the app — routes/web.php serves one shell to both — and a
| 302-to-login handed back as `application/javascript` would install a login
| page as a worker. The offline page is what renders when nothing can reach the
| network, and a redirect is precisely the thing that cannot be followed then.
|
| None of the three exposes anything: an app name, two colours, a list of build
| filenames the HTML already links to, and a page of static prose.
|
| WHY THEY CARRY NO SESSION — the reason this file exists rather than three more
| lines in routes/web.php
|
| `SESSION_DRIVER=database`, and a browser revalidates `/sw.js` on EVERY
| navigation. Inside the `web` group each of those revalidations would start a
| session, write a `sessions` row for a visitor who is not one, and hand back a
| Set-Cookie — and a response with a Set-Cookie is a response Cloudflare will
| not hold, so the manifest and the offline page would stop being edge-cacheable
| as well. None of the three reads a session, a CSRF token or a user, so none of
| them needs the group. tests/Feature/PwaShellTest asserts they still carry no
| cookie, because that is the kind of thing a later `->middleware('web')` undoes
| silently.
|
| WHY THE ORDER THEY ARE REGISTERED IN MATTERS: see bootstrap/app.php.
|
*/

Route::get('/manifest.webmanifest', ManifestController::class)->name('pwa.manifest');
Route::get('/sw.js', ServiceWorkerController::class)->name('pwa.sw');
Route::get('/offline', OfflineController::class)->name('pwa.offline');
