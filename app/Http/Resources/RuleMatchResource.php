<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Rules\RuleMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One trip a rule found.
 *
 * IT REUSES AirportResource for both ends rather than sending two IATA codes.
 * The watch screen's rule rows show city names and flag swatches exactly like
 * the boarding-pass rows above them (§5), and those are drawn from
 * `countryCode` — a match that sent less would need a second request per row
 * to draw the same thing.
 *
 * `watched` IS WHY THE ONE-TAP BUTTON CAN BE HONEST: a match already on the
 * watchlist shows as watched instead of offering to add it again and getting
 * back "You are already watching AMS-LIS."
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
            'code' => $route->code,
            'origin' => AirportResource::make($route->origin)->toArray($request),
            'destination' => AirportResource::make($route->destination)->toArray($request),

            /*
             * The cheapest DEPARTURE that fits the rule — not the route's
             * cheapest fare. A rule that says "leaving Friday under €80" is
             * answered by the cheapest Friday, and quoting Tuesday's €38 next
             * to a rule about Fridays would be a price nobody can book.
             */
            'cheapest' => [
                'date' => $match->cheapest->departureDate->format('Y-m-d'),
                'price' => Euros::from($match->cheapest->cents),
            ],

            'watched' => $match->watched,
        ];
    }
}
