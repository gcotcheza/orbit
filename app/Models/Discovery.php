<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use App\Domain\Discovery\Lane;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One route Orbit found on its own — no `route_id`, no `user_id` (docs/BUSINESS-LOGIC.md §16).
 *
 * @property int $id
 * @property int $origin_airport_id
 * @property int $destination_airport_id
 * @property string $code "AMS-AGP"
 * @property Lane $lane which claim this row is making — see the enum
 * @property CarbonImmutable $departure_date
 * @property int $price_cents
 * @property float $cents_per_km
 * @property float|null $percentile
 * @property int|null $savings_cents
 * @property array{level: string|null, lowest: int|null, typical_low: int|null, typical_high: int|null, confirmed: bool}|null $google_verdict
 * @property CarbonImmutable|null $found_at
 * @property CarbonImmutable $discovered_at
 * @property CarbonImmutable $expires_at
 * @property-read Airport $origin
 * @property-read Airport $destination
 */
#[Fillable([
    'origin_airport_id', 'destination_airport_id', 'code', 'lane', 'departure_date', 'price_cents',
    'cents_per_km', 'percentile', 'savings_cents', 'google_verdict', 'found_at',
    'discovered_at', 'expires_at',
])]
final class Discovery extends Model
{
    /**
     * Laravel would pluralise this to `discoverys`; the table name must agree with the
     * migration, the prune and three tests.
     */
    protected $table = 'discoveries';

    /**
     * @return BelongsTo<Airport, $this>
     */
    public function origin(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'origin_airport_id');
    }

    /**
     * @return BelongsTo<Airport, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'destination_airport_id');
    }

    /**
     * The current set: still live, cheapest per kilometre first (docs/BUSINESS-LOGIC.md §16).
     * A scope, not a controller query: the API's read and the prune's definition must agree.
     *
     * @param  Builder<Discovery>  $query
     * @return Builder<Discovery>
     */
    public function scopeLive(Builder $query, CarbonImmutable $now): Builder
    {
        return $query
            ->where('expires_at', '>', $now)
            // AND NOT a departure that has gone by: `expires_at` bounds find believability,
            // this bounds flight takeability (docs/BUSINESS-LOGIC.md §16).
            ->whereDate('departure_date', '>=', $now->toDateString())
            ->orderBy('cents_per_km')
            ->orderBy('code');
    }

    /**
     * Whether Google was asked and said yes. Read off the stored verdict, never recomputed:
     * a retuned rule must not rewrite past claims (docs/BUSINESS-LOGIC.md §16).
     */
    public function isVerified(): bool
    {
        return ($this->google_verdict['confirmed'] ?? false) === true;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // THE ENUM IS THE CAST: nothing downstream compares a lane to a string literal.
            // See App\Domain\Discovery\Lane (docs/BUSINESS-LOGIC.md §16).
            'lane'           => Lane::class,
            'departure_date' => 'immutable_date',
            'found_at'       => 'immutable_datetime',
            'discovered_at'  => 'immutable_datetime',
            'expires_at'     => 'immutable_datetime',
            'price_cents'    => 'integer',
            'savings_cents'  => 'integer',
            'cents_per_km'   => 'float',
            'percentile'     => 'float',
            'google_verdict' => 'array',
        ];
    }
}
