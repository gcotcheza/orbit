<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Route;
use App\Models\WatchlistItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Validation\Validator;

/**
 * `POST /api/watchlist` — the pair itself is RoutePairRequest; what is added here is the one
 * rule about the WATCHLIST, which a lookup must not have (docs/BUSINESS-LOGIC.md §36).
 */
final class AddWatchedRouteRequest extends RoutePairRequest
{
    /**
     * The pair is not already on this account's watchlist. An `after` callback rather than a
     * `unique` rule: what must be unique is the (user, route) pair, not a column.
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
