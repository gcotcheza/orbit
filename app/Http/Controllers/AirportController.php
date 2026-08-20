<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Airport;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use App\Http\Requests\SearchAirportsRequest;
use App\Http\Resources\DestinationOptionResource;

/**
 * Every airport Orbit will price, searched (the add-route form's typeahead). Unlike GET /api/destinations: includes
 * origins, shares its ranking, no accent folding (docs/BUSINESS-LOGIC.md §36).
 */
final class AirportController extends Controller
{
    /**
     * Merged with the client's curated list (MAX_SUGGESTIONS = 8).
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    private const LIMIT = 10;

    /**
     * `%` and `_` in what somebody typed are LITERAL, and this is what makes
     * them so. Without it a box containing "%" matches all 3,270 rows.
     */
    private const ESCAPE = "escape '\\'";

    public function __invoke(SearchAirportsRequest $request): JsonResponse
    {
        $term = $request->term();
        $code = mb_strtoupper($term);

        /* Folded once here rather than per predicate below. */
        $like = addcslashes(mb_strtolower($term), '%_\\');
        $prefix = $like.'%';
        $anywhere = '%'.$like.'%';

        $airports = Airport::query()
            ->where(function (Builder $match) use ($code, $anywhere): void {
                /** @var Builder<Airport> $match */
                $match->where('iata', $code)
                    ->orWhereRaw('lower(city) like ? '.self::ESCAPE, [$anywhere])
                    ->orWhereRaw('lower(name) like ? '.self::ESCAPE, [$anywhere])
                    ->orWhereRaw('lower(country) like ? '.self::ESCAPE, [$anywhere]);
            })
            ->orderByRaw(self::rank(), [$code, $prefix, $prefix, $prefix])
            // Deterministic tie-break: without it, which N rows come back among ties is the planner's choice, and the panel would reshuffle.
            // Why: docs/BUSINESS-LOGIC.md §36.
            ->orderBy('city')
            ->orderBy('iata')
            ->limit(self::LIMIT)
            ->get(['iata', 'city', 'country', 'country_code']);

        return DestinationOptionResource::collection($airports)
            ->additional(['meta' => ['count' => $airports->count(), 'query' => $term]])
            ->response()
            // Private cache: a shared cache holding an authenticated response is how one account's answer reaches another.
            // Why: docs/BUSINESS-LOGIC.md §36.
            ->header('Cache-Control', 'private, max-age=300');
    }

    /**
     * Best match first: the code, then the city, then the airport's own name, then the country, then anything that merely contains what was typed. Order
     * matches destinations.js's curated-list ranking — shown as one merged list (docs/BUSINESS-LOGIC.md §36).
     *
     * Every value in the CASE expression is a bound `?`; the literal-string return type proves no typed input reaches
     * orderByRaw() as text (docs/BUSINESS-LOGIC.md §36).
     *
     * @return literal-string
     */
    private static function rank(): string
    {
        return 'case'
            .' when iata = ? then 0'
            .' when lower(city) like ? '.self::ESCAPE.' then 1'
            .' when lower(name) like ? '.self::ESCAPE.' then 2'
            .' when lower(country) like ? '.self::ESCAPE.' then 3'
            .' else 4 end';
    }
}
