<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The cheapest ROUND-TRIP fare a provider found for one pair of dates.
 *
 * THE SIBLING OF DatedFare, NOT A REPLACEMENT FOR IT. That one is a one-way
 * fare for a single departure date and is what every price in this app has been
 * since it shipped — the calendar heatmap, the deal score, the alert threshold
 * and the €80 in a rule are all one-way numbers. This one exists because
 * one-way pricing is only the truth for the carriers Orbit started with:
 *
 *   MEASURED ON 2026-08-16, cheapest one-way (month-matrix, recorded the day
 *   before) against cheapest round-trip (prices/latest) on the same route:
 *
 *     AMS-LIS   €80  one-way    €134 return    the one-way is 60% of a return
 *     AMS-JFK   €334 one-way    €484 return    the one-way is 69% of a return
 *     AMS-BKK   €272 one-way    €472 return    the one-way is 58% of a return
 *
 *   A long-haul one-way is not half a return, it is roughly two thirds of one.
 *   So "AMS-JFK from €334" was never a lie about the number and was always a
 *   lie about the trip: nobody flies to New York one way, and the fare somebody
 *   would actually pay is €484. Pricing the PAIR is the only way to say
 *   something true about a long-haul route.
 *
 * =============================================================================
 * THE GRAIN IS (DEPARTURE DATE, NIGHTS), AND IT IS THE PROVIDER'S OWN GRAIN
 * =============================================================================
 * Travelpayouts' `/v2/prices/latest` with `one_way=false` answers with one
 * entry per (depart_date, return_date) pair, already reduced to the cheapest —
 * 119 entries with 119 distinct pairs for AMS-LIS, 56 of 56 for AMS-JFK, 338 of
 * 338 for AMS-BKK. There was no reduction left to do, so this type does not
 * pretend to a finer grain than the answer has.
 *
 * NIGHTS RATHER THAN A RETURN DATE, AND THE RETURN DATE IS DERIVED. The two
 * carry exactly the same information — nights is `return - departure` and the
 * return date is `departure + nights` — so one of them has to be the stored
 * fact and the other a function, or they will disagree one day. Nights wins for
 * three reasons that all point the same way:
 *
 *   1. IT IS THE AXIS EVERY QUESTION IS ASKED ALONG. "A week somewhere warm in
 *      February" names a duration and a window, never a return date, and
 *      App\Domain\Rules\RuleCriteria::$tripLengthNights has held `[min, max]`
 *      since the rules engine shipped.
 *   2. IT INDEXES. `where nights between 6 and 8` reads an ordinary integer
 *      index; the same question against a return date is `return_date -
 *      departure_date`, which is an expression index and a different expression
 *      on Postgres and on SQLite — the two databases this app runs on.
 *   3. DERIVING THE OTHER WAY IS EXACT AND DIALECT-FREE. `departure->modify(
 *      '+N days')` is arithmetic on a date nothing rounds.
 *
 * ZERO NIGHTS IS LEGAL. A same-day return is a real fare (three of the 198
 * recorded entries had one), so the floor is 0 and not 1. NEGATIVE is not: a
 * return before its departure is a corrupt row, and the constructor refuses it
 * rather than letting a negative stay reach a duration band that would never
 * match it and a column that cannot hold it.
 *
 * `foundAt` IS THE SAME THIRD DATE IT IS ON DatedFare, and it matters MORE
 * here. The round-trip cache is measurably older than the one-way one:
 * `/v2/prices/latest` serves the last SEVEN days of finds (recorded range
 * 2026-08-09 to 2026-08-16, on all three routes), so a round-trip fare is
 * routinely days old where a month-matrix fare is often hours old. Every one of
 * the 198 recorded entries carried a `found_at`, and it is carried through here
 * for the reason DatedFare's docblock gives at length: "I asked just now" and
 * "this price is current" are two different claims. Null means the age is not
 * known and is never filled in from `fetched_at`.
 */
final readonly class ReturnTrip
{
    public function __construct(
        public DateTimeImmutable $departureDate,
        public int $nights,
        public int $cents,
        /** When the PROVIDER found this price; null when it does not say. */
        public ?DateTimeImmutable $foundAt = null,
    ) {
        if ($nights < 0) {
            throw new InvalidArgumentException(
                "A return trip cannot last {$nights} nights — the return leg would precede the outbound one.",
            );
        }
    }

    /**
     * The day you would fly home.
     *
     * DERIVED AND NEVER STORED — see the class docblock for why nights is the
     * fact and this is the function. Midnight-anchored like its departure,
     * because `departure_date` is a DATE column and a return that inherited a
     * time of day would compare unequal to the same calendar day written by
     * anything else.
     */
    public function returnDate(): DateTimeImmutable
    {
        return $this->departureDate->modify("+{$this->nights} days");
    }
}
