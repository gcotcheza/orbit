<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Airport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One end of a route.
 *
 * `lat`/`lng` are here for the globe, which needs both ends of every watched
 * route before it can draw a single arc — so they travel with the watchlist
 * rather than being fetched per route when the camera reaches one.
 *
 * `countryCode` is what the design's CSS-gradient flag swatches key off (§5).
 * The full country name is next to it because the route detail prints it.
 */
final class AirportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Airport $airport */
        $airport = $this->resource;

        return [
            'iata' => $airport->iata,
            'city' => $airport->city,
            'country' => $airport->country,
            'countryCode' => $airport->country_code,
            'lat' => $airport->lat,
            'lng' => $airport->lng,
        ];
    }
}
