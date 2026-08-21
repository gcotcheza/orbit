<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Application\Routes\WatchedRoute;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A watchlist row: the route summary plus the owner's toggle. PAUSED ROUTES ARE IN THE LIST,
 * because the switch that turns one back on lives on the row it turned off.
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
