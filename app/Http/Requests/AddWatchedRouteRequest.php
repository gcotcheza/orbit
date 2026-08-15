<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Route;
use App\Models\WatchlistItem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Builder;

/**
 * `POST /api/watchlist` — start watching a city pair.
 *
 * THE PAIR ITSELF IS RoutePairRequest, which this shares with the lookup
 * endpoint: the two codes, what they may be, and the five sentences that come
 * back when they are not. What is added here is the one rule that is about the
 * WATCHLIST rather than about the route — a pair already on it — which is
 * exactly the rule a lookup must not have.
 */
final class AddWatchedRouteRequest extends RoutePairRequest
{
    /**
     * The pair is not already on this account's watchlist.
     *
     * AN `after` CALLBACK RATHER THAN A `unique` RULE, because what has to be
     * unique is not a column: it is the (user, route) pair, reached through a
     * route that is looked up by the code the two fields spell. It runs only
     * once the fields themselves are valid — telling somebody they are already
     * watching `AM-LIS` would be answering a question they did not ask.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $code = Route::codeFor($this->iata('origin'), $this->iata('destination'));

                $watched = WatchlistItem::query()
                    ->where('user_id', $this->user()?->getAuthIdentifier())
                    ->whereHas('route', function (Builder $route) use ($code): void {
                        /** @var Builder<Route> $route */
                        $route->where('code', $code);
                    })
                    ->exists();

                if ($watched) {
                    $validator->errors()->add('destination', "You are already watching {$code}.");
                }
            },
        ];
    }
}
