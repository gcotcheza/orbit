<?php

declare(strict_types=1);

namespace App\Application\Alerts;

use Illuminate\Support\Str;
use InvalidArgumentException;
use App\Domain\Alerts\AlertType;

/**
 * What one standing rule found this morning, all of it in one mail. Chips come from RuleViews,
 * rebuilt from stored criteria and never re-parsed (docs/BUSINESS-LOGIC.md §10).
 */
final readonly class RuleMatchNotice implements AlertNotice
{
    /**
     * The cheapest of them, which every line of copy leads with. A property, not `$deals[0]`
     * at four sites — it also states the no-empty-notice invariant.
     */
    public DealSummary $cheapest;

    /**
     * @param  string  $ruleText  what was typed, for the "your rule" line
     * @param  list<string>  $chips  the rule's chip labels, in the design's order
     * @param  list<DealSummary>  $deals  every new match, cheapest first
     */
    public function __construct(
        public int $ruleId,
        public string $ruleText,
        public array $chips,
        public array $deals,
    ) {
        if ($deals === []) {
            throw new InvalidArgumentException('A rule alert with no matches is not news.');
        }

        $this->cheapest = $deals[0];
    }

    public function type(): AlertType
    {
        return AlertType::RuleMatch;
    }

    /**
     * The rule is named in the subject: "4 new matches" with no idea which rule is a mail
     * that has to be opened just to find out whether it is worth opening.
     */
    public function subject(): string
    {
        /* One ellipsis character rather than three dots: a subject line is
         * measured in the pixels a phone has, not in characters. */
        $rule = Str::limit($this->ruleText, 40, '…');

        if (count($this->deals) === 1) {
            return sprintf('✈ %s — “%s”', $this->cheapest->headline(), $rule);
        }

        return sprintf(
            '✈ %d new matches from %s — “%s”',
            count($this->deals),
            $this->cheapest->price(),
            $rule,
        );
    }
}
