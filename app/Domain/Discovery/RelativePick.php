<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * One candidate the relative lane chose, and WHY — a `Baseline` pick claims savings a remembered
 * median predicted; an `Exploration` pick has none (docs/BUSINESS-LOGIC.md §16).
 */
final readonly class RelativePick
{
    public function __construct(
        public DealCandidate $candidate,
        /**
         * WHY THIS ONE. `Baseline` means a remembered median said it was rare; `Exploration` means
         * the rotation reached it.
         */
        public PickReason $reason,
        /**
         * The remembered baseline, on a `Baseline` pick — null on exploration, where the whole
         * point is that there isn't one.
         */
        public ?RouteBaseline $baseline = null,
    ) {}

    /**
     * The pre-fetch prediction, not the result — the card prints from the freshly fetched window
     * instead. Deliberately not stored on the row (docs/BUSINESS-LOGIC.md §16).
     */
    public function expectedDiscount(): ?float
    {
        return $this->baseline?->discountOf($this->candidate->cents);
    }
}
