<?php

declare(strict_types=1);

namespace App\Application\Routes;

use App\Models\Route;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use App\Models\LivePriceCheck;
use Illuminate\Support\Facades\Date;
use App\Domain\Discovery\GoogleVerdict;
use App\Application\Ports\LiveFareCheck;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * "Check live price" — the one place in Orbit where a tap spends a SerpAPI
 * search. The guardrails and what each answer means: docs/BUSINESS-LOGIC.md §17.
 */
final readonly class LivePriceChecks
{
    public function __construct(
        private LiveFareCheck $google,
        private LoggerInterface $logger,
    ) {}

    /** The stored answer for this route and departure, inside the cooldown. */
    public function latest(Route $route, DateTimeImmutable $departure): ?LivePriceCheck
    {
        $check = $this->row($route, $departure);

        if ($check === null) {
            return null;
        }

        return $check->isFresh(Date::now()->toImmutable(), self::cooldownHours()) ? $check : null;
    }

    public function check(Route $route, DateTimeImmutable $departure): LiveCheckResult
    {
        $existing = $this->latest($route, $departure);

        if ($existing !== null) {
            return LiveCheckResult::answered($existing);
        }

        if ($this->google->available() < 1) {
            $this->logger->info('A live price check was refused — no SerpAPI budget.', [
                'route'     => $route->code,
                'departure' => $departure->format('Y-m-d'),
            ]);

            return LiveCheckResult::noBudget();
        }

        $answer = $this->google->ask(
            $route->origin->iata,
            $route->destination->iata,
            $departure,
        );

        /*
         * ⚠ NOT BILLED, SO NOT RECORDED. A row here would serve "Google had no
         * opinion" for the whole cooldown off a search that never happened.
         */
        if (! $answer->wasSpent) {
            $this->logger->info('A live price check could not be made — Google was never asked.', [
                'route'     => $route->code,
                'departure' => $departure->format('Y-m-d'),
            ]);

            return LiveCheckResult::couldNotAsk();
        }

        $check = $this->store($route, $departure, $answer->verdict);

        $this->logger->info('A live price check was made.', [
            'route'     => $route->code,
            'departure' => $departure->format('Y-m-d'),
            'lowest'    => $check->lowestCents(),
        ]);

        return LiveCheckResult::answered($check);
    }

    /**
     * ⚠ A CONCURRENT TAP MUST NOT COST A 500: the unique key lets one row through and the
     * loser serves the winner. ⚠ DO NOT CALL THIS INSIDE A TRANSACTION (Postgres poisons it).
     */
    private function store(Route $route, DateTimeImmutable $departure, ?GoogleVerdict $verdict): LivePriceCheck
    {
        $check = $this->row($route, $departure) ?? new LivePriceCheck([
            'route_id'       => $route->id,
            'departure_date' => $departure->format('Y-m-d'),
        ]);

        try {
            $check->fill([
                'checked_at'     => Date::now(),
                'google_verdict' => $verdict?->toArray(),
            ])->save();
        } catch (UniqueConstraintViolationException $e) {
            $winner = $this->row($route, $departure);

            if ($winner === null) {
                throw $e;
            }

            $this->logger->info('Two live price checks raced; the stored answer is served to both.', [
                'route'     => $route->code,
                'departure' => $departure->format('Y-m-d'),
            ]);

            return $winner;
        }

        return $check;
    }

    /**
     * ⚠ An exact match, and it only finds the row because the model writes
     * `departure_date` as a bare `Y-m-d` — see App\Models\LivePriceCheck.
     */
    private function row(Route $route, DateTimeImmutable $departure): ?LivePriceCheck
    {
        return LivePriceCheck::query()
            ->where('route_id', $route->id)
            ->where('departure_date', $departure->format('Y-m-d'))
            ->first();
    }

    private static function cooldownHours(): int
    {
        return (int) config('orbit.live_check.cooldown_hours');
    }
}
