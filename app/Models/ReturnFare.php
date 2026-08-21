<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cheapest round-trip for one (departure date, stay length) (docs/BUSINESS-LOGIC.md §15).
 *
 * @property int $id
 * @property int $route_id
 * @property CarbonImmutable $departure_date
 * @property int $nights
 * @property int $price_cents
 * @property CarbonImmutable $fetched_at
 * @property CarbonImmutable|null $found_at
 * @property-read Route $route
 */
#[Fillable(['route_id', 'departure_date', 'nights', 'price_cents', 'fetched_at', 'found_at'])]
final class ReturnFare extends Model
{
    /**
     * @return BelongsTo<Route, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /**
     * Day you'd fly home — derived, never stored. Deliberately NOT an Attribute: that would
     * leak into toArray() and invite treating a derived value as a column.
     */
    public function returnDate(): CarbonImmutable
    {
        return $this->departure_date->addDays($this->nights);
    }

    /*
     * DELIBERATELY no duration-band scope yet — it would guess at a shape no caller has asked
     * for. The migration's index already records the intent.
     */

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'departure_date' => 'immutable_date',
            'fetched_at'     => 'immutable_datetime',
            'found_at'       => 'immutable_datetime',
            'nights'         => 'integer',
            'price_cents'    => 'integer',
        ];
    }
}
