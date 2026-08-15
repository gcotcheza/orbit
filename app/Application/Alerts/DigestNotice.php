<?php

declare(strict_types=1);

namespace App\Application\Alerts;

use App\Domain\Alerts\AlertType;

/**
 * The Sunday morning mail: where every watched route stands, what the rules are
 * finding, and what Orbit already flagged this week.
 *
 * THE ONE MAIL THAT IS NOT AN INTERRUPTION. Everything else in this app fires
 * because something crossed a line; this one arrives whether or not it did, and
 * its job is the opposite — to make a week with no alerts legible ("nothing
 * crossed your threshold, here is where things are") rather than silent. That
 * is why it repeats routes the cooldown has been suppressing all week: the
 * cooldown is about not interrupting, and this is not an interruption.
 *
 * IT REFUSES TO BE EMPTY. `isEmpty()` is what App\Jobs\SendWeeklyDigest checks
 * before it writes a ledger row or sends anything: an account with no watched
 * routes, no rules and no alerts this week gets no mail at all. A weekly "you
 * have nothing" is a weekly reminder to unsubscribe.
 */
final readonly class DigestNotice implements AlertNotice
{
    /**
     * @param  list<DealSummary>  $routes  every watched route, cheapest first
     * @param  list<RuleDigest>  $rules  each active rule with its best matches
     * @param  list<DealSummary>  $week  what Orbit alerted on in the last
     *                                   config('orbit.alerts.digest_days') days,
     *                                   read back out of the ledger
     */
    public function __construct(
        public array $routes,
        public array $rules,
        public array $week,
    ) {}

    public function type(): AlertType
    {
        return AlertType::WeeklyDigest;
    }

    public function isEmpty(): bool
    {
        return $this->routes === [] && $this->rules === [] && $this->week === [];
    }

    /**
     * The cheapest thing in the whole mail, whichever section it came from —
     * what the subject leads with when there is nothing louder to say.
     */
    public function cheapest(): ?DealSummary
    {
        $cheapest = null;

        foreach ([$this->routes, $this->week] as $deals) {
            foreach ($deals as $deal) {
                if ($cheapest === null || $deal->priceCents < $cheapest->priceCents) {
                    $cheapest = $deal;
                }
            }
        }

        foreach ($this->rules as $rule) {
            foreach ($rule->deals as $deal) {
                if ($cheapest === null || $deal->priceCents < $cheapest->priceCents) {
                    $cheapest = $deal;
                }
            }
        }

        return $cheapest;
    }

    /**
     * A WEEK WITH ALERTS IN IT SAYS SO FIRST. "3 deals worth a look" is a
     * different mail from "here is your week", and somebody deciding in a
     * notification shade whether to open it is deciding between those two.
     */
    public function subject(): string
    {
        if ($this->week !== []) {
            return sprintf(
                'Your week in fares — %d deal%s Orbit flagged',
                count($this->week),
                count($this->week) === 1 ? '' : 's',
            );
        }

        $cheapest = $this->cheapest();

        return $cheapest === null
            ? 'Your week in fares'
            : sprintf('Your week in fares — cheapest %s %s', $cheapest->pair(), $cheapest->price());
    }

    /**
     * What the ledger stores for a digest.
     *
     * COUNTS AND NOT COPIES. The deals themselves already have rows of their
     * own from the mornings they fired, and duplicating them here would make
     * `GET /api/alerts` show the same fare twice with two different dates on
     * it. What is worth remembering about a digest is that it went out and how
     * much was in it.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'routes' => count($this->routes),
            'rules' => count($this->rules),
            'week' => count($this->week),
            'headline' => $this->subject(),
        ];
    }
}
