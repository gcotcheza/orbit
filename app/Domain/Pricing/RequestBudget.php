<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use InvalidArgumentException;

/**
 * What a day of scheduled polling costs the fare provider, clock hour by clock hour.
 * The stagger is what moves a fan-out's tail into the next one (docs/BUSINESS-LOGIC.md §27).
 */
final readonly class RequestBudget
{
    /**
     * @param  list<ProviderRun>  $runs
     */
    public function __construct(
        private array $runs,
        private int $staggerMinutes,
        private int $watchedRoutes,
    ) {
        if ($staggerMinutes < 0) {
            throw new InvalidArgumentException("A stagger of {$staggerMinutes} minutes is not a delay.");
        }

        if ($watchedRoutes < 0) {
            throw new InvalidArgumentException("A watchlist cannot hold {$watchedRoutes} routes.");
        }
    }

    public function withWatchedRoutes(int $count): self
    {
        return new self($this->runs, $this->staggerMinutes, $count);
    }

    /**
     * @return array<int, int>
     */
    public function perClockHour(): array
    {
        $hours = [];

        foreach ($this->runs as $run) {
            if (! $run->perRoute) {
                $hour = self::clockHour($run->startMinute);
                $hours[$hour] = ($hours[$hour] ?? 0) + $run->requests;

                continue;
            }

            for ($index = 0; $index < $this->watchedRoutes; $index++) {
                $hour = self::clockHour($run->startMinute + $this->staggerMinutes * $index);
                $hours[$hour] = ($hours[$hour] ?? 0) + $run->requests;
            }
        }

        ksort($hours);

        return $hours;
    }

    public function peak(): int
    {
        return max([0, ...array_values($this->perClockHour())]);
    }

    public function busiestHour(): int
    {
        $peak = $this->peak();

        foreach ($this->perClockHour() as $hour => $requests) {
            if ($requests === $peak) {
                return $hour;
            }
        }

        return 0;
    }

    public function exceeds(int $hourlyLimit): bool
    {
        return $this->peak() > $hourlyLimit;
    }

    private static function clockHour(int $minuteOfDay): int
    {
        return intdiv($minuteOfDay, 60) % 24;
    }
}
