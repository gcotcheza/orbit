<?php

declare(strict_types=1);

namespace App\Application\Rules;

use App\Models\User;
use App\Models\DealRule;
use App\Domain\Rules\ParsedRule;
use Illuminate\Support\Collection;
use App\Domain\Rules\RuleVocabulary;

/**
 * The one place that knows a rule is chips plus criteria plus matches. A STORED RULE'S CHIPS
 * ARE REBUILT FROM ITS CRITERIA, never from its text (docs/BUSINESS-LOGIC.md §11).
 */
final readonly class RuleViews
{
    public function __construct(
        private RuleMatches $matches,
        private RuleVocabulary $vocabulary,
    ) {}

    /**
     * A parse, with what it would find. The create screen's whole response.
     */
    public function read(ParsedRule $parsed, User $user): RuleReading
    {
        return new RuleReading($parsed, $this->matches->for($parsed->criteria(), $user));
    }

    public function of(DealRule $rule, User $user): RuleView
    {
        return new RuleView($rule, $this->read(
            ParsedRule::of($rule->criteria(), $this->vocabulary),
            $user,
        ));
    }

    /**
     * @param  Collection<int, DealRule>  $rules
     * @return list<RuleView>
     */
    public function for(Collection $rules, User $user): array
    {
        return array_values($rules->map(fn (DealRule $rule): RuleView => $this->of($rule, $user))->all());
    }
}
