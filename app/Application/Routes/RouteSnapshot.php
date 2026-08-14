<?php

declare(strict_types=1);

namespace App\Application\Routes;

use App\Domain\Pricing\DatedFare;
use App\Domain\Pricing\DealScore;
use App\Domain\Pricing\PriceHistory;
use App\Domain\Pricing\PriceStats;
use App\Models\Route;

/**
 * One route, as every screen needs it: the ends, the price, the judgement and
 * the series behind it.
 *
 * WHY A VIEW MODEL RATHER THAN THE ELOQUENT MODEL. Half of what a screen shows
 * is not in any column — the score, the verdict, the percentage under usual,
 * the sparkline — and the alternative to assembling it once here is accessors
 * on the model that each run their own query the first time they are touched.
 * That is the shape N+1s and inconsistent snapshots come from: the API would
 * be able to answer with a score computed against statistics a later line
 * re-read after a refresh job had rewritten them.
 *
 * It holds the Route MODEL rather than copying its columns, because that half
 * really is plain CRUD and docs/PLAN.md is explicit that Eloquent is used
 * directly for it. The relations it exposes (`origin`, `destination`) are
 * eager-loaded by RouteSnapshots before this is built.
 */
final readonly class RouteSnapshot
{
    public function __construct(
        public Route $route,
        /** The last observation's price: what the design's big number shows. */
        public ?int $currentCents,
        public ?PriceStats $stats,
        /** Newest-last, trimmed to config('orbit.history.chart_days'). */
        public PriceHistory $history,
        public DealScore $deal,
        /** Calendar days since the FIRST observation we actually hold. */
        public int $trackingDays,
        /** Cheapest bookable departure in the poll window, if there is one. */
        public ?DatedFare $cheapest,
    ) {}

    public function usualCents(): ?int
    {
        return $this->stats?->usualCents();
    }

    /**
     * "38% below its usual €84" — negative when the fare is above usual. NULL
     * when either half of the comparison is missing, which the client renders
     * as no caption rather than as 0%.
     */
    public function percentUnderUsual(): ?int
    {
        if ($this->currentCents === null || $this->stats === null) {
            return null;
        }

        return $this->stats->percentUnderUsual($this->currentCents);
    }
}
