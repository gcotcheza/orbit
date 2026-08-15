<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use InvalidArgumentException;

/**
 * "No pings after ten at night" — as arithmetic on a wall clock.
 *
 * MINUTES SINCE LOCAL MIDNIGHT, AND NOTHING ELSE. This class holds no date, no
 * timezone and no clock: the window the owner set is a fact about a wall clock
 * (`user_settings.quiet_start`/`quiet_end` store exactly that, in the owner's
 * zone — see the migration), and turning "is 23:40 inside 22:00–08:00" into a
 * question about an instant is what makes quiet hours drift an hour twice a
 * year. App\Application\Alerts\DeliveryWindow does the conversion, once.
 *
 * THE WINDOW USUALLY CROSSES MIDNIGHT, which is the whole difficulty and the
 * reason this is a class rather than two comparisons at the call site. The
 * default is 22:00 to 08:00, so the "inside" test is `>= start OR < end` rather
 * than `>= start AND < end`, and a naive implementation is not merely wrong at
 * 03:00 — it is wrong in the direction of sending mail, at three in the
 * morning, to the one person this app has.
 *
 * THE END IS EXCLUSIVE. 08:00 is not quiet: it is the moment the held mail goes
 * out, and a window that still covered its own end would defer that mail by
 * another full day.
 *
 * A ZERO-LENGTH WINDOW (start === end) COVERS NOTHING. "From 22:00 to 22:00" is
 * somebody who has not finished setting it up rather than a request never to be
 * contacted again, and reading it as twenty-four hours of silence would make
 * the app look broken in a way nothing on the screen explains.
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
     * `HH:MM` or `HH:MM:SS` to minutes since midnight.
     *
     * BOTH PRECISIONS, because the column is a `time` and the two engines this
     * app runs on disagree about it: Postgres hands back `22:00:00` and SQLite
     * hands back whatever string was written. App\Models\UserSettings trims to
     * five characters for the same reason; accepting either here means a caller
     * that forgot to trim gets the right answer rather than a plausible one.
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
     * 24:00 is midnight and so is 00:00 — a caller that computed a minute by
     * adding to one it already had should not be able to produce a time this
     * class has no opinion about.
     */
    private static function normalise(int $minuteOfDay): int
    {
        return (($minuteOfDay % self::MINUTES_PER_DAY) + self::MINUTES_PER_DAY) % self::MINUTES_PER_DAY;
    }
}
