<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Application\Rules\RuleMatch;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One trip a rule found. It reuses AirportResource for both ends so the rule rows can draw
 * city names and flags; `watched` is what lets the one-tap button be honest.
 */
final class RuleMatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RuleMatch $match */
        $match = $this->resource;
        $route = $match->route;

        return [
            'code'        => $route->code,
            'origin'      => AirportResource::make($route->origin)->toArray($request),
            'destination' => AirportResource::make($route->destination)->toArray($request),

            /*
             * The cheapest DEPARTURE that fits the rule, not the route's cheapest fare:
             * quoting Tuesday's €38 beside a rule about Fridays is a price nobody can book.
             */
            'cheapest' => [
                'date'  => $match->cheapest->departureDate->format('Y-m-d'),
                'price' => Euros::from($match->cheapest->cents),
            ],

            'watched' => $match->watched,
        ];
    }
}
