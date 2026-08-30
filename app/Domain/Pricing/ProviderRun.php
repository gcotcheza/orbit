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
        return new self($name, self::minuteOfDay($startsAt), true, self::countable($name, $requestsPerRoute));
    }

    public static function single(string $name, string $startsAt, int $requests): self
    {
        return new self($name, self::minuteOfDay($startsAt), false, self::countable($name, $requests));
    }

    public static function minuteOfDay(string $clock): int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $clock, $parts) !== 1) {
            throw new InvalidArgumentException("Not a 24-hour clock time: {$clock}.");
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
