<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * One candidate the relative lane chose, and WHY it chose it.
 *
 * THE REASON TRAVELS WITH THE PICK because the two kinds cost the same request
 * and mean completely different things afterwards. A BASELINE pick is a claim
 * waiting to be confirmed — Orbit already believes this fare is 47% under what
 * the route usually costs and is spending a fetch to check. An EXPLORATION pick
 * is a question — Orbit has no idea what this route costs and is spending a
 * fetch to find out, with no expectation that a card comes of it.
 *
 * WITHOUT THIS DISTINCTION THE RUN CANNOT BE READ. Both kinds go through the
 * same verification and most exploration picks will fail it, which is CORRECT
 * and would otherwise look like a lane with a terrible hit rate. The log line
 * says how many of each were taken, so "we surfaced nothing and learned four
 * routes" is distinguishable from "we surfaced nothing and wasted four fetches".
 */
final readonly class RelativePick
{
    public function __construct(
        public DealCandidate $candidate,
        /**
         * WHY THIS ONE. `Baseline` means a remembered median said it was rare;
         * `Exploration` means the rotation reached it.
         */
        public PickReason $reason,
        /**
         * The remembered baseline, on a `Baseline` pick — null on exploration,
         * where the whole point is that there isn't one.
         */
        public ?RouteBaseline $baseline = null,
    ) {}

    /**
     * How far under its usual this fare looked BEFORE the window was fetched, or
     * null on an exploration pick.
     *
     * THE PREDICTION AND NOT THE RESULT. What the card eventually prints comes
     * from the freshly fetched window (`percentile`, `savings_cents`), exactly
     * as an absolute discovery's does. This number is only ever the reason a
     * request was spent, and it is deliberately not stored on the row: a claim
     * on a screen has to rest on the measurement that was made, not on the guess
     * that motivated it.
     */
    public function expectedDiscount(): ?float
    {
        return $this->baseline?->discountOf($this->candidate->cents);
    }
}
