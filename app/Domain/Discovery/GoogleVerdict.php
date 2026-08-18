<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * What Google Flights says about a route and date, as a value Orbit can act on.
 *
 * Built by App\Infrastructure\Verify\GoogleFlightsCheck out of SerpAPI's
 * `price_insights` block. It is the SECOND OPINION in the funnel and the only
 * one that comes from outside Travelpayouts — which is the entire reason it is
 * worth a metered request: every other number in this feature descends from the
 * same cache, so a cache that is wrong is a funnel that is confidently wrong all
 * the way to the screen.
 *
 * =============================================================================
 * ⚠ THE ONE DECISION IN THIS FILE, AND THE MEASUREMENT THAT FORCED IT
 * =============================================================================
 * The obvious rule for "did Google confirm this?" is: our candidate's price is
 * below Google's `typical_price_range` low. It is obvious, it is one comparison,
 * AND IT CONFIRMS EVERYTHING. Three real finalists were put to Google on
 * 2026-08-16, the same day the sweep was recorded:
 *
 *   route     Travelpayouts   Google's OWN cheapest   level     typical range
 *   DUS-AGP        €29                €70            typical      55 – 175
 *   DUS-RAK        €27               €168            typical     100 – 200
 *   EIN-VNO        €18                €30            typical      20 – 245
 *
 * Every one of those candidates is under its typical-range low. Every one would
 * have been stamped "✓ verified low by Google". And Google — asked for the same
 * airports on the same date — COULD NOT FIND A SEAT AT ANYTHING LIKE THE PRICE:
 * Marrakesh at €27 against a real market of €168 is not a bargain, it is a
 * six-fold discrepancy, and stamping it verified would be Orbit putting a second
 * company's name on its own stale cache entry.
 *
 * That is not a hypothetical failure. It is the SAME failure this app has
 * already shipped twice and written down twice: €36 shown for a date whose live
 * cheapest was €56 (App\Domain\Pricing\DatedFare), and DUS-AGP quoted at €29
 * against a Skyscanner cheapest of €68 (config/orbit.php, `booking`). The
 * candidate's own price is the number UNDER SUSPICION. Testing it against
 * Google's range asks the suspect to vouch for itself.
 *
 * SO THE VERDICT IS ABOUT GOOGLE'S MARKET, NOT ABOUT OUR NUMBER:
 *
 *     confirmed  ⇔  price_level is "low"  OR  Google's own lowest_price is at
 *                   or under its typical-range low
 *
 * Both halves are Google talking about what Google can sell today. It is
 * STRICTLY NARROWER than the candidate-price reading — nothing it confirms
 * would have been refused by the other rule — so it honours "only labelled
 * insane if" in the direction that word is meant to point. On the three
 * finalists above it confirms NOTHING, which is the correct answer and is what
 * the funnel is supposed to feel like: those three are shown as "great find",
 * unverified, with the age printed next to them.
 *
 * `lowest` IS CARRIED EVEN THOUGH THE RULE COULD BE EVALUATED WITHOUT IT,
 * because it is the most useful thing on the card for a person deciding whether
 * to trust a fare: "Orbit says €27, Google says €168" is a sentence the reader
 * can act on, and hiding it would be keeping the disagreement to ourselves.
 */
final readonly class GoogleVerdict
{
    /** Google's three words for a fare, as `price_insights.price_level`. */
    public const LEVEL_LOW = 'low';

    public function __construct(
        /** `low`, `typical`, `high` — or null when Google had no opinion. */
        public ?string $level,
        /** The cheapest seat Google itself could find, in cents; null if silent. */
        public ?int $lowestCents,
        /** `typical_price_range`, in cents. Null when Google published no band. */
        public ?int $typicalLowCents,
        public ?int $typicalHighCents,
    ) {}

    /**
     * Does Google's market agree that this route and date are cheap right now?
     *
     * READ THE CLASS DOCBLOCK BEFORE CHANGING THIS. The absent candidate price
     * in the signature is the decision, not an oversight.
     *
     * A VERDICT WITH NOTHING IN IT CONFIRMS NOTHING. Google answering without a
     * `price_insights` block is common on thin routes, and it is a real answer
     * — "no opinion" — rather than an error. It is also not permission.
     */
    public function confirmsCheap(): bool
    {
        if ($this->level === self::LEVEL_LOW) {
            return true;
        }

        if ($this->lowestCents === null || $this->typicalLowCents === null) {
            return false;
        }

        return $this->lowestCents <= $this->typicalLowCents;
    }

    /**
     * The row's `google_verdict` column, or null when there is nothing to say.
     *
     * STORED AS THE FACTS AND NOT AS THE CONCLUSION. `confirmed` is derivable
     * from the other four and is written anyway, because the API resource and
     * the badge both read it and a screen recomputing a verdict from parts is
     * how two places come to disagree about one fare. If the rule above is ever
     * retuned, the stored `confirmed` is what that day's cards actually claimed
     * — the same reason App\Http\Resources\AlertResource reads the ledger's
     * payload rather than today's statistics.
     *
     * @return array{level: string|null, lowest: int|null, typical_low: int|null, typical_high: int|null, confirmed: bool}
     */
    public function toArray(): array
    {
        return [
            'level'        => $this->level,
            'lowest'       => $this->lowestCents,
            'typical_low'  => $this->typicalLowCents,
            'typical_high' => $this->typicalHighCents,
            'confirmed'    => $this->confirmsCheap(),
        ];
    }
}
