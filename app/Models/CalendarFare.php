<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cheapest fare for one departure date — one heatmap cell (docs/BUSINESS-LOGIC.md §2).
 *
 * @property int $id
 * @property int $route_id
 * @property CarbonImmutable $departure_date
 * @property int $price_cents
 * @property CarbonImmutable $fetched_at
 * @property CarbonImmutable|null $found_at
 * @property-read Route $route
 */
#[Fillable(['route_id', 'departure_date', 'price_cents', 'fetched_at', 'found_at'])]
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
     * Departures on `$today` or later: a day that has gone cannot be booked.
     *
     * @param  Builder<CalendarFare>  $query
     * @return Builder<CalendarFare>
     */
    public function scopeDepartingFrom(Builder $query, CarbonInterface $today): Builder
    {
        return $query->where('departure_date', '>=', $today->toDateString());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'departure_date' => 'immutable_date',
            'fetched_at'     => 'immutable_datetime',
            'found_at'       => 'immutable_datetime',
            'price_cents'    => 'integer',
        ];
    }
}
