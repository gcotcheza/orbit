<?php

declare(strict_types=1);

namespace App\Application\Rules;

use App\Domain\Rules\ParsedRule;
use App\Domain\Rules\RuleVocabulary;
use App\Models\DealRule;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns rules — saved ones, and the one somebody is still typing — into what
 * the API publishes.
 *
 * ONE PLACE THAT KNOWS A RULE IS "CHIPS PLUS CRITERIA PLUS MATCHES". The
 * create screen (`POST /api/rules/parse`) and the watch screen
 * (`GET /api/rules`) draw the same three things from different starting
 * points, and the whole point of this class is that they cannot disagree about
 * what a rule looks like: one of them starts from text and one from a stored
 * criteria object, and both end up here.
 *
 * A STORED RULE'S CHIPS ARE REBUILT FROM ITS CRITERIA, NOT FROM ITS TEXT, and
 * that is the load-bearing line in this file. The criteria are what the owner
 * accepted after removing the chips they disagreed with; re-parsing
 * `raw_text` would put every removed chip straight back on the row and make
 * the correction look like it never happened.
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
