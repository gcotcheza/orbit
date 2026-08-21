<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Airport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One end of a route. `lat`/`lng` travel with the watchlist because the globe needs both ends
 * before it can draw an arc; `countryCode` keys the flag swatches (design/README.md §5).
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
            'iata'        => $airport->iata,
            'city'        => $airport->city,
            'country'     => $airport->country,
            'countryCode' => $airport->country_code,
            'lat'         => $airport->lat,
            'lng'         => $airport->lng,
        ];
    }
}
