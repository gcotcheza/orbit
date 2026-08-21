<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Airport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row in the add-route form's destination list, shared by both endpoints that fill it —
 * deliberately narrower than AirportResource, which is the same table.
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
            'iata'        => $airport->iata,
            'city'        => $airport->city,
            'country'     => $airport->country,
            'countryCode' => $airport->country_code,
        ];
    }
}
