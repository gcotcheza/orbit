<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\DiscoveryResource;
use App\Models\Discovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * The current set of discoveries — "the insanely cheap routes you never thought
 * to watch".
 *
 * A PURE READ OF A PRECOMPUTED TABLE, and that is the whole design. Everything
 * expensive about this feature — three origin sweeps, five window fetches, up
 * to five metered Google searches — happens at 05:20 in App\Jobs\DiscoverDeals.
 * This endpoint is on the search screen's load path, which a person taps
 * several times a day, and a version that swept on demand would put forty
 * provider requests behind a tab.
 *
 * NO PARAMETERS AT ALL, which makes it the simplest endpoint in this API. There
 * is nothing to page (the table's steady state is about ten rows, bounded by
 * `orbit.discovery.max_rows`), nothing to filter (a discovery the reader did
 * not want is one they scroll past) and nothing to sort (the order IS the
 * ranking — see App\Models\Discovery::scopeLive). A `?limit=` here would be a
 * knob on a list that is already as short as it will ever be.
 *
 * AN EMPTY ANSWER IS A REAL ANSWER AND THE COMMONEST ONE. A box with no sweep
 * provider configured, a week where nothing cleared the thresholds, or the
 * thirty-six hours after a failed run all produce `data: []`. Every threshold in
 * `orbit.discovery` is a floor rather than a quota, precisely so that this can
 * happen — and the client draws an honest empty state rather than the least
 * mediocre thing available.
 *
 * BEHIND `auth:sanctum` LIKE EVERY OTHER READ. The rows are not private in any
 * interesting sense — they are three public airports and a fare — but this API
 * has one authentication rule and a first exception to it is not worth a screen
 * nobody can see without signing in anyway.
 */
final class DiscoveryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /*
         * THE OWNER'S CLOCK, not UTC's — the same resolution App\Jobs\
         * DiscoverDeals uses to write these rows. The `live` scope compares a
         * departure DATE against today, and a reader at 00:30 Amsterdam time is
         * still on yesterday's date in UTC: the endpoint would hide a discovery
         * for today's departure that the job considers perfectly current.
         */
        $now = Date::now((string) config('orbit.timezone'))->toImmutable();

        $discoveries = Discovery::query()
            /*
             * EAGER, BECAUSE EVERY CARD PRINTS BOTH ENDS. The resource reads
             * `destination->city` and `destination->country` for the headline
             * and the origin's code for the "from AMS" line, so a lazy load
             * would be two queries per row on a list whose whole job is to
             * render in one paint.
             */
            ->with(['origin', 'destination'])
            ->live($now)
            ->get();

        return DiscoveryResource::collection($discoveries)
            ->additional(['meta' => [
                'count' => $discoveries->count(),

                /*
                 * WHEN THIS SET WAS FOUND, so the strip can say "found this
                 * morning" rather than implying the fares were checked when the
                 * screen opened. Null on an empty set, which is the honest
                 * answer to "when did you last find something" when the answer
                 * is "we did not".
                 *
                 * A TIMESTAMP IN THE OWNER'S TIMEZONE, like the only other one
                 * in this API (`meta.fares.fetchedAt` on the route detail) —
                 * every other date here is a bare `YYYY-MM-DD` because it names
                 * a day, and this one names a moment.
                 */
                'discoveredAt' => $discoveries
                    ->max('discovered_at')
                    ?->setTimezone((string) config('orbit.timezone'))
                    ->toIso8601String(),
            ]])
            ->response();
    }
}
