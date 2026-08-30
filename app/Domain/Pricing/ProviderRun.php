<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use InvalidArgumentException;

/**
 * One scheduled call on the fare provider: a per-route fan-out, or a single job
 * whose cost does not move with the watchlist (docs/BUSINESS-LOGIC.md §27).
 */
final readonly class ProviderRun
{
    private function __construct(
        public string $name,
        public int $startMinute,
        public bool $perRoute,
        public int $requests,
    ) {}

    public static function fanOut(string $name, string $startsAt, int $requestsPerRoute): self
    {
        return new self($name, self::minuteOfDay($name, $startsAt), true, self::countable($name, $requestsPerRoute));
    }

    public static function single(string $name, string $startsAt, int $requests): self
    {
        return new self($name, self::minuteOfDay($name, $startsAt), false, self::countable($name, $requests));
    }

    private static function minuteOfDay(string $name, string $startsAt): int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $startsAt, $parts) !== 1) {
            throw new InvalidArgumentException("{$name} is scheduled at {$startsAt}, which is not a 24-hour clock time.");
        }

        return ((int) $parts[1]) * 60 + (int) $parts[2];
    }

    private static function countable(string $name, int $requests): int
    {
        if ($requests < 0) {
            throw new InvalidArgumentException("{$name} cannot cost {$requests} provider requests.");
        }

        return $requests;
    }
}
