<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One live second opinion — a row of `live_price_checks`, written only by
 * App\Application\Routes\LivePriceChecks.
 *
 * A null `google_verdict` means Google was asked and would not say; a search
 * that was never spent gets no row at all (docs/BUSINESS-LOGIC.md §17).
 *
 * @property int $id
 * @property int $route_id
 * @property CarbonImmutable $departure_date
 * @property CarbonImmutable $checked_at
 * @property array{level: string|null, lowest: int|null, typical_low: int|null, typical_high: int|null, confirmed: bool}|null $google_verdict
 * @property-read Route $route
 */
#[Fillable(['route_id', 'departure_date', 'checked_at', 'google_verdict'])]
final class LivePriceCheck extends Model
{
    /**
     * @return BelongsTo<Route, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /** The cheapest seat Google could find, in cents; null when it was silent. */
    public function lowestCents(): ?int
    {
        $lowest = $this->google_verdict['lowest'] ?? null;

        return is_int($lowest) ? $lowest : null;
    }

    public function isFresh(CarbonImmutable $now, int $cooldownHours): bool
    {
        return $this->checked_at->greaterThan($now->subHours($cooldownHours));
    }

    /**
     * ⚠ A BARE `Y-m-d`, AND NOT A DATE CAST. The cast stores `Y-m-d H:i:s`,
     * which SQLite keeps verbatim and an exact-match lookup never finds.
     *
     * @return Attribute<CarbonImmutable, string>
     */
    protected function departureDate(): Attribute
    {
        return Attribute::make(
            get: static fn (string $value): CarbonImmutable => CarbonImmutable::parse($value),
            set: static fn (DateTimeInterface|string $value): string => $value instanceof DateTimeInterface
                ? $value->format('Y-m-d')
                : $value,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_at'     => 'immutable_datetime',
            'google_verdict' => 'array',
        ];
    }
}
