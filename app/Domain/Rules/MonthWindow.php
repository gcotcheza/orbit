<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use DateTimeImmutable;

/**
 * "Mar – May", as a thing the matcher can use.
 *
 * Months, not dates — the whole design decision. A rule is a STANDING alert ("spring, under €80" means every spring), so dates would quietly expire;
 * `resolve()` turns two month numbers into the next real span each time (docs/BUSINESS-LOGIC.md §11).
 *
 * Wrapping is normal (winter = Dec-Feb): `to` < `from` is the ordinary case, not an error, and every method here must
 * mean the right thing then (docs/BUSINESS-LOGIC.md §11).
 */
final readonly class MonthWindow
{
    /** Jan..Dec, three letters, index 1-12. Not locale-aware on purpose: this is a label the API publishes, not prose. */
    private const NAMES = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
    ];

    private function __construct(
        /** 1-12 */
        public int $from,
        /** 1-12; smaller than `from` when the window crosses new year. */
        public int $to,
    ) {}

    /**
     * A window, or NULL if either end is not a month. Nullable rather than throwing: both callers parse untrusted input,
     * and a month 13 is bad input, not a bug to crash on (docs/BUSINESS-LOGIC.md §11).
     */
    public static function of(int $from, int $to): ?self
    {
        if ($from < 1 || $from > 12 || $to < 1 || $to > 12) {
            return null;
        }

        return new self($from, $to);
    }

    /**
     * Every month the window covers, in the order it covers them.
     *
     * @return list<int>
     */
    public function months(): array
    {
        $months = [];

        for ($month = $this->from; ; $month = $month % 12 + 1) {
            $months[] = $month;

            if ($month === $this->to) {
                break;
            }
        }

        return $months;
    }

    public function covers(int $month): bool
    {
        return in_array($month, $this->months(), true);
    }

    /**
     * The next real span this window stands for, as of `$today`.
     *
     * Start may be in the past (deliberately): in April, "spring" is the spring we're in, not next March. Callers
     * intersect with future fares anyway, so a past start costs nothing (docs/BUSINESS-LOGIC.md §11).
     *
     * Search starts a year back (matters only for wrapping windows): on 10 Jan, "winter" began last December — starting at
     * the current year would answer next December, matching nothing for eleven months (docs/BUSINESS-LOGIC.md §11).
     *
     * @return array{DateTimeImmutable, DateTimeImmutable} [first day of `from`, last day of `to`]
     */
    public function resolve(DateTimeImmutable $today): array
    {
        $year = (int) $today->format('Y');

        for ($offset = -1; ; $offset++) {
            $start = $this->firstDay($year + $offset, $this->from);
            $end = $this->lastDay($year + $offset + ($this->to < $this->from ? 1 : 0), $this->to);

            if ($end >= $today->setTime(0, 0)) {
                return [$start, $end];
            }
        }
    }

    /**
     * "Mar – May", or "Jun" when the window is a single month.
     *
     * The spaced en dash is design/README.md §4's, to the character.
     */
    public function label(): string
    {
        return $this->from === $this->to
            ? self::NAMES[$this->from]
            : self::NAMES[$this->from].' – '.self::NAMES[$this->to];
    }

    private function firstDay(int $year, int $month): DateTimeImmutable
    {
        return (new DateTimeImmutable)->setDate($year, $month, 1)->setTime(0, 0);
    }

    private function lastDay(int $year, int $month): DateTimeImmutable
    {
        return $this->firstDay($year, $month)->modify('last day of this month')->setTime(0, 0);
    }
}
