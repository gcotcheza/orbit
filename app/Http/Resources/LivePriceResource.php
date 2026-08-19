<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Models\LivePriceCheck;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What Google said when somebody pressed "Check live price" — `meta.liveCheck`
 * on the route detail (docs/API.md).
 *
 * ⚠ A null `lowest` is "asked, no opinion" and never reassurance: it is never
 * filled in from Orbit's own price. docs/BUSINESS-LOGIC.md §17.
 */
final class LivePriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LivePriceCheck $check */
        $check = $this->resource;

        $verdict = $check->google_verdict;
        $lowest = $check->lowestCents();

        return [
            'date'   => $check->departure_date->toDateString(),
            'lowest' => $lowest === null ? null : Euros::from($lowest),

            /* Google's "usual", from a market Orbit cannot see — deliberately a
               second opinion beside Orbit's own statistics. */
            'typicalLow'  => is_int($verdict['typical_low'] ?? null) ? Euros::from($verdict['typical_low']) : null,
            'typicalHigh' => is_int($verdict['typical_high'] ?? null) ? Euros::from($verdict['typical_high']) : null,

            'level' => is_string($verdict['level'] ?? null) ? $verdict['level'] : null,

            /* When Orbit asked, and what the cooldown is measured from. */
            'checkedAt' => $check->checked_at->setTimezone((string) config('orbit.timezone'))->toIso8601String(),
        ];
    }
}
