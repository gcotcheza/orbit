<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Discovery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deal-strip card, deliberately NOT a RouteSummaryResource (no score/history — a discovery has none); carries its own evidence instead, and hands off
 * via `code` only (no booking/watch link of its own — /route/{code} already offers both) (docs/BUSINESS-LOGIC.md §16).
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
             * `lane` (absolute/relative) drives the client's card sentence — absolute needs no extra words, relative must say "rare for THIS ROUTE" since its per-km
             * price is ordinary. String, not boolean, matching App\Domain\Discovery\Lane (docs/BUSINESS-LOGIC.md §16).
             */
            'lane' => $discovery->lane->value,

            'origin'      => AirportResource::make($discovery->origin),
            'destination' => AirportResource::make($discovery->destination),

            'price' => Euros::from($discovery->price_cents),

            /**
             * Bare YYYY-MM-DD (a calendar day, not a moment — docs/API.md's two axes): an ISO timestamp here would be read in the
             * viewer's timezone and land a day early west of London (docs/BUSINESS-LOGIC.md §16).
             */
            'departureDate' => $discovery->departure_date->toDateString(),

            /**
             * Milli-euros/km, 1dp — readable vs €0.0108/km's leading zeros. Published even though no screen prints it yet: it's
             * why the card is on the strip and its order (docs/BUSINESS-LOGIC.md §16).
             */
            'milliEurosPerKm' => round($discovery->cents_per_km * 10, 1),

            /**
             * Percentile, or null when the window couldn't be fetched — null is a real answer here (patchy coverage on obscure
             * pairs); the client draws no line rather than a zero (docs/BUSINESS-LOGIC.md §16).
             */
            'percentile' => $discovery->percentile === null ? null : round($discovery->percentile, 1),
            'savings'    => $discovery->savings_cents === null ? null : Euros::from($discovery->savings_cents),

            /**
             * ISO timestamp with offset (a moment, not a day — client renders "seen 2 days ago" via resources/js/lib/format.js). Nullable on purpose even though it
             * should never be null in practice: a resource that assumed that invariant would break if it's retuned (docs/BUSINESS-LOGIC.md §16).
             */
            'foundAt' => $discovery->found_at?->setTimezone((string) config('orbit.timezone'))->toIso8601String(),

            'verdict' => $this->verdict($discovery),
        ];
    }

    /**
     * `verified` is read off the stored verdict, never re-derived (same argument as AlertResource — a retuned rule must not restate what last month's card said). `label` is server-composed, not
     * client-composed. `googleLowest` publishes even when unconfirmed, since the disagreement itself is evidence the reader should see (docs/BUSINESS-LOGIC.md §16).
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
