<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cheapest round-trip found for one (departure date, stay length).
 *
 * THE ROW BEHIND App\Domain\Pricing\ReturnTrip, and the sibling of
 * App\Models\CalendarFare — that one is a ONE-WAY price for a departure date,
 * this one is the price of going and coming back. The two are never mixed: a
 * long-haul one-way reads roughly two thirds of the return fare on the same
 * route, so a screen that put both in the same column would be comparing two
 * different trips.
 *
 * `return_date` IS NOT A COLUMN. `nights` is the stored fact and the return
 * date is derived from it (`returnDate()` below, and the migration's docblock
 * for the three reasons). Storing both would be one fact written twice, and the
 * copy that drifts is always the one somebody reads.
 *
 * TWO TIMESTAMPS THAT SOUND ALIKE AND ARE NOT, exactly as on CalendarFare:
 * `fetched_at` is when ORBIT asked, `found_at` is when the price was found by
 * whoever's search turned it up. Null means "not known" and renders as nothing
 * at all. The gap between them is WIDER here than on the one-way table —
 * `/v2/prices/latest` serves a seven-day-deep cache — which is why nothing may
 * substitute one for the other.
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
     * The day you would fly home — derived, never stored.
     *
     * NOT AN ACCESSOR/ATTRIBUTE, deliberately. An `Attribute` of this name
     * would appear in `toArray()` and in every API resource that spreads the
     * model, which is how a derived value starts being treated as a column and
     * ends up in somebody's `where` clause. A method has to be called on
     * purpose.
     */
    public function returnDate(): CarbonImmutable
    {
        return $this->departure_date->addDays($this->nights);
    }

    /*
     * NO DURATION-BAND SCOPE HERE YET, AND THAT IS ON PURPOSE. The obvious one
     * — `whereBetween('nights', [$band->min, $band->max])` — is one line, and
     * writing it before a single caller exists would be guessing at the shape
     * the screens want (cheapest per band? per departure week? per month?). The
     * migration's `(route_id, nights, departure_date)` index is where that
     * intent is recorded; the query that uses it belongs to the PR that has a
     * screen to justify it.
     */

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'departure_date' => 'immutable_date',
            'fetched_at' => 'immutable_datetime',
            'found_at' => 'immutable_datetime',
            'nights' => 'integer',
            'price_cents' => 'integer',
        ];
    }
}
