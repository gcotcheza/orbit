<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Pwa\OfflineController;
use App\Http\Controllers\Pwa\ManifestController;
use App\Http\Controllers\Pwa\ServiceWorkerController;

/*
|--------------------------------------------------------------------------
| The PWA shell — three routes, registered with NO middleware group
|--------------------------------------------------------------------------
|
| Public and session-free on purpose: docs/BUSINESS-LOGIC.md §35.
|
*/

Route::get('/manifest.webmanifest', ManifestController::class)->name('pwa.manifest');
Route::get('/sw.js', ServiceWorkerController::class)->name('pwa.sw');
Route::get('/offline', OfflineController::class)->name('pwa.offline');
