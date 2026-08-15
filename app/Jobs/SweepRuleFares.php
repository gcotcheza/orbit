<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Rules\RuleMatches;
use App\Models\Airport;
use App\Models\CalendarFare;
use App\Models\DealRule;
use App\Models\Route;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Psr\Log\LoggerInterface;

/**
 * Go and find out what a rule is worth.
 *
 * THE PROBLEM THIS SOLVES. A rule names routes nobody has ever watched —
 * "somewhere sunny in spring" is about thirty city pairs Orbit holds no fares
 * for — and the daily poll only visits the watchlist (see
 * App\Console\Commands\PollFares). Without this job a new rule matches nothing
 * on the day it is written, which reads as a broken feature rather than as an
 * empty cupboard. So creating a rule queues this, and the schedule runs it
 * again every morning after the watchlist poll has finished.
 *
 * IT CREATES `routes` ROWS FOR PAIRS NOBODY IS WATCHING, and that is fine: a
 * route is a fact about the world rather than a possession
 * (App\Http\Controllers\WatchlistItemController says the same when it reuses
 * an existing row). Nothing shows them until a rule matches one, and the day
 * the owner promotes one to the watchlist they get the history this job
 * already paid for.
 *
 * THE CAP IS THE POINT. A rule with no vibe is 3 origins × 77 destinations,
 * and running that would spend a morning's rate limit on a sentence somebody
 * may delete. config('orbit.rules.sweep_cap') keeps the best-fitting pairs —
 * "best" is App\Domain\Rules\RuleMatcher::rank(), most matching vibes then
 * warmest then alphabetical, so the same rule sweeps the same places every
 * morning rather than a different thirty each time — and logs what it dropped,
 * because a rule permanently losing its tail is a thing somebody should be
 * able to find out about.
 *
 * IT TAKES AN ID, NOT A MODEL, for the reason PollRoutePrices does: a rule
 * deleted between the tap and the worker is a normal Tuesday.
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

        /*
         * ALREADY PRICED TODAY IS SKIPPED BEFORE THE CAP IS APPLIED, not
         * after. The cap is a budget for provider calls, and spending it on a
         * route whose fares were fetched this morning would mean a rule that
         * overlaps the watchlist never reaches its own tail. PollRoutePrices
         * is idempotent per day, so re-polling would be harmless — just
         * useless, and rate limits are the scarce thing here.
         */
        $wanted = array_values(array_filter($codes, static fn (string $code): bool => ! isset($fresh[$code])));

        $cap = (int) config('orbit.rules.sweep_cap');
        $sweeping = array_slice($wanted, 0, $cap);
        $dropped = count($wanted) - count($sweeping);

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
                    'origin_airport_id' => $airports[$origin],
                    'destination_airport_id' => $airports[$destination],
                ],
            );

            PollRoutePrices::dispatch($route->id);
        }

        $logger->info('Swept a deal rule.', [
            'rule' => $rule->id,
            'candidates' => count($codes),
            'polled' => count($sweeping),
            'fresh' => count($codes) - count($wanted),
            'dropped' => $dropped,
            'cap' => $cap,
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
