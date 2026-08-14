<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cheapest fare found for one departure date — one cell of the heatmap.
 *
 * @property int $id
 * @property int $route_id
 * @property CarbonImmutable $departure_date
 * @property int $price_cents
 * @property CarbonImmutable $fetched_at
 * @property-read Route $route
 */
#[Fillable(['route_id', 'departure_date', 'price_cents', 'fetched_at'])]
final class CalendarFare extends Model
{
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
            'departure_date' => 'immutable_date',
            'fetched_at' => 'immutable_datetime',
            'price_cents' => 'integer',
        ];
    }
}
