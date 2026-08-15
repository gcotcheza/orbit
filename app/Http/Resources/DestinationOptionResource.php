<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Airport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row in the add-route form's destination list.
 *
 * DELIBERATELY NARROWER THAN AirportResource, which is the same table. That one
 * travels inside a watchlist row and carries `lat`/`lng` because the globe
 * cannot draw an arc without them; this one is a list of places to pick from,
 * and the four fields below are exactly what the suggestion row prints —
 * "Bilbao · BIO — Spain" — and what it filters on. Coordinates for eighty
 * airports the form will never plot is payload nobody reads.
 *
 * `countryCode` is here for the same flag swatches the boarding passes use
 * (design/README.md §5), so a suggestion can look like the row it will become.
 */
final class DestinationOptionResource extends JsonResource
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
        ];
    }
}
