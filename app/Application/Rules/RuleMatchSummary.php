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
 */
final readonly class RuleMatchSummary
{
    /**
     * @param  list<RuleMatch>  $matches  every match, cheapest first
     * @param  list<RuleMatch>  $sample  the first config('orbit.rules.sample') of them
     */
    private function __construct(
        public array $matches,
        public array $sample,
    ) {}

    /**
     * @param  list<RuleMatch>  $matches  cheapest first
     */
    public static function of(array $matches, int $sampleSize): self
    {
        return new self($matches, array_slice($matches, 0, max($sampleSize, 0)));
    }

    public static function none(): self
    {
        return new self([], []);
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
