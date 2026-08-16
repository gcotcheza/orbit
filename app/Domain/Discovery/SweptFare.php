<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use DateTimeImmutable;

/**
 * One line of an origin sweep: the cheapest thing the provider has lately seen
 * from a home airport to ONE destination.
 *
 * THE RAWEST THING IN THE FUNNEL, and deliberately so — it is what
 * App\Application\Ports\OriginSweepProvider answers with, and it knows nothing
 * about distance, thresholds or whether anybody would want to go there. The
 * scorer turns it into a DealCandidate by pairing it with the two airports'
 * coordinates, because that is the first step that needs the DATABASE and this
 * type has to be constructible from an HTTP response alone.
 *
 * NO ORIGIN FIELD. A sweep is asked one origin at a time and answers for that
 * origin; carrying it on every one of the 562 rows would be the question copied
 * into each of its answers. App\Jobs\DiscoverDeals holds it for the loop.
 *
 * =============================================================================
 * `destinationIata` IS THE PROVIDER'S CODE AND MAY NOT BE AN AIRPORT
 * =============================================================================
 * Travelpayouts normalises some airports to CITY codes — the return adapter
 * documents `destination=JFK` answering as `NYC` — and an origin sweep is where
 * that surfaces at scale rather than as a curiosity: 45 of the 1,177 rows
 * across the three home airports on 2026-08-16 carried a code with no row in
 * `airports` (LON, MOW, MIL, BUE, CHI, JKT, IZM, BAK…). Every one of them is a
 * metropolitan area rather than a field you can land at.
 *
 * THEY ARE DROPPED RATHER THAN GUESSED AT, in the scorer, for two reasons that
 * both matter: Orbit has no coordinates for them, so there is no honest €/km;
 * and the whole point of a discovery is that tapping it opens the ordinary
 * lookup flow, which needs a real `AMS-XXX` route code. A city code would be a
 * card that goes nowhere.
 */
final readonly class SweptFare
{
    public function __construct(
        /** The provider's code for where this goes — see the class docblock. */
        public string $destinationIata,
        public DateTimeImmutable $departureDate,
        public int $cents,
        /**
         * When the PROVIDER found this price; null when it will not say.
         *
         * IT IS ALMOST NEVER NULL HERE AND IT IS ALMOST NEVER FRESH. Every one
         * of the 1,177 rows recorded on 2026-08-16 carried one, and they were
         * spread across a full SEVEN DAYS — 116 of the AMS sweep's 562 found
         * that day, 108 the day before, and 3 a week old. That spread is the
         * reason App\Domain\Discovery\DiscoveryPolicy has a freshness rule at
         * all: a sweep is not a price list, it is a week of other people's
         * searches piled up, and the oldest of it is the least believable.
         */
        public ?DateTimeImmutable $foundAt = null,
    ) {}
}
