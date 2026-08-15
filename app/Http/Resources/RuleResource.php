<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Rules\RuleView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved rule: the reading, plus the four facts that belong to the row rather
 * than to the sentence.
 *
 * IT COMPOSES RuleReadingResource RATHER THAN EXTENDING IT — the two hold
 * different resources (a RuleView and a RuleReading), and inheritance whose
 * parent could never be handed this object is a relationship that only looks
 * like one. Composition is also what keeps the parse endpoint's shape a
 * literal subset of this one, which is what lets the create screen hand its
 * last parse straight to the create call.
 *
 * `text` IS WHAT WAS TYPED and is not derivable from the chips — a rule whose
 * chips say "From AMS · Max €80" could have been written a dozen ways, and the
 * one the owner chose is the one the textarea should show when they come back
 * to it.
 *
 * PAUSED RULES ARE IN THE LIST with `active: false`, exactly like paused
 * watchlist rows: the switch that turns one back on lives on the row it turned
 * off, so hiding it would make the action impossible from the only screen that
 * offers it.
 */
final class RuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RuleView $view */
        $view = $this->resource;
        $rule = $view->rule;

        return [
            'id' => $rule->id,
            'text' => $rule->raw_text,
            'active' => $rule->active,
            /* The list is newest first, and this is what says so. */
            'createdAt' => $rule->created_at->toIso8601String(),

            ...RuleReadingResource::make($view->reading)->toArray($request),
        ];
    }
}
