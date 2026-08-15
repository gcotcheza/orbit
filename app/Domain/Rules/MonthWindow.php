<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use DateTimeImmutable;

/**
 * "Mar – May", as a thing the matcher can use.
 *
 * MONTHS AND NOT DATES, and that is the whole design decision in this file.
 * A rule is a STANDING alert — "somewhere sunny in spring, under €80" is a
 * sentence about every spring, not about spring 2027 — so a window stored as
 * two dates would quietly expire, and the rule would go silent on the exact
 * anniversary the owner wrote it for. Two month numbers never expire;
 * `resolve()` turns them into the next real span each time somebody asks.
 *
 * WRAPPING IS NORMAL. Winter is December to February, so `to` being smaller
 * than `from` is the ordinary case rather than an error, and every method here
 * has to mean the right thing when it happens.
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
     * A window, or NULL if either end is not a month.
     *
     * Nullable rather than throwing because both callers are parsing
     * somebody's typing — the model's JSON or a regex capture — and a month 13
     * is a sentence this app could not read, not a bug it should crash on.
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
     * THE START MAY BE IN THE PAST, and deliberately: asked in April, "spring"
     * is the spring we are standing in, and answering with next March would
     * hide every fare on offer. Only a window that has ENDED rolls forward to
     * next year. Callers intersect this with fares that are all in the future
     * anyway, so a start behind today costs nothing.
     *
     * THE SEARCH STARTS A YEAR BACK, which only matters for a wrapping window.
     * Asked on 10 January, "winter" is the winter around us — it began last
     * December — and a search that started at the current year would answer
     * with next December, leaving a January rule matching nothing for eleven
     * months. For a non-wrapping window the extra year has already ended and
     * the loop simply moves past it.
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
