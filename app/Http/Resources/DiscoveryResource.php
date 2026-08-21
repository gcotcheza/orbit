<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Discovery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deal-strip card, deliberately NOT a RouteSummaryResource — a discovery has no score or history,
 * so it carries its own evidence (docs/BUSINESS-LOGIC.md §16).
 */
final class DiscoveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Discovery $discovery */
        $discovery = $this->resource;

        return [
            /*
             * Route code: the navigation contract for /route/{code}, constrained by
             * [A-Z]{3}-[A-Z]{3} in routes/web.php.
             */
            'code' => $discovery->code,

            /**
             * `lane` drives the client's card sentence: absolute needs no extra words, relative
             * must say "rare for THIS ROUTE". A string, matching the enum.
             */
            'lane' => $discovery->lane->value,

            'origin'      => AirportResource::make($discovery->origin),
            'destination' => AirportResource::make($discovery->destination),

            'price' => Euros::from($discovery->price_cents),

            /**
             * Bare YYYY-MM-DD, a calendar day and not a moment: an ISO timestamp would be read in
             * the viewer's timezone and land a day early west of London.
             */
            'departureDate' => $discovery->departure_date->toDateString(),

            /**
             * Milli-euros/km, one decimal — readable next to €0.0108/km. Published though no screen
             * prints it: it is why the card is on the strip.
             */
            'milliEurosPerKm' => round($discovery->cents_per_km * 10, 1),

            /**
             * Percentile, or null when the window could not be fetched — null is a real answer on
             * obscure pairs, and the client draws no line rather than a zero.
             */
            'percentile' => $discovery->percentile === null ? null : round($discovery->percentile, 1),
            'savings'    => $discovery->savings_cents === null ? null : Euros::from($discovery->savings_cents),

            /**
             * ISO timestamp with offset (a moment, not a day). Nullable on purpose even though it
             * should never be null: an assumed invariant breaks when retuned.
             */
            'foundAt' => $discovery->found_at?->setTimezone((string) config('orbit.timezone'))->toIso8601String(),

            'verdict' => $this->verdict($discovery),
        ];
    }

    /**
     * `verified` is read off the stored verdict, never re-derived, and `label` is server-composed.
     * `googleLowest` publishes even when unconfirmed.
     *
     * @return array{verified: bool, label: string, level: string|null, googleLowest: int|float|null, typicalLow: int|float|null, typicalHigh: int|float|null}
     */
    private function verdict(Discovery $discovery): array
    {
        $verified = $discovery->isVerified();
        $google = $discovery->google_verdict;

        return [
            'verified'     => $verified,
            'label'        => $verified ? 'Verified low by Google' : 'Unverified',
            'level'        => is_string($google['level'] ?? null) ? $google['level'] : null,
            'googleLowest' => is_int($google['lowest'] ?? null) ? Euros::from($google['lowest']) : null,
            'typicalLow'   => is_int($google['typical_low'] ?? null) ? Euros::from($google['typical_low']) : null,
            'typicalHigh'  => is_int($google['typical_high'] ?? null) ? Euros::from($google['typical_high']) : null,
        ];
    }
}
