<?php

declare(strict_types=1);

namespace App\Application\Routes;

use App\Domain\Pricing\DatedFare;
use DateTimeImmutable;

/**
 * One month of the price heatmap, with each day already judged.
 *
 * THE VERDICT IS COMPUTED HERE, NOT IN THE BROWSER. design/README.md §3 fixes
 * the rule — cheap at or below the month's low plus 28% of its range, pricey
 * at or above 66% — and it is also the rule a future "cheap day" alert would
 * have to use. Two implementations of it would eventually disagree, and the
 * one that disagreed silently would be the one on the phone.
 *
 * THE RANGE IS THE MONTH'S OWN low and high, not the route's yearly
 * statistics: the screen's question is "which day of THIS month is cheap",
 * and a June in which every fare is dear should still colour its cheapest
 * Tuesday green. The route-level judgement is the deal score, on the other
 * two screens.
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
             * A MONTH WITH ONE PRICE has a zero range, and every day of it is
             * both the cheapest and the dearest. "mid" is the only honest
             * colour for a month that says nothing, and it also keeps the
             * division below off zero.
             */
            $position = $range > 0 ? ($fare->cents - $low) / $range : 0.5;

            $days[] = [
                'date' => $fare->departureDate->format('Y-m-d'),
                'cents' => $fare->cents,
                'verdict' => match (true) {
                    $position <= $cheapAt => self::CHEAP,
                    $position >= $priceyAt => self::PRICEY,
                    default => self::MID,
                },
                /*
                 * CARRIED THROUGH UNTOUCHED, and deliberately NOT folded into
                 * the verdict. How old a price is and whether it is cheap for
                 * this month are two independent facts, and a four-day-old €40
                 * is still the cheapest cell in the grid — it is just a cell the
                 * sheet has to say "seen four days ago" under. Colouring on age
                 * would hide the answer to the question the screen exists to
                 * ask.
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
