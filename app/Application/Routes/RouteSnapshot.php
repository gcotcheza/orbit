<?php

declare(strict_types=1);

namespace App\Application\Routes;

use App\Models\Route;
use DateTimeImmutable;
use App\Domain\Pricing\DatedFare;
use App\Domain\Pricing\DealScore;
use App\Domain\Pricing\PriceStats;
use App\Domain\Pricing\PriceHistory;

/**
 * One route, as every screen needs it: the ends, the price, the judgement and
 * the series behind it — assembled once, so nothing recomputes mid-response.
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
     * Whether the cheapest DEPARTURE has probably gone — old AND well under usual, both halves
     * required, and a null `foundAt` is never demoted (docs/BUSINESS-LOGIC.md §17).
     */
    public function cheapestMayBeGone(DateTimeImmutable $now, int $staleAfterHours, int $underUsualPercent): bool
    {
        $foundAt = $this->cheapest?->foundAt;

        if ($this->cheapest === null || $foundAt === null || $this->stats === null) {
            return false;
        }

        $ageHours = ($now->getTimestamp() - $foundAt->getTimestamp()) / 3600;

        if ($ageHours <= $staleAfterHours) {
            return false;
        }

        return $this->stats->percentUnderUsual($this->cheapest->cents) >= $underUsualPercent;
    }

    /**
     * "38% below its usual €84" — negative when above usual, NULL when either half of the
     * comparison is missing, which the client renders as no caption rather than 0%.
     */
    public function percentUnderUsual(): ?int
    {
        if ($this->currentCents === null || $this->stats === null) {
            return null;
        }

        return $this->stats->percentUnderUsual($this->currentCents);
    }
}
