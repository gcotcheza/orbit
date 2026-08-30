<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use InvalidArgumentException;

/**
 * What a day of scheduled polling costs the fare provider, by clock hour and by any
 * sixty minutes — a limit may be read either way (docs/BUSINESS-LOGIC.md §27).
 */
final class RequestBudget
{
    /** @var list<array{int, int}>|null */
    private ?array $jobs = null;

    /** @var array<int, int>|null */
    private ?array $hours = null;

    /**
     * @param  list<ProviderRun>  $runs
     */
    public function __construct(
        private readonly array $runs,
        private readonly int $staggerMinutes,
        private readonly int $watchedRoutes,
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
        if ($this->hours !== null) {
            return $this->hours;
        }

        $hours = [];

        foreach ($this->jobs() as [$minute, $requests]) {
            $hour = intdiv($minute, 60) % 24;
            $hours[$hour] = ($hours[$hour] ?? 0) + $requests;
        }

        ksort($hours);

        return $this->hours = $hours;
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

    public function rollingPeak(int $windowMinutes): int
    {
        return $this->worstWindow($windowMinutes)[1];
    }

    public function rollingPeakStartsAt(int $windowMinutes): int
    {
        return $this->worstWindow($windowMinutes)[0];
    }

    public function exceeds(int $hourlyLimit): bool
    {
        return max($this->peak(), $this->rollingPeak(60)) > $hourlyLimit;
    }

    /**
     * @return array{int, int} the costliest window's first minute, and its cost
     */
    private function worstWindow(int $windowMinutes): array
    {
        if ($windowMinutes < 1) {
            throw new InvalidArgumentException("A window of {$windowMinutes} minutes holds nothing.");
        }

        $jobs = $this->jobs();
        $total = count($jobs);
        $worst = [0, 0];
        $head = 0;
        $carried = 0;

        for ($tail = 0; $tail < $total; $tail++) {
            while ($head < $total && $jobs[$head][0] < $jobs[$tail][0] + $windowMinutes) {
                $carried += $jobs[$head][1];
                $head++;
            }

            if ($carried > $worst[1]) {
                $worst = [$jobs[$tail][0], $carried];
            }

            $carried -= $jobs[$tail][1];
        }

        return $worst;
    }

    /**
     * Every queued job, in the order the provider sees them.
     *
     * @return list<array{int, int}>
     */
    private function jobs(): array
    {
        if ($this->jobs !== null) {
            return $this->jobs;
        }

        $jobs = [];

        foreach ($this->runs as $run) {
            $fanOut = $run->perRoute ? $this->watchedRoutes : 1;

            for ($index = 0; $index < $fanOut; $index++) {
                $jobs[] = [$run->startMinute + ($run->perRoute ? $this->staggerMinutes * $index : 0), $run->requests];
            }
        }

        usort($jobs, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $this->jobs = $jobs;
    }
}
