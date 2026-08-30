<?php

declare(strict_types=1);

namespace App\Application\Pricing;

use App\Models\Route;
use App\Models\DealRule;
use Psr\Log\LoggerInterface;
use App\Domain\Pricing\ProviderRun;
use App\Domain\Pricing\RequestBudget;

/**
 * Today's watchlist against Travelpayouts' hourly allowance — the arithmetic
 * docs/BUSINESS-LOGIC.md §27 used to carry by hand, and nothing re-checked.
 */
final readonly class FareRequestBudget
{
    /** Measured, not assumed: `TravelpayoutsPriceProviderTest` bills each window against the API. */
    public const NEAR_MONTHS = 7;

    public const FAR_MONTHS = 12;

    public const SWEEP_MONTHS = 4;

    public const RETURN_REQUESTS_PER_ROUTE = 1;

    /** The clock in `routes/console.php`; `ScheduleTest` fails when the two part. */
    public const FAR_POLL_AT = '04:10';

    public const RETURNS_POLL_AT = '04:40';

    public const DISCOVERY_AT = '05:20';

    public const NEAR_POLL_AT = '06:10';

    public const RULE_SWEEP_AT = '06:40';

    public function __construct(private LoggerInterface $logger) {}

    /**
     * The busiest day of the week — the one the weekly far poll also runs on.
     */
    public function at(int $watchedRoutes, int $activeRules): RequestBudget
    {
        return new RequestBudget(
            [
                ProviderRun::fanOut('far poll', self::FAR_POLL_AT, self::FAR_MONTHS),
                ProviderRun::fanOut('returns poll', self::RETURNS_POLL_AT, self::RETURN_REQUESTS_PER_ROUTE),
                ProviderRun::single('discovery', self::DISCOVERY_AT, $this->discoveryRequests()),
                ProviderRun::fanOut('fare poll', self::NEAR_POLL_AT, self::NEAR_MONTHS),
                ProviderRun::single('rule sweep', self::RULE_SWEEP_AT, $this->sweepRequests($activeRules)),
            ],
            (int) config('orbit.poll.stagger_minutes'),
            $watchedRoutes,
        );
    }

    /**
     * Reads the watchlist as it is now, and says so at ERROR when the schedule
     * no longer fits. Returns the sentence it logged, or null.
     */
    public function warnIfBreached(): ?string
    {
        $watchedRoutes = Route::onWatchlist()->count();
        $activeRules = DealRule::query()->where('active', true)->count();

        $limit = (int) config('orbit.travelpayouts.hourly_request_limit');
        $budget = $this->at($watchedRoutes, $activeRules);

        if (! $budget->exceeds($limit)) {
            return null;
        }

        $sentence = sprintf(
            'The morning schedule asks Travelpayouts for more than it allows: the %02d:00 hour costs %d requests of ~%d (watched routes: %d, active deal rules: %d). Widen orbit.poll.stagger_minutes or move a run — docs/BUSINESS-LOGIC.md §27.',
            $budget->busiestHour(),
            $budget->peak(),
            $limit,
            $watchedRoutes,
            $activeRules,
        );

        $this->logger->error($sentence, [
            'watched_routes'          => $watchedRoutes,
            'active_rules'            => $activeRules,
            'stagger_minutes'         => (int) config('orbit.poll.stagger_minutes'),
            'hourly_limit'            => $limit,
            'requests_per_clock_hour' => $budget->perClockHour(),
        ]);

        return $sentence;
    }

    private function sweepRequests(int $activeRules): int
    {
        return $activeRules * (int) config('orbit.rules.sweep_cap') * self::SWEEP_MONTHS;
    }

    private function discoveryRequests(): int
    {
        /** @var list<string> $origins */
        $origins = config('orbit.origins');

        $shortlisted = (int) config('orbit.discovery.shortlist')
            + (int) config('orbit.discovery.lanes.relative.shortlist');

        return count($origins) + $shortlisted * self::NEAR_MONTHS;
    }
}
