<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Domain\Rules\RuleChip;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One removable chip (design/README.md §4). THE VALUE IS NOT PUBLISHED, only the id and the
 * words — sending the payload would invite a second fold in JavaScript.
 */
final class RuleChipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RuleChip $chip */
        $chip = $this->resource;

        return [
            /* Stable across re-parses of the same sentence — see RuleChip. */
            'id' => $chip->id,
            /* The eyebrow: "From", "Max price". Upper-casing is the design's job. */
            'category' => $chip->category(),
            /* The value under it: "AMS", "€80", "2–3 nights". */
            'label' => $chip->label,
        ];
    }
}
