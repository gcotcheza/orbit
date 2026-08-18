<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RouteFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A city pair, and the hub every price in this app hangs off.
 *
 * NAMED `Route` DESPITE Illuminate\Support\Facades\Route existing. The domain
 * word for what this is, is "route" — it is on every screen and in every URL —
 * and renaming the model to avoid a collision would put the framework's
 * vocabulary ahead of the product's in the one place the product's should win.
 * The two are never both needed in the same file: routes/web.php uses the
 * facade and nothing else, and a controller uses the model and nothing else.
 * Where a test needs both, it aliases at the import.
 *
 * @property int $id
 * @property string $code "AMS-LIS"
 * @property int $origin_airport_id
 * @property int $destination_airport_id
 * @property-read Airport $origin
 * @property-read Airport $destination
 * @property-read RouteStats|null $stats
 * @property-read Collection<int, PriceObservation> $observations
 * @property-read Collection<int, CalendarFare> $fares
 */
#[Fillable(['code', 'origin_airport_id', 'destination_airport_id'])]
final class Route extends Model
{
    /** @use HasFactory<RouteFactory> */
    use HasFactory;

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
     * @return HasOne<RouteStats, $this>
     */
    public function stats(): HasOne
    {
        return $this->hasOne(RouteStats::class);
    }

    /**
     * @return HasMany<PriceObservation, $this>
     */
    public function observations(): HasMany
    {
        return $this->hasMany(PriceObservation::class);
    }

    /**
     * @return HasMany<CalendarFare, $this>
     */
    public function fares(): HasMany
    {
        return $this->hasMany(CalendarFare::class);
    }

    /**
     * @return HasMany<WatchlistItem, $this>
     */
    public function watchlistItems(): HasMany
    {
        return $this->hasMany(WatchlistItem::class);
    }

    /**
     * The routes worth spending a provider call on.
     *
     * ONE DEFINITION, because two commands and a seeder all need it and
     * "watched" drifting between them would mean a route being polled but
     * never scored, or scored against statistics nothing refreshes.
     *
     * @return Builder<Route>
     */
    public static function onWatchlist(bool $activeOnly = true): Builder
    {
        return self::query()
            ->whereHas('watchlistItems', function (Builder $items) use ($activeOnly): void {
                /** @var Builder<WatchlistItem> $items */
                if ($activeOnly) {
                    $items->where('active', true);
                }
            })
            ->orderBy('code');
    }

    /**
     * The code both providers and the URL use, built from the two ends.
     */
    public static function codeFor(string $originIata, string $destinationIata): string
    {
        return mb_strtoupper($originIata).'-'.mb_strtoupper($destinationIata);
    }
}
