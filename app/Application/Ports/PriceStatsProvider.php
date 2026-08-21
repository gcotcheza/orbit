<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Domain\Pricing\PriceStats;

/**
 * What a route USUALLY costs — shaped as a five-number summary because that is what a
 * price-analysis endpoint answers. NULL is a real answer (docs/BUSINESS-LOGIC.md §6).
 */
interface PriceStatsProvider
{
    /**
     * @param  string  $originIata  three-letter IATA code, upper case
     * @param  string  $destinationIata  three-letter IATA code, upper case
     */
    public function statsFor(string $originIata, string $destinationIata): ?PriceStats;
}
