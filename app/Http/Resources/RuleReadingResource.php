<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Rules\RuleMatch;
use App\Application\Rules\RuleReading;
use App\Domain\Rules\RuleChip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sentence as this app reads it: the chips, the criteria, and what it finds.
 *
 * THE WHOLE ANSWER TO `POST /api/rules/parse`, and the core of every stored
 * rule (RuleResource builds on it). One shape, two screens — the same
 * arrangement RouteSummaryResource and WatchlistRouteResource have, and for
 * the same reason: the create screen and the watch screen draw the same three
 * things, and a chip list that meant something slightly different on one of
 * them is the expensive kind of mistake.
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
             * In the design's order — where from, how much, how long, which
             * day, when, what for — because the screen renders them in the
             * order they arrive and the order is a reading of the sentence.
             */
            'chips' => array_map(
                static fn (RuleChip $chip): array => RuleChipResource::make($chip)->toArray($request),
                $reading->parsed->chips,
            ),

            /*
             * WHAT THE CHIPS ADD UP TO, published because it is what gets
             * saved and a client that shows a summary ("from AMS, EIN or DUS
             * under €80") should not have to reconstruct it from labels meant
             * for a 352 px chip.
             */
            'criteria' => $reading->criteria()->toArray(),

            'matches' => [
                /* Every match, not just the sampled ones — the banner's number. */
                'count' => $matches->count(),
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
