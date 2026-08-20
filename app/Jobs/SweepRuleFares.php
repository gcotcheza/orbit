<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Route;
use App\Models\Airport;
use App\Models\DealRule;
use App\Models\CalendarFare;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\Date;
use App\Application\Rules\RuleMatches;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Go and find out what a rule is worth. Queued on rule creation and daily after the watchlist poll, so a new rule isn't empty on day one. Creates
 * `routes` rows for unwatched pairs on purpose, and caps + shortens the horizon to protect the provider's rate limit (docs/BUSINESS-LOGIC.md §11).
 */
final class SweepRuleFares implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $ruleId) {}

    public function handle(RuleMatches $matches, LoggerInterface $logger): void
    {
        $rule = DealRule::query()->find($this->ruleId);

        if ($rule === null || ! $rule->active) {
            return;
        }

        $codes = $matches->candidateCodes($rule->criteria());

        if ($codes === []) {
            return;
        }

        $fresh = $this->pricedToday($codes);

        // Fresh-today codes are filtered out BEFORE the cap, not after — otherwise a rule
        // overlapping the watchlist would never reach its own tail (docs/BUSINESS-LOGIC.md §11).
        $wanted = array_values(array_filter($codes, static fn (string $code): bool => ! isset($fresh[$code])));

        $cap = (int) config('orbit.rules.sweep_cap');
        $sweeping = array_slice($wanted, 0, $cap);
        $dropped = count($wanted) - count($sweeping);

        /* The shorter horizon — see the class docblock and config/orbit.php. */
        $horizon = (int) config('orbit.rules.sweep_horizon_days');

        $airports = $this->airportIds($sweeping);

        foreach ($sweeping as $code) {
            [$origin, $destination] = explode('-', $code);

            if (! isset($airports[$origin], $airports[$destination])) {
                /* An airport the seeder does not know. Not this job's problem to fix. */
                continue;
            }

            $route = Route::query()->firstOrCreate(
                ['code' => $code],
                [
                    'origin_airport_id'      => $airports[$origin],
                    'destination_airport_id' => $airports[$destination],
                ],
            );

            PollRoutePrices::dispatch($route->id, $horizon);
        }

        $logger->info('Swept a deal rule.', [
            'rule'       => $rule->id,
            'candidates' => count($codes),
            'polled'     => count($sweeping),
            'fresh'      => count($codes) - count($wanted),
            'dropped'    => $dropped,
            'cap'        => $cap,
            /* The other half of the budget, so one line says what a sweep cost. */
            'horizon_days' => $horizon,
        ]);
    }

    /**
     * Route codes whose fares were already fetched today.
     *
     * @param  list<string>  $codes
     * @return array<string, true>
     */
    private function pricedToday(array $codes): array
    {
        $today = Date::now((string) config('orbit.timezone'))->startOfDay();

        /** @var list<string> $priced */
        $priced = CalendarFare::query()
            ->join('routes', 'routes.id', '=', 'calendar_fares.route_id')
            ->whereIn('routes.code', $codes)
            ->where('calendar_fares.fetched_at', '>=', $today)
            ->distinct()
            ->pluck('routes.code')
            ->all();

        return array_fill_keys($priced, true);
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, int> IATA => airport id
     */
    private function airportIds(array $codes): array
    {
        $iatas = [];

        foreach ($codes as $code) {
            foreach (explode('-', $code) as $iata) {
                $iatas[$iata] = true;
            }
        }

        /** @var array<string, int> $ids */
        $ids = Airport::query()
            ->whereIn('iata', array_keys($iatas))
            ->pluck('id', 'iata')
            ->all();

        return $ids;
    }
}
