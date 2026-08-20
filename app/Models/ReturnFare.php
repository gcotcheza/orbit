<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cheapest round-trip found for one (departure date, stay length).
 *
 * Row behind App\Domain\Pricing\ReturnTrip; sibling of CalendarFare (one-way).
 * Never compare the two directly — a long-haul one-way reads ~2/3 of the return.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * `return_date` is NOT a column — derived from `nights` via `returnDate()`,
 * to avoid one fact stored (and drifting) twice.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * `fetched_at` (Orbit asked) vs `found_at` (price was found) are not
 * interchangeable; the gap is wider here than on the one-way table.
 * Why: docs/BUSINESS-LOGIC.md §36.
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
     * Day you'd fly home — derived, never stored. Deliberately NOT an
     * Attribute/accessor: that would leak into toArray()/API and invite
     * treating a derived value as a real column.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    public function returnDate(): CarbonImmutable
    {
        return $this->departure_date->addDays($this->nights);
    }

    /*
     * DELIBERATELY no duration-band scope yet — would guess at a shape no
     * caller has asked for. Migration's (route_id, nights, departure_date)
     * index already records the intent for whoever adds it.
     * Why: docs/BUSINESS-LOGIC.md §36.
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
