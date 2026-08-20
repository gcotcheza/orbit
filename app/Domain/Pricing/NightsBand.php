<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use InvalidArgumentException;

/**
 * How long you would stay, as a range — "a long weekend", "a fortnight".
 *
 * A type, not two nullable ints, so a reversed or negative pair is caught once here rather than silently matching
 * nothing (or fares that don't exist).
 *
 * Nights, never days — config/orbit.php, `return_fares.nights` and RuleCriteria::$tripLengthNights all count nights;
 * an off-by-one here silently answers the neighbouring question.
 *
 * Zero is a legal minimum, not a degenerate case — same-day returns are real fares the live cache serves. See
 * App\Domain\Pricing\ReturnTrip.
 *
 * Inclusive at both ends, matching how RuleCriteria::$tripLengthNights already documents "[min, max]" to read
 * (docs/BUSINESS-LOGIC.md §15).
 */
final readonly class NightsBand
{
    public function __construct(
        public int $min,
        public int $max,
    ) {
        if ($min < 0) {
            throw new InvalidArgumentException("A stay cannot be {$min} nights long.");
        }

        if ($max < $min) {
            throw new InvalidArgumentException(
                "A nights band runs from its shorter end to its longer one; got [{$min}, {$max}].",
            );
        }
    }

    /**
     * The `[min, max]` pair shape from config/orbit.php's `returns.durations` and RuleCriteria::$tripLengthNights
     * (docs/BUSINESS-LOGIC.md §15).
     *
     * @param  array{int, int}  $pair
     */
    public static function of(array $pair): self
    {
        return new self($pair[0], $pair[1]);
    }

    public function contains(int $nights): bool
    {
        return $nights >= $this->min && $nights <= $this->max;
    }
}
