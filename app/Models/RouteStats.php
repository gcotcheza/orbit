<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pricing\PriceStats;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A route's five-number price summary, as last fetched.
 *
 * @property int $id
 * @property int $route_id
 * @property int $min_cents
 * @property int $p25_cents
 * @property int $median_cents
 * @property int $p75_cents
 * @property int $max_cents
 * @property CarbonImmutable $refreshed_at
 * @property-read Route $route
 */
#[Fillable(['route_id', 'min_cents', 'p25_cents', 'median_cents', 'p75_cents', 'max_cents', 'refreshed_at'])]
final class RouteStats extends Model
{
    protected $table = 'route_price_stats';

    /**
     * @return BelongsTo<Route, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /**
     * The domain value these five columns are a persisted copy of.
     */
    public function toPriceStats(): PriceStats
    {
        return new PriceStats(
            minCents: $this->min_cents,
            p25Cents: $this->p25_cents,
            medianCents: $this->median_cents,
            p75Cents: $this->p75_cents,
            maxCents: $this->max_cents,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_cents' => 'integer',
            'p25_cents' => 'integer',
            'median_cents' => 'integer',
            'p75_cents' => 'integer',
            'max_cents' => 'integer',
            'refreshed_at' => 'immutable_datetime',
        ];
    }
}
