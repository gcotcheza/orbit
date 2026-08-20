<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * What Google Flights says about a route and date. ⚠ Deliberately NOT "candidate price <
 * Google's typical low" — that would confirm everything (docs/BUSINESS-LOGIC.md §16).
 */
final readonly class GoogleVerdict
{
    /** Google's three words for a fare, as `price_insights.price_level`. */
    public const LEVEL_LOW = 'low';

    public function __construct(
        /** `low`, `typical`, `high` — or null when Google had no opinion. */
        public ?string $level,
        /** The cheapest seat Google itself could find, in cents; null if silent. */
        public ?int $lowestCents,
        /** `typical_price_range`, in cents. Null when Google published no band. */
        public ?int $typicalLowCents,
        public ?int $typicalHighCents,
    ) {}

    /**
     * Does Google's market agree this route and date are cheap? ⚠ The absent candidate price
     * in the signature is the decision, not an oversight (docs/BUSINESS-LOGIC.md §16).
     */
    public function confirmsCheap(): bool
    {
        if ($this->level === self::LEVEL_LOW) {
            return true;
        }

        if ($this->lowestCents === null || $this->typicalLowCents === null) {
            return false;
        }

        return $this->lowestCents <= $this->typicalLowCents;
    }

    /**
     * The row's `google_verdict` column, or null. `confirmed` is stored, not derivable — a
     * retuned rule must not rewrite history (docs/BUSINESS-LOGIC.md §16).
     *
     * @return array{level: string|null, lowest: int|null, typical_low: int|null, typical_high: int|null, confirmed: bool}
     */
    public function toArray(): array
    {
        return [
            'level'        => $this->level,
            'lowest'       => $this->lowestCents,
            'typical_low'  => $this->typicalLowCents,
            'typical_high' => $this->typicalHighCents,
            'confirmed'    => $this->confirmsCheap(),
        ];
    }
}
