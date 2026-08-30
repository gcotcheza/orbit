<?php

declare(strict_types=1);

namespace App\Application\Ports;

use DateTimeImmutable;
use App\Domain\Discovery\GoogleAnswer;

/**
 * A metered second opinion on one fare. ⚠ NOT a PriceProvider and must never become one:
 * the budget is 250 searches a MONTH (docs/BUSINESS-LOGIC.md §17).
 */
interface LiveFareCheck
{
    /** How many searches this run may spend — 0 when it may spend none. */
    public function available(): int;

    /**
     * @param  string  $originIata  three-letter IATA code, upper case
     * @param  string  $destinationIata  three-letter IATA code, upper case
     */
    public function ask(string $originIata, string $destinationIata, DateTimeImmutable $departure): GoogleAnswer;
}
