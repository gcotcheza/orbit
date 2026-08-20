<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use InvalidArgumentException;

/**
 * "No pings after ten at night", as arithmetic on a wall clock: minutes since local midnight,
 * a window that usually crosses midnight, end exclusive (docs/BUSINESS-LOGIC.md §10).
 */
final readonly class QuietHours
{
    private const MINUTES_PER_DAY = 1440;

    private function __construct(
        public bool $enabled,
        public int $startMinute,
        public int $endMinute,
    ) {}

    /**
     * @param  string  $start  `HH:MM` in the owner's timezone
     * @param  string  $end  `HH:MM` in the owner's timezone
     */
    public static function between(string $start, string $end): self
    {
        return new self(true, self::minuteOfDay($start), self::minuteOfDay($end));
    }

    /**
     * The account has quiet hours switched off — everything is sent at once.
     */
    public static function off(): self
    {
        return new self(false, 0, 0);
    }

    public function covers(int $minuteOfDay): bool
    {
        if (! $this->enabled || $this->startMinute === $this->endMinute) {
            return false;
        }

        $minute = self::normalise($minuteOfDay);

        return $this->startMinute < $this->endMinute
            /* An ordinary window inside one day: 09:00 to 17:00. */
            ? $minute >= $this->startMinute && $minute < $this->endMinute
            /* One that crosses midnight: 22:00 to 08:00. */
            : $minute >= $this->startMinute || $minute < $this->endMinute;
    }

    /**
     * How long until the window opens again, in minutes. NULL when the given
     * time is not inside it, which is the caller's cue to send now.
     */
    public function minutesUntilEnd(int $minuteOfDay): ?int
    {
        if (! $this->covers($minuteOfDay)) {
            return null;
        }

        return ($this->endMinute - self::normalise($minuteOfDay) + self::MINUTES_PER_DAY) % self::MINUTES_PER_DAY;
    }

    public function endHour(): int
    {
        return intdiv($this->endMinute, 60);
    }

    public function endMinuteOfHour(): int
    {
        return $this->endMinute % 60;
    }

    /**
     * `HH:MM` or `HH:MM:SS` to minutes since midnight. Both precisions: Postgres returns
     * `22:00:00`, SQLite returns whatever was written.
     */
    private static function minuteOfDay(string $clock): int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $clock, $parts) !== 1) {
            throw new InvalidArgumentException(sprintf('[%s] is not a wall-clock time.', $clock));
        }

        $hours = (int) $parts[1];
        $minutes = (int) $parts[2];

        if ($hours > 23 || $minutes > 59) {
            throw new InvalidArgumentException(sprintf('[%s] is not a wall-clock time.', $clock));
        }

        return $hours * 60 + $minutes;
    }

    /**
     * 24:00 and 00:00 are both midnight — a caller adding to a minute it already had must
     * never produce a time this class has no opinion about.
     */
    private static function normalise(int $minuteOfDay): int
    {
        return (($minuteOfDay % self::MINUTES_PER_DAY) + self::MINUTES_PER_DAY) % self::MINUTES_PER_DAY;
    }
}
