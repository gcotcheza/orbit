<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SearchAirportsRequest;
use App\Http\Resources\DestinationOptionResource;
use App\Models\Airport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Every airport Orbit will price, searched — the other half of the add-route
 * form's typeahead.
 *
 * WHY THIS IS A `?q=` SEARCH WHEN `GET /api/destinations` DELIBERATELY IS NOT.
 * That endpoint's note argues at length that a network hop between a letter
 * and its suggestion is latency bought for nothing when the list is a couple of
 * hundred rows of four short strings — 184 of them today. Every word of it
 * still holds — and this table is 3,270
 * rows, which is roughly 200 KB the form would download on its first open, on
 * a phone, to answer four keystrokes. The two designs are not in tension: the
 * client keeps the curated list in memory and paints from it instantly, and
 * asks HERE for everywhere else (resources/js/stores/airports.js).
 *
 * THE ORIGINS ARE IN THE ANSWER, unlike in `GET /api/destinations`, and the
 * difference is worth stating because it looks like an oversight. That
 * endpoint answers "where can I fly TO" and must never offer Amsterdam; this
 * one answers "which airport is that", which is the question the box is
 * really asking — and DUS-AMS is a route App\Http\Requests\RoutePairRequest
 * accepts, so an airport search that hid it would disagree with the API it
 * exists to help somebody use. The form drops the CURRENTLY SELECTED origin
 * from what it shows, which is the precise version of the rule: never suggest
 * a route from a place to itself.
 *
 * THE RANKING IS THE SAME ONE THE CLIENT USES on the curated list — the code
 * beats the place, a prefix beats a substring — because the two lists are
 * merged into one panel and a row that sorted by different rules on either
 * side of the join would read as a shuffle. See `rank()`.
 *
 * WHAT IT CANNOT DO IS ACCENTS. The browser folds "Málaga" to "malaga" before
 * it searches the curated list (resources/js/stores/destinations.js); Postgres
 * would need `unaccent`, which is an extension this database does not install
 * for one typeahead. Every accented city in the curated set is therefore
 * already answered instantly by the client, and the world half matches what
 * was typed. It is a real limit and it lands where it costs least.
 */
final class AirportController extends Controller
{
    /**
     * Ten rows. The panel shows eight of the curated list (MAX_SUGGESTIONS)
     * and this is merged into it, so ten is enough for the client to have
     * something left after the duplicates are dropped and nowhere near enough
     * to be a page.
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
            /*
             * TOTAL AND DETERMINISTIC, which a ranked query with a LIMIT on it
             * has to be: without the tie-breakers, which ten of the forty
             * airports with "san" in them come back is whatever order the
             * planner felt like, and the panel would reshuffle on a re-render.
             */
            ->orderBy('city')
            ->orderBy('iata')
            ->limit(self::LIMIT)
            ->get(['iata', 'city', 'country', 'country_code']);

        return DestinationOptionResource::collection($airports)
            ->additional(['meta' => ['count' => $airports->count(), 'query' => $term]])
            ->response()
            /*
             * PRIVATE, for the reason `GET /api/destinations` is: it is behind
             * a session, and a shared cache holding an authenticated response
             * is how one account's answer reaches another. Five minutes is
             * enough for a backspace to be free and short enough that a deploy
             * which imports a new snapshot is not arguing with a phone.
             */
            ->header('Cache-Control', 'private, max-age=300');
    }

    /**
     * Best match first: the code, then the city, then the airport's own name,
     * then the country, then anything that merely contains what was typed.
     *
     * "AMS" IS THE AIRPORT AND NOT A SUBSTRING OF AMSTERDAM'S NAME, and
     * "san" is San Diego before Santorini before Japan. Both fall out of the
     * order below, which is the same order resources/js/stores/destinations.js
     * ranks the curated list with — deliberately, since the two are shown as
     * one list.
     *
     * `literal-string` IS NOT DECORATION. `orderByRaw()` takes one, and saying
     * so here is what proves the SQL is built entirely out of constants in this
     * file: every value in the expression is a `?`, and there is no path by
     * which anything a person typed reaches it as text.
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
