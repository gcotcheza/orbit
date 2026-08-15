<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use DateTimeImmutable;

/**
 * The cheapest fare a provider found for ONE departure date.
 *
 * This is what App\Application\Ports\PriceProvider returns a list of, and what
 * fills both the calendar heatmap (one cell per departure date) and the daily
 * observation (the minimum across the whole window).
 *
 * DISTINCT FROM PricePoint on purpose, even though both are a date and a
 * price: this one's date is when you would FLY, that one's is when we LOOKED.
 * They are the two axes of a fare and collapsing them into one type is how a
 * chart ends up plotting the wrong one.
 *
 * =============================================================================
 * AND `foundAt` IS A THIRD DATE, WHICH IS NEITHER OF THOSE TWO
 * =============================================================================
 * When the price was FOUND — by whoever's search first turned it up — as
 * against when Orbit fetched it. The distinction only exists because the real
 * provider is a CACHE: Travelpayouts serves fares other people's searches
 * already produced, so a poll that ran twenty minutes ago can hand back a price
 * last seen four days ago. Orbit showed €36 for a date whose live cheapest was
 * €56, and both numbers were true — one of them was simply four days old, and
 * nothing on the screen said which.
 *
 * NULLABLE, AND DEFAULTED, WHICH IS THE WHOLE OF THE EXTENSION. Every existing
 * construction site stays valid and means what it always meant, and the null is
 * a real answer: "this fare's age is not known". It is NEVER filled in from
 * `fetched_at` as a stand-in — that substitution is exactly the false claim this
 * field was added to stop making. Callers that cannot say how old a price is
 * must say nothing, not something plausible.
 *
 * WHY IT IS ON THE PORT'S TYPE RATHER THAN ON THE ROW ALONE. The age has to
 * survive the trip from the adapter to the screen and to the alert policy, and
 * `App\Application\Ports\PriceProvider` is the only thing that stands between
 * them. A field added downstream of the port could only ever be re-derived, and
 * the one honest source for it is the provider's own answer.
 */
final readonly class DatedFare
{
    public function __construct(
        public DateTimeImmutable $departureDate,
        public int $cents,
        /** When the PROVIDER found this price; null when it does not say. */
        public ?DateTimeImmutable $foundAt = null,
    ) {}
}
