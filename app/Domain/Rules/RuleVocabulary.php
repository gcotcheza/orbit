<?php

declare(strict_types=1);

namespace App\Domain\Rules;

/**
 * The words this app knows, handed to the parts of it that are not allowed to
 * call config().
 *
 * App\Domain is pure PHP — that is docs/PLAN.md's line, and the reason the
 * deal scorer takes a ScoringPolicy rather than reading its weights. The rule
 * parser has the same problem three times over (which airports, which vibes,
 * what each is called), so it gets the same answer: one value, built once in
 * App\Providers\AppServiceProvider, injected into both adapters and into
 * ParsedRule.
 *
 * IT IS ALSO WHY THE REGEX PARSER IS A UNIT TEST AND NOT A FEATURE TEST. A
 * parser that queried the airports table for "amsterdam" would need a database
 * to prove it can read a sentence.
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
     * The chip text for a vibe, falling back to the vibe itself.
     *
     * The fallback is for a vibe that reached us from a stored rule or from
     * the model after somebody edited the vocabulary — showing the raw word is
     * a worse chip, and a missing-key crash is a worse screen.
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
