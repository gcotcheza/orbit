<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Domain\Discovery\SweptFare;

/**
 * "What is cheap from here, to ANYWHERE" — no destination in the signature, and DO NOT treat
 * these as verified finds (docs/BUSINESS-LOGIC.md §16).
 */
interface OriginSweepProvider
{
    /**
     * The cheapest recent find from this origin to each destination.
     *
     * @param  string  $originIata  three-letter IATA code, upper case
     * @return list<SweptFare> one per destination, no promised order; missing means absent
     */
    public function cheapestFromOrigin(string $originIata): array;
}
