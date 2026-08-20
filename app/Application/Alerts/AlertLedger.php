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
 * The `alerts` table, as the pipeline reads and writes it.
 *
 * Why: docs/BUSINESS-LOGIC.md §10.
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
     * What "the same alert as last time" means — the rule is part of the key.
     *
     * Why: docs/BUSINESS-LOGIC.md §10.
     */
    public static function key(AlertType $type, ?int $routeId, ?int $ruleId): string
    {
        return $type->value.'|'.($routeId ?? 0).'|'.($ruleId ?? 0);
    }

    /**
     * Write down that Orbit decided to say this; `triggered_at` is the cooldown anchor.
     *
     * Why: docs/BUSINESS-LOGIC.md §10.
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
     * A transport took these, stamped once so a retry does not re-stamp an
     * already-delivered row.
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
     * The digest's "this week" callout — delivered rows only.
     *
     * Why: docs/BUSINESS-LOGIC.md §10.
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
