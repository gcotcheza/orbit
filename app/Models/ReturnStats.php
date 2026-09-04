<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a round trip usually costs on one route, for one duration band
 * (docs/BUSINESS-LOGIC.md §15, R4).
 *
 * @property int $id
 * @property int $route_id
 * @property int $nights_min
 * @property int $nights_max
 * @property int $min_cents
 * @property int $p25_cents
 * @property int $median_cents
 * @property int $p75_cents
 * @property int $max_cents
 * @property int $sample_count
 * @property CarbonImmutable $refreshed_at
 * @property-read Route $route
 */
#[Fillable([
    'route_id', 'nights_min', 'nights_max',
    'min_cents', 'p25_cents', 'median_cents', 'p75_cents', 'max_cents',
    'sample_count', 'refreshed_at',
])]
final class ReturnStats extends Model
{
    protected $table = 'return_price_stats';

    /**
     * @return BelongsTo<Route, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nights_min'   => 'integer',
            'nights_max'   => 'integer',
            'min_cents'    => 'integer',
            'p25_cents'    => 'integer',
            'median_cents' => 'integer',
            'p75_cents'    => 'integer',
            'max_cents'    => 'integer',
            'sample_count' => 'integer',
            'refreshed_at' => 'immutable_datetime',
        ];
    }
}
