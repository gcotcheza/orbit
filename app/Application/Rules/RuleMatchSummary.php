<?php

declare(strict_types=1);

namespace App\Application\Rules;

use App\Domain\Pricing\DatedFare;

/**
 * "6 trips match this right now — cheapest €34" (design/README.md §4). The count is of
 * everything and may be only a floor; the sample is what fits (docs/BUSINESS-LOGIC.md §11).
 */
final readonly class RuleMatchSummary
{
    /**
     * @param  list<RuleMatch>  $matches  every match, cheapest first
     * @param  list<RuleMatch>  $sample  the first config('orbit.rules.sample') of them
     * @param  int  $pending  candidate routes this rule is about that Orbit holds
     *                        no fare for yet
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
     * Is the count below a floor rather than a total? True means "at least": count() is what
     * Orbit holds, not what exists (docs/BUSINESS-LOGIC.md §11).
     */
    public function partial(): bool
    {
        return $this->pending > 0;
    }

    public function count(): int
    {
        return count($this->matches);
    }

    // Null when nothing matched; the screen renders that as "nothing yet", not €0.
    // Why: docs/BUSINESS-LOGIC.md §11.
    public function cheapest(): ?DatedFare
    {
        return $this->matches[0]->cheapest ?? null;
    }
}
