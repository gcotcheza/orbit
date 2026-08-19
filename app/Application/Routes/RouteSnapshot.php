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
     * Whether the cheapest fare is the kind that has probably already gone —
     * old AND well under this route's usual price.
     *
     * =========================================================================
     * BOTH HALVES ARE REQUIRED, AND THE COMBINATION IS THE WHOLE RULE
     * =========================================================================
     * A four-day-old fare AT its usual price is the ordinary state of a quiet
     * route and disappoints nobody. A fare 40% under usual found an hour ago is
     * exactly what this app is for, at full volume. It is the pair that is the
     * trap — cheap enough to be the reason somebody opened the screen, old
     * enough to be the first kind of fare to disappear — and DUS→VCE at €36
     * against a usual €62, seen three days earlier and unbuyable at any price
     * near it, is the fare this method is named after. config/orbit.php,
     * `live_check`, carries the argument and the two numbers.
     *
     * THE THRESHOLDS ARE PASSED IN, like every policy number in this app: this
     * is Application code, and a `config()` call here would be a second place
     * the rule is defined, next to the resource that draws it.
     *
     * IT READS THE CHEAPEST DEPARTURE, NOT `currentCents`. They are the same
     * number on almost every screen, by two different routes — one is the
     * morning's observation, the other is the row in the calendar — and only
     * one of them carries a `found_at`. A demotion drawn from an age belongs to
     * the fare that HAS that age.
     *
     * A NULL `foundAt` IS NEVER DEMOTED. It means "we do not know how old this
     * is" (App\Domain\Pricing\DatedFare), which is the state of every row
     * written before that column existed and of any provider that will not say.
     * Demoting on not-knowing would grey out a whole database on the morning it
     * shipped — the same reading App\Domain\Alerts\AlertPolicy takes of the same
     * null, for the same reason.
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
