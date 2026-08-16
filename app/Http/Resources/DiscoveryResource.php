<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Discovery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card on the "Deals from your airports" strip, as `GET /api/discoveries`
 * publishes it.
 *
 * IT IS NOT A RouteSummaryResource AND MUST NOT GROW INTO ONE. That shape is
 * the shared route document four screens read — score, tier, verdict,
 * sparkline, statistics — and every field in it descends from Orbit having
 * WATCHED a route for a while. A discovery has none of that by definition: no
 * history, no observations, no deal score, and a route row that usually does
 * not exist yet. Publishing a half-filled route summary would put `score: 0`
 * and `confident: false` on a card whose entire purpose is to say "this is
 * remarkable", which is the day-1 honesty rule pointing exactly the wrong way.
 *
 * SO THE CARD CARRIES ITS OWN EVIDENCE INSTEAD, and the fields below are that
 * evidence rather than a summary of it: what it costs, what a kilometre of it
 * costs, where it sat in its own window, what Google said, and how old the
 * price is. A reader can disbelieve every one of them independently.
 *
 * THE HAND-OFF IS `code` AND NOTHING ELSE. Tapping a discovery opens
 * `/route/AMS-AGP` — the same lookup flow "look before you watch" has used
 * since the search screen — which prices the pair, creates the route row and
 * offers the watch button. This resource publishes no booking link and no watch
 * action of its own: both already exist one screen along, and a second way to
 * book from a less verified surface is not something this feature should invent.
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
             * THE ROUTE CODE, which is the whole navigation contract — it is
             * what `/route/{code}` takes and what `[A-Z]{3}-[A-Z]{3}` in
             * routes/web.php constrains.
             */
            'code' => $discovery->code,

            'origin' => AirportResource::make($discovery->origin),
            'destination' => AirportResource::make($discovery->destination),

            'price' => Euros::from($discovery->price_cents),

            /*
             * A BARE `YYYY-MM-DD`, like every other DAY in this API. It names a
             * calendar day rather than a moment (docs/API.md's two axes), and
             * an ISO timestamp here would be read in the viewer's timezone and
             * land a day early for anybody west of London.
             */
            'departureDate' => $discovery->departure_date->toDateString(),

            /*
             * MILLIEUROS PER KILOMETRE, ROUNDED TO ONE DECIMAL — the unit the
             * thresholds are quoted in and the only one the numbers read
             * naturally in. €0.0108/km is four leading zeros to scan past; 10.8
             * is a figure somebody can compare two of at a glance.
             *
             * PUBLISHED EVEN THOUGH NO SCREEN PRINTS IT TODAY. It is the reason
             * this card is on the strip at all and the reason it is where it is
             * in the order — a client that showed the list without being able
             * to explain the order would be asking the reader to take the
             * ranking on faith.
             */
            'milliEurosPerKm' => round($discovery->cents_per_km * 10, 1),

            /*
             * THE CROSS-SECTIONAL EVIDENCE, or null when the window could not
             * be fetched. Null is a real answer — Travelpayouts' calendar
             * coverage is patchy and a discovery is by definition an obscure
             * pair — and the client draws no line rather than a zero.
             */
            'percentile' => $discovery->percentile === null ? null : round($discovery->percentile, 1),
            'savings' => $discovery->savings_cents === null ? null : Euros::from($discovery->savings_cents),

            /*
             * WHEN THE PRICE WAS FOUND — the third date, and the one this whole
             * app has learned to publish. An ISO timestamp WITH an offset,
             * because this one names a MOMENT rather than a day, and the client
             * turns it into "seen 2 days ago" (resources/js/lib/format.js).
             *
             * NULL RENDERS AS NOTHING AT ALL and never as "fresh". On this
             * table it should never be null — App\Domain\Discovery\
             * DiscoveryPolicy discards a candidate whose age is unknown — and
             * it is published as nullable anyway, because a resource that
             * assumed a domain invariant would be the thing that breaks when
             * the invariant is retuned.
             */
            'foundAt' => $discovery->found_at?->setTimezone((string) config('orbit.timezone'))->toIso8601String(),

            'verdict' => $this->verdict($discovery),
        ];
    }

    /**
     * The badge, and the evidence behind it.
     *
     * `verified` IS READ OFF THE STORED VERDICT AND NEVER RE-DERIVED. It is
     * what this row claimed when it was written, which is what the badge on the
     * card means — the same argument AlertResource makes for reading a ledger's
     * payload rather than today's statistics. A rule retuned next month must not
     * silently restate what last month's cards said.
     *
     * `label` IS THE SERVER'S WORD AND NOT THE CLIENT'S. "✓ Verified low by
     * Google" is a claim about a third party, and a hard-coded string in a Vue
     * component is a claim that goes on being made the day the check behind it
     * is turned off. The client styles by `verified`; it does not compose the
     * sentence.
     *
     * `googleLowest` IS THE MOST USEFUL FIELD ON THE CARD WHEN THE VERDICT IS
     * *NOT* CONFIRMED, which is why it is published either way. "Orbit says €27,
     * Google says €168" is a disagreement the reader is entitled to see — and
     * on the three finalists measured on 2026-08-16 it was the whole story.
     *
     * @return array{verified: bool, label: string, level: string|null, googleLowest: int|float|null, typicalLow: int|float|null, typicalHigh: int|float|null}
     */
    private function verdict(Discovery $discovery): array
    {
        $verified = $discovery->isVerified();
        $google = $discovery->google_verdict;

        return [
            'verified' => $verified,
            'label' => $verified ? 'Verified low by Google' : 'Unverified',
            'level' => is_string($google['level'] ?? null) ? $google['level'] : null,
            'googleLowest' => is_int($google['lowest'] ?? null) ? Euros::from($google['lowest']) : null,
            'typicalLow' => is_int($google['typical_low'] ?? null) ? Euros::from($google['typical_low']) : null,
            'typicalHigh' => is_int($google['typical_high'] ?? null) ? Euros::from($google['typical_high']) : null,
        ];
    }
}
