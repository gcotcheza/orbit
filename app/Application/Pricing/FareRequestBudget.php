<?php

declare(strict_types=1);

namespace App\Application\Pricing;

use App\Models\Route;
use App\Models\DealRule;
use Psr\Log\LoggerInterface;
use App\Domain\Pricing\ProviderRun;
use App\Domain\Pricing\RequestBudget;

/**
 * Today's watchlist against the two limits the morning has to fit inside — the
 * provider's hourly allowance and the alert run's clock (docs/BUSINESS-LOGIC.md §13, §27).
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

    public const ALERTS_AT = '07:35';

    public const ROLLING_WINDOW_MINUTES = 60;

    public function __construct(private LoggerInterface $logger) {}

    public function onSaturday(int $watchedRoutes, int $activeRules): RequestBudget
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

    public function alertRunClears(int $watchedRoutes): bool
    {
        return $this->pollFinishesAt($watchedRoutes) <= ProviderRun::minuteOfDay(self::ALERTS_AT);
    }

    /**
     * Reads the watchlist as it is now and says so at ERROR against both limits
     * — they are different problems, and the fix for one worsens the other.
     *
     * @return list<string> the sentences it logged
     */
    public function warnAboutBreaches(): array
    {
        $watchedRoutes = Route::onWatchlist()->count();
        $activeRules = DealRule::query()->where('active', true)->count();
        $stagger = (int) config('orbit.poll.stagger_minutes');

        $limit = (int) config('orbit.travelpayouts.hourly_request_limit');
        $budget = $this->onSaturday($watchedRoutes, $activeRules);

        $clock = $budget->peak();
        $rolling = $budget->rollingPeak(self::ROLLING_WINDOW_MINUTES);

        $sentences = [];

        if ($budget->exceeds($limit)) {
            $worst = $rolling > $clock
                ? sprintf('the %d minutes from %s cost %d requests', self::ROLLING_WINDOW_MINUTES, self::clock($budget->rollingPeakStartsAt(self::ROLLING_WINDOW_MINUTES)), $rolling)
                : sprintf('the %02d:00 clock hour costs %d requests', $budget->busiestHour(), $clock);

            $sentences[] = $sentence = sprintf(
                'The morning schedule asks Travelpayouts for more than it allows on Saturday: %s of ~%d (watched routes: %d, active deal rules: %d). Widen orbit.poll.stagger_minutes or move a run, and check both measures — a wider stagger lowers the clock hour and can raise the rolling window — docs/BUSINESS-LOGIC.md §27.',
                $worst,
                $limit,
                $watchedRoutes,
                $activeRules,
            );

            $this->logger->error($sentence, [
                'limit'                   => 'provider_hourly_requests',
                'watched_routes'          => $watchedRoutes,
                'active_rules'            => $activeRules,
                'stagger_minutes'         => $stagger,
                'hourly_limit'            => $limit,
                'clock_hour_peak'         => $clock,
                'rolling_window_peak'     => $rolling,
                'rolling_window_starts'   => self::clock($budget->rollingPeakStartsAt(self::ROLLING_WINDOW_MINUTES)),
                'requests_per_clock_hour' => $budget->perClockHour(),
            ]);
        }

        if (! $this->alertRunClears($watchedRoutes)) {
            $sentences[] = $sentence = sprintf(
                'The alert run no longer sees every route: at %d watched routes the last fare poll is dispatched %s and needs until %s, after orbit:alerts at %s. Widening orbit.poll.stagger_minutes fixes the request budget and makes THIS worse — move orbit:alerts later, which it can be only until 08:00 when quiet hours release the mail, or shorten the fan-out — docs/BUSINESS-LOGIC.md §13.',
                $watchedRoutes,
                self::clock($this->lastPollDispatchMinute($watchedRoutes)),
                self::clock($this->pollFinishesAt($watchedRoutes)),
                self::ALERTS_AT,
            );

            $this->logger->error($sentence, [
                'limit'                   => 'alert_run_clearance',
                'watched_routes'          => $watchedRoutes,
                'stagger_minutes'         => $stagger,
                'last_poll_dispatched_at' => self::clock($this->lastPollDispatchMinute($watchedRoutes)),
                'poll_completion_minutes' => $this->pollCompletionMinutes(),
                'alerts_at'               => self::ALERTS_AT,
            ]);
        }

        return $sentences;
    }

    private function lastPollDispatchMinute(int $watchedRoutes): int
    {
        return ProviderRun::minuteOfDay(self::NEAR_POLL_AT)
            + (int) config('orbit.poll.stagger_minutes') * max(0, $watchedRoutes - 1);
    }

    private function pollFinishesAt(int $watchedRoutes): int
    {
        return $this->lastPollDispatchMinute($watchedRoutes) + $this->pollCompletionMinutes();
    }

    /**
     * A poll job's worst case in whole minutes, from the adapter's own timeouts.
     * Horizon kills it sooner — docs/DECISIONS.md, the-stagger-buys-the-headroom-and-a-guard-keeps-it.
     */
    private function pollCompletionMinutes(): int
    {
        $attempts = (int) config('orbit.travelpayouts.retries') + 1;
        $seconds = self::NEAR_MONTHS * (
            $attempts * (int) config('orbit.travelpayouts.timeout')
            + ($attempts - 1) * (int) config('orbit.travelpayouts.retry_delay_ms') / 1000
        );

        return (int) ceil($seconds / 60);
    }

    private function sweepRequests(int $activeRules): int
    {
        return $activeRules * (int) config('orbit.rules.sweep_cap') * self::SWEEP_MONTHS;
    }

    private function discoveryRequests(): int
    {
        $origins = (array) config('orbit.origins');

        $shortlisted = (int) config('orbit.discovery.shortlist')
            + (int) config('orbit.discovery.lanes.relative.shortlist');

        return count($origins) + $shortlisted * self::NEAR_MONTHS;
    }

    private static function clock(int $minuteOfDay): string
    {
        return sprintf('%02d:%02d', intdiv($minuteOfDay, 60) % 24, $minuteOfDay % 60);
    }
}
