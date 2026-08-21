<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use App\Domain\Pricing\PricePoint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One morning's answer for one route — a row of `route_price_history`, hence `$table`.
 *
 * @property int $id
 * @property int $route_id
 * @property CarbonImmutable $observed_on
 * @property int $price_cents
 * @property-read Route $route
 */
#[Fillable(['route_id', 'observed_on', 'price_cents'])]
final class PriceObservation extends Model
{
    protected $table = 'route_price_history';

    /**
     * @return BelongsTo<Route, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /**
     * The domain's view of this row. The scorer takes these and knows nothing
     * about Eloquent, which is the boundary docs/PLAN.md draws.
     */
    public function toPricePoint(): PricePoint
    {
        return new PricePoint($this->observed_on->toDateTimeImmutable(), $this->price_cents);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'observed_on' => 'immutable_date',
            'price_cents' => 'integer',
        ];
    }
}
