<?php

declare(strict_types=1);

namespace App\Application\Alerts;

use App\Domain\Alerts\AlertType;

/**
 * The Sunday morning mail: what every watched route, rule and week-of-alerts looks like — the one
 * alert mail that is not an interruption (docs/BUSINESS-LOGIC.md §10).
 */
final readonly class DigestNotice implements AlertNotice
{
    /**
     * @param  list<DealSummary>  $routes  every watched route, cheapest first
     * @param  list<RuleDigest>  $rules  each active rule with its best matches
     * @param  list<DealSummary>  $week  what Orbit alerted on in the last
     *                                   config('orbit.alerts.digest_days') days, from the ledger
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

    /**
     * Whether App\Jobs\SendWeeklyDigest should skip mailing/logging this week entirely — a weekly
     * "you have nothing" is a reason to unsubscribe (docs/BUSINESS-LOGIC.md §10).
     */
    public function isEmpty(): bool
    {
        return $this->routes === [] && $this->rules === [] && $this->week === [];
    }

    /**
     * The cheapest thing in the whole mail, whichever section it came from — what the subject leads
     * with when there is nothing louder to say.
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
     * The subject leads with the alert count when there is one — that is the decision the reader is
     * making in a notification shade (docs/BUSINESS-LOGIC.md §10).
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
     * What the ledger stores for a digest: counts, not copies — the deals already have their own
     * ledger rows from when they fired (docs/BUSINESS-LOGIC.md §10).
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'routes'   => count($this->routes),
            'rules'    => count($this->rules),
            'week'     => count($this->week),
            'headline' => $this->subject(),
        ];
    }
}
