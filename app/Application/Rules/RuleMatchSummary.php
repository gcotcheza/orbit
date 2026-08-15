<?php

declare(strict_types=1);

namespace App\Application\Rules;

use App\Domain\Pricing\DatedFare;

/**
 * "6 trips match this right now — cheapest €34" (design/README.md §4), plus
 * the handful the screen can actually show.
 *
 * THE COUNT IS OF EVERYTHING AND THE SAMPLE IS NOT. The banner's number has to
 * be the truth about the rule — a rule that matches sixty routes is a rule
 * somebody should probably tighten, and a "6" capped by the sample size would
 * hide exactly that. The sample is what fits on a phone.
 *
 * EMPTY IS A REAL ANSWER, not an error: a rule with no matches yet is the
 * ordinary state of a rule created ten seconds ago, before
 * App\Jobs\SweepRuleFares has fetched a fare for any of the routes it named.
 *
 * AND SO IS A COUNT THAT IS ONLY A FLOOR, which is what `pending` is for. The
 * same sweep is the reason a rule can say "2 trips match" before it is saved
 * and "32 trips match" a minute after: the count was never wrong, it was
 * counted over the candidates Orbit had already priced. `pending` is how many
 * of them it had not, so the screen can phrase the number as the floor it is
 * rather than as a total the next refresh contradicts.
 */
final readonly class RuleMatchSummary
{
    /**
     * @param  list<RuleMatch>  $matches  every match, cheapest first
     * @param  list<RuleMatch>  $sample  the first config('orbit.rules.sample') of them
     * @param  int  $pending  candidate routes this rule is about that Orbit holds
     *                        no fare for yet — see the note above
     */
    private function __construct(
        public array $matches,
        public array $sample,
        public int $pending,
    ) {}

    /**
     * @param  list<RuleMatch>  $matches  cheapest first
     */
    public static function of(array $matches, int $sampleSize, int $pending = 0): self
    {
        return new self($matches, array_slice($matches, 0, max($sampleSize, 0)), max($pending, 0));
    }

    public static function none(int $pending = 0): self
    {
        return new self([], [], max($pending, 0));
    }

    /**
     * Is the count below a floor rather than a total?
     *
     * TRUE MEANS "AT LEAST", and the screen has to say so. `count()` is the
     * truth about the fares Orbit HOLDS, which on the create screen is a
     * different question from the one the person typing is asking: they mean
     * "how many trips are there", and the answer to that is still arriving —
     * App\Jobs\SweepRuleFares prices the rest after the rule is saved. A "2"
     * that becomes 32 a minute later was not wrong, it was unqualified, and an
     * unqualified number is the one somebody decides not to save a rule on.
     */
    public function partial(): bool
    {
        return $this->pending > 0;
    }

    public function count(): int
    {
        return count($this->matches);
    }

    /**
     * The cheapest fare any of them found — the second half of the banner's
     * sentence. NULL when nothing matched, which the screen renders as the
     * "nothing yet" state rather than as €0.
     */
    public function cheapest(): ?DatedFare
    {
        return $this->matches[0]->cheapest ?? null;
    }
}
