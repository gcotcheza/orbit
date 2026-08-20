<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Discovery;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;
use App\Http\Resources\DiscoveryResource;

/**
 * The current set of discoveries — a pure read of a precomputed table, no parameters, behind
 * auth:sanctum. Empty `data: []` is a real and common answer (docs/BUSINESS-LOGIC.md §16).
 */
final class DiscoveryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /*
         * Owner's clock, not UTC — `live` compares departure DATE to today, and UTC would hide
         * a discovery from a reader still on yesterday locally (docs/BUSINESS-LOGIC.md §16).
         */
        $now = Date::now((string) config('orbit.timezone'))->toImmutable();

        $discoveries = Discovery::query()
            /*
             * Eager-load: the resource reads both airports per row, so lazy loading would be an
             * N+1 on a list meant to render in one paint.
             */
            ->with(['origin', 'destination'])
            ->live($now)
            ->get();

        return DiscoveryResource::collection($discoveries)
            ->additional(['meta' => [
                'count' => $discoveries->count(),

                /*
                 * "Found this morning", not "checked when opened"; null on an empty set is honest.
                 * Owner-timezone timestamp, like meta.fares.fetchedAt (docs/BUSINESS-LOGIC.md §16).
                 */
                'discoveredAt' => $discoveries
                    ->max('discovered_at')
                    ?->setTimezone((string) config('orbit.timezone'))
                    ->toIso8601String(),
            ]])
            ->response();
    }
}
