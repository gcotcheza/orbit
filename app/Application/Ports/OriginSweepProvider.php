<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Domain\Discovery\SweptFare;

/**
 * Where "what is cheap from here, to ANYWHERE" comes from — unlike the other
 * fare ports, this one has no destination in its signature ("surprise me").
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * One request per origin covers every destination (measured ~1,177 candidate
 * fares for 3 requests vs. 1,177 route-by-route); exactly one entry per
 * destination, no duplicates, in every answer.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * DO NOT treat these as verified: unchecked finds, some up to a week stale
 * (`SweptFare::$foundAt`); DiscoveryPolicy/DiscoverDeals do verification.
 * `asOf` is deliberately absent too — no real API answers about the past.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
interface OriginSweepProvider
{
    /**
     * The cheapest recent find from this origin to each destination.
     *
     * @param  string  $originIata  three-letter IATA code, upper case
     * @return list<SweptFare> one entry per destination, no promised order —
     *                         missing destinations are absent, not zero-priced.
     *                         Why: docs/BUSINESS-LOGIC.md §36.
     */
    public function cheapestFromOrigin(string $originIata): array;
}
