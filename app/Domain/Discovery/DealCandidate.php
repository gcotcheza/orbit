<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use DateTimeImmutable;

/**
 * A swept fare with a distance attached, and therefore something that can be
 * RANKED.
 *
 * THE MIDDLE OF THE FUNNEL. A SweptFare is what the provider said; a
 * App\Models\Discovery is what survived verification and got written down. This
 * is the shape in between: enough to sort a thousand rows and throw away all
 * but a handful, and cheap enough that doing so costs no requests at all.
 *
 * =============================================================================
 * WHY €/km IS THE SORT AND WHY IT IS NOT THE WHOLE RULE
 * =============================================================================
 * The owner's ask is "surprise me with a €29 Santorini I never thought to
 * watch", and the hard half of that is SURPRISE: a screen that ranked by price
 * alone would be a list of the nearest airports forever — Brussels, Cologne,
 * Maastricht — because a 158 km hop is always going to undercut a 3,000 km one.
 * Ranking by what a euro BUYS is what puts Marrakesh and Tangier above
 * Düsseldorf.
 *
 * IT IS NOT SUFFICIENT ON ITS OWN, and the measurements are why. Across the
 * three home sweeps on 2026-08-16, €/km put Singapore (€287, 27.3 m€/km),
 * Manila (€293, 28.1) and Bangkok (€271, 29.4) in the same band as Málaga (€36,
 * 19.1) and Marrakesh (€27, 10.8). Every one of those long-haul numbers is a
 * genuinely remarkable fare and NOT ONE OF THEM IS THE THING THIS SCREEN IS
 * FOR: €287 is a holiday somebody plans, and the promise here is a fare you
 * look at on a Tuesday and book on the Tuesday. So DiscoveryPolicy carries an
 * absolute ceiling alongside the ratio, and the ratio does the ordering
 * underneath it.
 *
 * MILLIEUROS PER KILOMETRE IS HOW THE NUMBERS READ. €0.0108/km is four leading
 * zeros to scan past; 10.8 m€/km is a figure somebody can hold two of in their
 * head and compare. `centsPerKilometre()` answers the storable integer-ish
 * version and the config thresholds are quoted in the same unit, so nothing in
 * this feature ever has to agree about where a decimal point goes.
 */
final readonly class DealCandidate
{
    public function __construct(
        public string $originIata,
        public string $destinationIata,
        public DateTimeImmutable $departureDate,
        public int $cents,
        /** Great-circle origin→destination, from App\Domain\Geo\Haversine. */
        public float $kilometres,
        public ?DateTimeImmutable $foundAt = null,
    ) {}

    /**
     * `AMS-AGP` — the app's own route code, and the reason this type carries
     * both ends rather than a destination alone.
     *
     * IT IS THE HAND-OFF. A discovery is not a new screen: tapping one opens
     * `/route/AMS-AGP`, which is the SAME lookup flow the search screen has
     * used since "look before you watch" (App\Http\Controllers\RouteController).
     * That is only possible because a candidate can name itself in the one
     * spelling the rest of the app keys on — App\Models\Route::codeFor's.
     */
    public function routeCode(): string
    {
        return $this->originIata.'-'.$this->destinationIata;
    }

    /**
     * What a kilometre of this flight costs, in EURO CENTS.
     *
     * THE RANKING NUMBER, and it is stored on the row as well as computed here
     * — see the migration for why a derived value earns a column in this one
     * table. A candidate with no distance cannot be ranked and never reaches
     * this method: the scorer drops it, because dividing by zero here would
     * produce INF, and INF sorts to the front of the cheapest-first list.
     */
    public function centsPerKilometre(): float
    {
        return $this->kilometres > 0.0
            ? $this->cents / $this->kilometres
            : INF;
    }

    /**
     * How old the price is, in days, as of `$now` — or null if the provider
     * would not say when it found it.
     *
     * NULL IS "NOT KNOWN" AND THE POLICY TREATS IT AS TOO OLD, which is the
     * opposite of what App\Domain\Alerts\AlertPolicy does with the same fact,
     * and the asymmetry is deliberate. There, null had to mean fresh: the
     * column arrived after the rows did, and a rule that silenced alerts on
     * not-knowing would have turned the alert system off on the morning it
     * shipped. Here there is no legacy — every row in a sweep is minted by this
     * feature — and the claim being made is much stronger. "Insanely cheap" is
     * a claim about RIGHT NOW, so a price of unknown vintage is exactly the one
     * that must not be on the screen. See DiscoveryPolicy::isFresh().
     */
    public function ageInDays(DateTimeImmutable $now): ?float
    {
        if ($this->foundAt === null) {
            return null;
        }

        return ($now->getTimestamp() - $this->foundAt->getTimestamp()) / 86400;
    }
}
