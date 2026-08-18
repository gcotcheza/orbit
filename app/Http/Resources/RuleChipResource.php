<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Domain\Rules\RuleChip;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One removable chip (design/README.md §4).
 *
 * THE VALUE IS NOT PUBLISHED, only the id and the words. A chip's payload is
 * how App\Domain\Rules\ParsedRule folds criteria back together and is nobody
 * else's business — the client's whole job is to draw `category` over `label`
 * and send `id` back in `removed` when the × is tapped. Sending the payload
 * too would invite a second implementation of the fold in JavaScript, and two
 * folds is one of them being wrong.
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
