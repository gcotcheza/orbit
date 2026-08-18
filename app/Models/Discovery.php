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
 * One route Orbit went and found on its own.
 *
 * THE ROW BEHIND App\Domain\Discovery\DealCandidate ONCE IT HAS SURVIVED
 * VERIFICATION. Everything a candidate carried that was a working number — the
 * raw sweep entry, the shortlist position — is gone; what is stored is what the
 * card says and the evidence for it.
 *
 * IT HAS NO `route_id` AND THAT IS DELIBERATE — see the migration. A discovery
 * is by definition a pair nobody watches and Orbit has usually never priced, so
 * it names its airports by key and its ROUTE by the `code` string the rest of
 * the app navigates on. Tapping one opens the ordinary lookup flow, which is
 * what creates the `routes` row, at the moment somebody actually shows interest.
 *
 * NO USER EITHER, unlike `alerts` and `watchlist_items`. A discovery is a fact
 * about the world and about the three home airports in config/orbit.php — not
 * about an account's relationship to one (docs/BUSINESS-LOGIC.md §1). There is
 * one account today and this table would not gain a column if there were two:
 * the sweep is the same sweep. What is per-user is whether you have WATCHED the
 * thing you were shown, and that already lives on `watchlist_items`.
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
     * Laravel would pluralise this to `discoverys`.
     *
     * A one-word override that is worth its line: the table is named in the
     * migration, in the prune and in three tests, and an inflector disagreeing
     * with all of them is a "relation does not exist" on the first deploy.
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
     * The current set: still live, cheapest per kilometre first.
     *
     * A SCOPE RATHER THAN A CONTROLLER QUERY, because the same two clauses are
     * the API's read AND the definition of "current" that the prune is written
     * against. Two spellings of "live" is how a row disappears from the screen
     * while the job still thinks it is showing.
     *
     * THE ORDER IS €/km AND NOT PRICE, which is the same decision the ranking
     * made: sorted by price this list is the nearest airports every day. The
     * card shows the price big and the reader sorts on their own instincts
     * from there.
     *
     * @param  Builder<Discovery>  $query
     * @return Builder<Discovery>
     */
    public function scopeLive(Builder $query, CarbonImmutable $now): Builder
    {
        return $query
            ->where('expires_at', '>', $now)
            /*
             * AND NOT A DEPARTURE THAT HAS GONE BY. `expires_at` bounds how long
             * a FIND is believable; this bounds whether the flight is still
             * takeable. A discovery made on Sunday for a Tuesday departure is
             * live and correct on Monday and is neither on Wednesday — and the
             * prune is daily, so without this clause the screen would offer it
             * for the rest of that day.
             */
            ->whereDate('departure_date', '>=', $now->toDateString())
            ->orderBy('cents_per_km')
            ->orderBy('code');
    }

    /**
     * Whether Google was asked and said yes.
     *
     * READ OFF THE STORED VERDICT AND NOT RECOMPUTED. `confirmed` is what this
     * row CLAIMED when it was written, which is what the badge on the card
     * means; re-deriving it from the other three fields would let a retuned
     * rule silently rewrite yesterday's claims. Absent verdict, absent claim —
     * there is no path here that answers true without a stored `confirmed`.
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
            /*
             * THE ENUM IS THE CAST, so nothing downstream compares a lane to a
             * string literal. `App\Domain\Discovery\Lane` is where the two cases
             * are defined and where the argument for there being two is written
             * down; a `=== 'relative'` in a resource would be a third place that
             * has to agree about the spelling.
             */
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
