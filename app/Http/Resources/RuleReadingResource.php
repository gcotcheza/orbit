<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Domain\Rules\RuleChip;
use App\Application\Rules\RuleMatch;
use App\Application\Rules\RuleReading;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sentence as this app reads it: chips, criteria, matches. Backs `POST /api/rules/parse` and
 * every stored rule (RuleResource builds on it) (docs/BUSINESS-LOGIC.md §11).
 */
final class RuleReadingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reading = $this->reading();
        $matches = $reading->matches;
        $cheapest = $matches->cheapest();

        return [
            /*
             * In the design's order (where, how much, how long, day, when, what for) — the screen
             * renders chips as given, order intact.
             */
            'chips' => array_map(
                static fn (RuleChip $chip): array => RuleChipResource::make($chip)->toArray($request),
                $reading->parsed->chips,
            ),

            /*
             * What the chips add up to — published so a client summary doesn't have to reconstruct
             * it from labels sized for a 352px chip.
             */
            'criteria' => $reading->criteria()->toArray(),

            'matches' => [
                /* Every match, not just the sampled ones — the banner's number. */
                'count' => $matches->count(),
                /*
                 * True means `count` is a floor the banner must say so — some routes have no fare
                 * yet, so the number can only grow (docs/BUSINESS-LOGIC.md §11).
                 */
                'partial' => $matches->partial(),
                /* NULL when nothing matched: no trips is not a €0 trip. */
                'cheapest' => $cheapest === null ? null : Euros::from($cheapest->cents),
                /* The handful a phone can show, cheapest first. */
                'sample' => array_map(
                    static fn (RuleMatch $match): array => RuleMatchResource::make($match)->toArray($request),
                    $matches->sample,
                ),
            ],
        ];
    }

    private function reading(): RuleReading
    {
        /** @var RuleReading $reading */
        $reading = $this->resource;

        return $reading;
    }
}
