<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use InvalidArgumentException;

/**
 * How long you would stay, as a range — "a long weekend", "a fortnight".
 *
 * A PAIR OF NUMBERS THAT ONLY MEAN ANYTHING TOGETHER, which is the whole reason
 * this is a type and not two nullable int arguments strung through the port. A
 * band with its ends the wrong way round matches nothing and reports no error;
 * a band whose minimum is negative matches fares that do not exist. Both are
 * caught here, once, at the moment somebody writes the pair down.
 *
 * NIGHTS AND NOT DAYS, AND THE APP MUST NEVER DRIFT ON THIS. A Friday-to-Sunday
 * trip is TWO nights and three days, and the two counts differ by one for every
 * trip there has ever been. `nights` is what config/orbit.php's `returns.
 * durations` lists, what `return_fares.nights` stores, what
 * App\Domain\Rules\RuleCriteria::$tripLengthNights already parses ("weekend" is
 * [2, 3]), and therefore what this band counts. An off-by-one here is a band
 * that silently answers the neighbouring question.
 *
 * ZERO IS A LEGAL MINIMUM AND IS NOT A DEGENERATE CASE. A same-day return is a
 * real fare the live cache serves — one AMS-LIS entry and two EIN-BCN entries
 * carried `return_date == depart_date` in the 198 recorded on 2026-08-16 — so
 * the floor is 0 rather than 1. See App\Domain\Pricing\ReturnTrip.
 *
 * INCLUSIVE AT BOTH ENDS, because that is how a person reads "6 to 8 nights"
 * and how `RuleCriteria::$tripLengthNights` is already documented to read
 * ("[min, max]"). The day this became exclusive at the top, every `[2, 3]`
 * weekend rule would quietly stop matching Sunday returns.
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
     * The `[min, max]` pair shape — which is what config/orbit.php's
     * `returns.durations` holds and what App\Domain\Rules\RuleCriteria has
     * carried in `$tripLengthNights` since the rules engine shipped.
     *
     * TAKING THE ARRAY RATHER THAN LEAVING CALLERS TO DESTRUCTURE IT is what
     * keeps the validation above on the path everything actually uses: a config
     * file edited to `[8, 6]` fails when it is read, with the pair in the
     * message, rather than producing a band that matches nothing at all.
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
