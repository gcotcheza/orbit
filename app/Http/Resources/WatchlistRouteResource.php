<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Routes\WatchedRoute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A watchlist row: the route summary plus the owner's toggle.
 *
 * PAUSED ROUTES ARE IN THE LIST, with `active: false`. They are what the
 * design's switch (§5) is drawn from, and hiding them would make turning one
 * back on impossible from the screen that turned it off.
 */
final class WatchlistRouteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WatchedRoute $watched */
        $watched = $this->resource;

        return [
            ...RouteSummaryResource::make($watched->snapshot)->toArray($request),
            'active' => $watched->active,
        ];
    }
}
