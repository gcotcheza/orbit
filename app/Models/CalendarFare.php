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
 * TWO TIMESTAMPS THAT SOUND ALIKE AND ARE NOT. `fetched_at` is when ORBIT
 * asked; `found_at` is when the PRICE was found by whoever's search turned it
 * up. The provider is a cache, so a row fetched this morning can hold a fare
 * last seen on Tuesday — see the `found_at` migration for the €36-that-was-€56
 * this distinction was added for. `found_at` is nullable and null means "not
 * known", which every screen renders as nothing at all.
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'departure_date' => 'immutable_date',
            'fetched_at' => 'immutable_datetime',
            'found_at' => 'immutable_datetime',
            'price_cents' => 'integer',
        ];
    }
}
