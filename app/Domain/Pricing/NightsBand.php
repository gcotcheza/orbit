<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use InvalidArgumentException;

/**
 * How long you would stay, as a range. A type, not two nullable ints; nights, never days;
 * zero is legal; inclusive at both ends (docs/BUSINESS-LOGIC.md §15).
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
     * The `[min, max]` pair shape from config/orbit.php's `returns.durations` and
     * RuleCriteria::$tripLengthNights (docs/BUSINESS-LOGIC.md §15).
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
