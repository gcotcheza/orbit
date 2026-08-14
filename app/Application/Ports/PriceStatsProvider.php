<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Domain\Pricing\PriceStats;

/**
 * What a route USUALLY costs.
 *
 * The second half of docs/PLAN.md's hybrid data model, and the reason a deal
 * score means anything on the day a route is added: our own history can only
 * say a fare is falling, never that it is low, until it has months behind it.
 * Amadeus' price-analysis endpoint answers exactly this — the quartiles of the
 * fares for a route — which is why the port is shaped as a five-number summary
 * and not as a bag of samples.
 *
 * NULL IS A REAL ANSWER. Statistics do not exist for every city pair, and a
 * caller that gets none is expected to score on what is left (see
 * App\Domain\Pricing\DealScorer) rather than to invent them.
 */
interface PriceStatsProvider
{
    /**
     * @param  string  $originIata  three-letter IATA code, upper case
     * @param  string  $destinationIata  three-letter IATA code, upper case
     */
    public function statsFor(string $originIata, string $destinationIata): ?PriceStats;
}
