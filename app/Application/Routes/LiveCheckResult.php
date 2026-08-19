<?php

declare(strict_types=1);

namespace App\Application\Routes;

use App\Models\LivePriceCheck;

/**
 * What came of a "check live price" tap. A null `check` is a refusal, and
 * `budgetRefused` is which of the two it was — docs/BUSINESS-LOGIC.md §17.
 */
final readonly class LiveCheckResult
{
    private function __construct(
        public ?LivePriceCheck $check,
        public bool $budgetRefused,
    ) {}

    public static function answered(LivePriceCheck $check): self
    {
        return new self($check, false);
    }

    public static function noBudget(): self
    {
        return new self(null, true);
    }

    public static function couldNotAsk(): self
    {
        return new self(null, false);
    }
}
