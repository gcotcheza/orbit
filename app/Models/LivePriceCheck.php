<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One live second opinion — a row of `live_price_checks`.
 *
 * WRITTEN ONLY BY App\Application\Routes\LivePriceChecks, which is the one place
 * a SerpAPI search is spent from a person's tap. Read by the route detail
 * resource, and by the cooldown that stops the same tap being paid for twice.
 *
 * `google_verdict` IS THE STORED FACTS AND NOT A CONCLUSION, the same bargain
 * `discoveries` makes: `confirmed` is derivable from the other three and is
 * written anyway, so that what a screen claimed on the day it claimed it cannot
 * be rewritten by a retuned rule. NULL means "asked, and Google would not say" —
 * a real answer, and never "assume the cached price is fine".
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

    /**
     * The cheapest seat Google itself could find, in cents — or null when it had
     * no opinion.
     *
     * THE FIELD THE HEADLINE IS SWAPPED FOR, and the reason a null here is not a
     * cosmetic detail: with no live price there is nothing to promote, so the
     * screen keeps the cached figure exactly as demoted as it already was and
     * says Google had nothing to add. An accessor rather than three `?? null`
     * reads spread over a resource and a test.
     */
    public function lowestCents(): ?int
    {
        $lowest = $this->google_verdict['lowest'] ?? null;

        return is_int($lowest) ? $lowest : null;
    }

    /**
     * Whether this answer is still inside the cooldown, i.e. whether re-asking
     * would be spending a search on a question already answered.
     *
     * THE HOURS ARE PASSED IN rather than read from config here, for the reason
     * every policy in this app is handed its numbers: a model that read
     * `config()` would be a second place the cooldown is defined, one refactor
     * away from disagreeing with the endpoint that enforces it.
     */
    public function isFresh(CarbonImmutable $now, int $cooldownHours): bool
    {
        return $this->checked_at->greaterThan($now->subHours($cooldownHours));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'departure_date' => 'immutable_date',
            'checked_at'     => 'immutable_datetime',
            'google_verdict' => 'array',
        ];
    }
}
