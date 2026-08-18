<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use App\Domain\Alerts\AlertType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the alert ledger: something Orbit decided to say, and whether it
 * has been said yet.
 *
 * PLAIN CRUD ON PURPOSE, like every other model here — docs/PLAN.md is explicit
 * that Eloquent is used directly for this half. The decisions live in
 * App\Domain\Alerts\AlertPolicy and the writing lives in
 * App\Application\Alerts\AlertLedger; this is the row.
 *
 * `type` IS CAST TO THE ENUM, so the four places that care about it — the
 * cooldown lookup, the mail adapter's per-setting gate, the digest's "this
 * week" filter and `GET /api/alerts` — all read the same value object rather
 * than four spellings of `rule_match`.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $route_id
 * @property int|null $deal_rule_id
 * @property AlertType $type
 * @property int|null $score
 * @property int|null $price_cents
 * @property array<string, mixed> $payload
 * @property string $channel
 * @property CarbonImmutable $triggered_at
 * @property CarbonImmutable|null $delivered_at
 * @property-read User $user
 * @property-read Route|null $route
 * @property-read DealRule|null $rule
 */
#[Fillable([
    'user_id',
    'route_id',
    'deal_rule_id',
    'type',
    'score',
    'price_cents',
    'payload',
    'channel',
    'triggered_at',
    'delivered_at',
])]
final class Alert extends Model
{
    /** The one channel there is an adapter for. See the migration. */
    public const CHANNEL_MAIL = 'mail';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Route, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /**
     * NAMED `rule` AND NOT `dealRule`, because that is the word the product
     * uses everywhere else (design/README.md §4, `GET /api/rules`). The column
     * has to be named for the table, so it is spelled out here rather than
     * being guessed from the method name.
     *
     * @return BelongsTo<DealRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(DealRule::class, 'deal_rule_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type'         => AlertType::class,
            'score'        => 'integer',
            'price_cents'  => 'integer',
            'payload'      => 'array',
            'triggered_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }
}
