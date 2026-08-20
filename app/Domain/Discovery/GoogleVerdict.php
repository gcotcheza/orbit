<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * What Google Flights says about a route and date, as a value Orbit can act on.
 *
 * Built from SerpAPI's `price_insights` — the only opinion in the funnel
 * that doesn't descend from the same Travelpayouts cache everything else does.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * ⚠ Deliberately NOT "candidate price < Google's typical low" — that
 * would confirm everything, including a measured 6x discrepancy.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * `lowest` is carried even though the rule doesn't need it — it's the
 * most useful card fact for a reader deciding whether to trust a fare.
 * Why: docs/BUSINESS-LOGIC.md §36.
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
     * Does Google's market agree that this route and date are cheap right now?
     *
     * ⚠ Read the class docblock before changing this — the absent candidate
     * price in the signature is the decision, not an oversight.
     * Why: docs/BUSINESS-LOGIC.md §36.
     *
     * A verdict with nothing in it confirms nothing — no `price_insights` is
     * a real "no opinion" answer, common on thin routes, not permission.
     * Why: docs/BUSINESS-LOGIC.md §36.
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
     * The row's `google_verdict` column, or null when there is nothing to say.
     *
     * `confirmed` is stored, not just derivable — a screen recomputing it from
     * parts is how two places disagree; a retuned rule must not rewrite history.
     * Why: docs/BUSINESS-LOGIC.md §36.
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
