<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One morning's round-trip price for one (route, duration band) — a row of
 * `return_price_history`, hence `$table` (docs/BUSINESS-LOGIC.md §15, R7).
 *
 * @property int $id
 * @property int $route_id
 * @property int $nights_min
 * @property int $nights_max
 * @property CarbonImmutable $observed_on
 * @property int $price_cents
 * @property int $nights
 * @property CarbonImmutable|null $found_at
 * @property-read Route $route
 */
#[Fillable(['route_id', 'nights_min', 'nights_max', 'observed_on', 'price_cents', 'nights', 'found_at'])]
final class ReturnObservation extends Model
{
    protected $table = 'return_price_history';

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
            'nights_min'  => 'integer',
            'nights_max'  => 'integer',
            'observed_on' => 'immutable_date',
            'price_cents' => 'integer',
            'nights'      => 'integer',
            'found_at'    => 'immutable_datetime',
        ];
    }
}
