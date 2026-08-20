<?php

declare(strict_types=1);

namespace App\Domain\Rules;

/**
 * The words this app knows, handed to parts not allowed to call config() — App\Domain is pure PHP (docs/PLAN.md).
 * Built once in AppServiceProvider (docs/BUSINESS-LOGIC.md §11).
 */
final readonly class RuleVocabulary
{
    /**
     * @param  list<string>  $origins  config('orbit.origins')
     * @param  array<string, string>  $originAliases  lower-case word a person types => IATA code
     * @param  array<string, list<string>>  $vibeWords  vibe => the words that mean it, longest first
     * @param  array<string, string>  $vibeLabels  vibe => the chip's text
     */
    public function __construct(
        public array $origins,
        public array $originAliases,
        public array $vibeWords,
        public array $vibeLabels,
    ) {}

    /**
     * The chip text for a vibe, falling back to the vibe itself — a stored
     * rule may predate an edited vocabulary; a crash is worse than the raw word.
     */
    public function labelFor(string $vibe): string
    {
        return $this->vibeLabels[$vibe] ?? ucfirst($vibe);
    }

    /**
     * Every vibe this app understands.
     *
     * @return list<string>
     */
    public function vibes(): array
    {
        return array_keys($this->vibeWords);
    }
}
