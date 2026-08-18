<?php

declare(strict_types=1);

namespace App\Application\Alerts;

use App\Models\User;
use App\Models\Alert;
use App\Models\Route;
use DateTimeInterface;
use App\Models\DealRule;
use Carbon\CarbonImmutable;
use App\Domain\Alerts\AlertType;
use App\Domain\Alerts\LastAlert;
use Illuminate\Database\Eloquent\Collection;

/**
 * The `alerts` table, as the pipeline uses it: what was already said, and the
 * writing down of what is about to be.
 *
 * THE COOLDOWN IS READ IN ONE QUERY, not one per route. A run asks about every
 * watched route and every route every rule matched — thirty of them is an
 * ordinary morning — and a `SELECT` per route would be a fan-out of tiny
 * queries in a job that already has a provider's rate limit to worry about.
 *
 * AND ONLY THE COOLDOWN WINDOW IS FETCHED. Anything older than
 * config('orbit.alerts.cooldown_hours') cannot suppress anything —
 * App\Domain\Alerts\AlertPolicy fires on it either way — so it does not need to
 * be in memory. That keeps the read a constant size on a table that grows
 * forever, and it is why `recentFor()` returning nothing for a route means
 * "nothing is holding this back" rather than "this route has never fired".
 *
 * BOTH THIS AND THE POLICY READ THE SAME CONFIG KEY for that window, which is
 * what makes the two safe to reason about separately: a cooldown lengthened in
 * config lengthens the query with it, and a ledger that fetched a shorter
 * window than the policy enforced would silently stop suppressing anything.
 */
final class AlertLedger
{
    /**
     * What has been said recently, keyed by kind-and-route-and-rule.
     *
     * @return array<string, LastAlert>
     */
    public function recentFor(User $user, DateTimeInterface $now): array
    {
        $since = CarbonImmutable::instance($now)->subHours($this->cooldownHours());

        $rows = Alert::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('triggered_at', '>=', $since)
            ->orderByDesc('triggered_at')
            ->orderByDesc('id')
            ->get(['route_id', 'deal_rule_id', 'type', 'price_cents', 'triggered_at']);

        $recent = [];

        foreach ($rows as $row) {
            if ($row->price_cents === null) {
                /* The digest. It is in the ledger but it suppresses nothing. */
                continue;
            }

            /* Newest first, so the first one seen for a key is the last one sent. */
            $recent[self::key($row->type, $row->route_id, $row->deal_rule_id)] ??= new LastAlert(
                $row->triggered_at->toDateTimeImmutable(),
                $row->price_cents,
            );
        }

        return $recent;
    }

    /**
     * What "the same alert as last time" means.
     *
     * THE RULE IS PART OF THE KEY, so two rules that both match AMS-FAO are two
     * cooldowns rather than one. Each rule is a separate question the owner
     * asked, and a match answering one of them is not an answer to the other —
     * suppressing it would mean a rule written this morning stays silent
     * because a different rule mentioned the route yesterday, which reads
     * exactly like the new rule not working.
     */
    public static function key(AlertType $type, ?int $routeId, ?int $ruleId): string
    {
        return $type->value.'|'.($routeId ?? 0).'|'.($ruleId ?? 0);
    }

    /**
     * Write down that Orbit decided to say this.
     *
     * `triggered_at` IS THE DECISION AND `delivered_at` STAYS NULL until a
     * channel confirms — see App\Infrastructure\Notify\MarkAlertsDelivered.
     * Quiet hours can put hours between the two, and the cooldown deliberately
     * runs from the first: a window that stretched by however long somebody
     * slept would not be a 24-hour cooldown.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(
        User $user,
        AlertType $type,
        ?Route $route,
        ?DealRule $rule,
        ?int $score,
        ?int $priceCents,
        array $payload,
        DateTimeInterface $now,
    ): Alert {
        return Alert::query()->create([
            'user_id'      => $user->getAuthIdentifier(),
            'route_id'     => $route?->id,
            'deal_rule_id' => $rule?->id,
            'type'         => $type,
            'score'        => $score,
            'price_cents'  => $priceCents,
            'payload'      => $payload,
            'channel'      => Alert::CHANNEL_MAIL,
            'triggered_at' => $now,
            'delivered_at' => null,
        ]);
    }

    /**
     * A transport took these. Stamped once — a row that has already been
     * delivered is not re-stamped by a retry, so the timestamp keeps meaning
     * "when it first went out".
     *
     * @param  list<int>  $ids
     */
    public function markDelivered(array $ids, DateTimeInterface $at): void
    {
        if ($ids === []) {
            return;
        }

        Alert::query()
            ->whereIn('id', $ids)
            ->whereNull('delivered_at')
            ->update(['delivered_at' => $at]);
    }

    /**
     * What Orbit actually sent this account since `$since` — the digest's
     * "this week" callout.
     *
     * DELIVERED ROWS ONLY, and route-or-rule rows only. A deal held by quiet
     * hours and still in the queue has not been seen yet, and a digest that
     * counted it would be telling somebody about mail they are about to
     * receive; the previous digest is excluded because a digest listing itself
     * is not a week's news.
     *
     * @return Collection<int, Alert>
     */
    public function delivered(User $user, DateTimeInterface $since): Collection
    {
        return Alert::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->whereIn('type', [AlertType::RouteDeal, AlertType::RuleMatch])
            ->whereNotNull('delivered_at')
            ->where('triggered_at', '>=', $since)
            ->orderByDesc('triggered_at')
            ->orderByDesc('id')
            ->get();
    }

    private function cooldownHours(): int
    {
        return (int) config('orbit.alerts.cooldown_hours');
    }
}
