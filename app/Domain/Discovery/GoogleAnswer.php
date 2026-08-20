<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * The outcome of one SerpAPI search: whether it was spent, and what it bought.
 * `wasSpent` false means nothing was billed, so record no check (docs/BUSINESS-LOGIC.md §17).
 */
final readonly class GoogleAnswer
{
    private function __construct(
        public bool $wasSpent,
        public ?GoogleVerdict $verdict,
    ) {}

    public static function of(GoogleVerdict $verdict): self
    {
        return new self(true, $verdict);
    }

    public static function noOpinion(): self
    {
        return new self(true, null);
    }

    public static function couldNotAsk(): self
    {
        return new self(false, null);
    }
}
