<?php

declare(strict_types=1);

namespace App\Application\Alerts;

use Illuminate\Support\Str;
use InvalidArgumentException;
use App\Domain\Alerts\AlertType;

/**
 * What one standing rule found this morning — everything of it, in one mail.
 *
 * ONE MAIL PER RULE PER RUN, AND THAT IS THE POINT OF THIS CLASS. "Somewhere
 * sunny under €80" is a sentence about a category, and on the morning a sale
 * starts it matches eleven routes at once. Eleven mails would be the single
 * fastest way to teach somebody to filter this app into a folder they never
 * open, which would cost them the one route in the eleven they would have
 * booked. So the run collects every NEW match — new meaning past the cooldown,
 * judged per route — and hands them over together.
 *
 * THE CHIPS COME FROM App\Application\Rules\RuleViews, rebuilt from the stored
 * criteria and never by re-parsing the text. The mail quotes what the rule
 * ACTUALLY asks for after the owner removed the chips they disagreed with; a
 * mail built from the sentence alone would tell somebody their rule includes a
 * departure airport they took off it.
 */
final readonly class RuleMatchNotice implements AlertNotice
{
    /**
     * The cheapest of them, which every line of copy here leads with.
     *
     * A PROPERTY RATHER THAN `$deals[0]` AT FOUR CALL SITES, because it also
     * states the invariant: a notice with no matches is not news and cannot be
     * built, so nothing downstream has to answer what an empty one would say.
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
     * THE RULE IS NAMED IN THE SUBJECT because the owner may have several, and
     * "4 new matches" with no idea which question they answer is a mail that
     * has to be opened to find out whether it was worth opening. Trimmed hard:
     * the informative half is at the front, where a phone still shows it.
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
