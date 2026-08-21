<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Application\Rules\RuleView;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved rule: the reading plus the four facts that belong to the row. It composes
 * RuleReadingResource rather than extending it; paused rules stay in the list.
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
            'id'     => $rule->id,
            'text'   => $rule->raw_text,
            'active' => $rule->active,
            /* The list is newest first, and this is what says so. */
            'createdAt' => $rule->created_at->toIso8601String(),

            ...RuleReadingResource::make($view->reading)->toArray($request),
        ];
    }
}
