<?php

declare(strict_types=1);

namespace App\Application\Routes;

use DateTimeImmutable;
use App\Domain\Pricing\DatedFare;

/**
 * One month of the price heatmap, with each day already judged.
 *
 * Verdict computed here, not in the browser — design/README.md §3's rule is
 * also what a future "cheap day" alert would need; two implementations would
 * eventually disagree.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * Range is the month's own low/high, not the route's yearly stats — a dear
 * June should still colour its cheapest Tuesday green.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final readonly class MonthCalendar
{
    public const CHEAP = 'cheap';

    public const MID = 'mid';

    public const PRICEY = 'pricey';

    /**
     * @param  list<array{date: string, cents: int, verdict: string, foundAt: ?DateTimeImmutable}>  $days
     */
    private function __construct(
        public array $days,
        public ?int $minCents,
        public ?int $maxCents,
        public ?DatedFare $cheapest,
    ) {}

    /**
     * @param  list<DatedFare>  $fares  ordered by departure date
     */
    public static function from(array $fares, float $cheapAt, float $priceyAt): self
    {
        if ($fares === []) {
            return new self([], null, null, null);
        }

        $prices = array_map(static fn (DatedFare $fare): int => $fare->cents, $fares);
        $low = min($prices);
        $high = max($prices);
        $range = $high - $low;

        $days = [];
        $cheapest = null;

        foreach ($fares as $fare) {
            /*
             * A month with one price has zero range — "mid" is the only
             * honest colour, and it keeps the division below off zero.
             * Why: docs/BUSINESS-LOGIC.md §36.
             */
            $position = $range > 0 ? ($fare->cents - $low) / $range : 0.5;

            $days[] = [
                'date'    => $fare->departureDate->format('Y-m-d'),
                'cents'   => $fare->cents,
                'verdict' => match (true) {
                    $position <= $cheapAt  => self::CHEAP,
                    $position >= $priceyAt => self::PRICEY,
                    default                => self::MID,
                },
                /*
                 * Carried through untouched, deliberately not folded into the
                 * verdict — age and cheapness are independent facts.
                 * Why: docs/BUSINESS-LOGIC.md §36.
                 */
                'foundAt' => $fare->foundAt,
            ];

            if ($fare->cents === $low && $cheapest === null) {
                $cheapest = $fare;
            }
        }

        return new self($days, $low, $high, $cheapest);
    }
}
