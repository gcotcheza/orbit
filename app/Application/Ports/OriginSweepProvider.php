<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Domain\Discovery\SweptFare;

/**
 * Where "what is cheap from here, to ANYWHERE" comes from.
 *
 * THE FOURTH FARE PORT, AND THE FIRST ONE WITH NO DESTINATION IN ITS SIGNATURE.
 * PriceProvider and ReturnTripProvider both answer a question about a PAIR the
 * caller already has in mind — the calendar, the deal score and every alert are
 * built on knowing which route you mean. This one answers the question the
 * owner cannot ask, because asking it requires already knowing the answer:
 * "surprise me".
 *
 * =============================================================================
 * ONE REQUEST PER ORIGIN, FOR EVERYWHERE
 * =============================================================================
 * That is the whole budget story and it is what makes this feature affordable
 * at all. Measured against the live API on 2026-08-16, one request per home
 * airport returned:
 *
 *     AMS   562 entries   562 distinct destinations
 *     DUS   419 entries   419 distinct destinations
 *     EIN   196 entries   196 distinct destinations
 *
 * — 1,177 candidate fares for THREE requests. The same coverage asked route by
 * route would be 1,177 requests, i.e. six hours of the token's entire hourly
 * allowance, for a screen nobody had asked a question of.
 *
 * EXACTLY ONE ENTRY PER DESTINATION, in all three answers, with no duplicates
 * anywhere. So an implementation does not reduce, and a caller may read the
 * list as "the cheapest recent find per place" without proving it first.
 *
 * =============================================================================
 * IT IS A CACHE OF FINDS AND NOT A PRICE LIST — read this before believing one
 * =============================================================================
 * The same warning PriceProvider carries, only louder, because there is no
 * second source to catch it. Every entry is a price SOMEBODY ELSE'S SEARCH
 * turned up, at some point in the last seven days: the recorded `found_at`
 * range ran 2026-08-09 to 2026-08-16, with 116 of AMS's 562 found on the day of
 * the call and 3 of them a full week old. `SweptFare::$foundAt` carries that,
 * every reader is expected to act on it, and App\Domain\Discovery\
 * DiscoveryPolicy treats a fare that will not say how old it is as too old.
 *
 * A SWEPT FARE HAS NOT BEEN CHECKED AND MUST NOT BE PRESENTED AS IF IT HAD.
 * This port is the FIRST stage of a two-stage funnel (docs/BUSINESS-LOGIC.md
 * §16); its answers are candidates, and what makes one a discovery is
 * App\Jobs\DiscoverDeals fetching its window and asking Google. An
 * implementation that made this list nicer — dropped the old rows, sorted by
 * price, filtered the implausible — would be making the verification stage's
 * decisions in the one place that has the least information to make them with.
 *
 * IMPLEMENTATIONS ANSWER AS OF NOW, and `asOf` is absent for the reason it is
 * absent from the other two ports: no real API lets you ask about the past.
 */
interface OriginSweepProvider
{
    /**
     * The cheapest recent find from this origin to each destination.
     *
     * @param  string  $originIata  three-letter IATA code, upper case
     * @return list<SweptFare> one entry per destination, in no promised order —
     *                         the caller ranks. Destinations with nothing cached
     *                         are absent rather than zero-priced, and an empty
     *                         list is a real answer.
     */
    public function cheapestFromOrigin(string $originIata): array;
}
