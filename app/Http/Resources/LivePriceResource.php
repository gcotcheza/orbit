<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Models\LivePriceCheck;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What Google said when somebody pressed "Check live price" — `meta.liveCheck`
 * on the route detail (docs/API.md).
 *
 * IT IS THE ONE NUMBER ON THAT SCREEN THAT DID NOT COME FROM TRAVELPAYOUTS,
 * which is the whole of its value: every other figure there descends from a
 * cache of other people's searches, so a cache that is stale is a screen that
 * is confidently stale all the way down. This is the second opinion, it cost a
 * metered search out of 250 a month, and it is labelled as live because it was
 * fetched live — see App\Application\Routes\LivePriceChecks for what stops that
 * label being applied to anything else.
 *
 * =============================================================================
 * `lowest` MAY BE NULL, AND THE CLIENT MUST NOT READ THAT AS REASSURANCE
 * =============================================================================
 * Google publishes `price_insights` only where it has enough history, and thin
 * routes routinely come back without it (App\Infrastructure\Verify\
 * GoogleFlightsCheck). "No opinion" is a real answer and it confirms nothing:
 * the screen keeps the cached fare exactly as demoted as it already was and
 * says Google had nothing to add. A resource that filled this in from the
 * cached price would be manufacturing the very claim the check exists to test.
 *
 * NO `verified` FLAG AND NO BADGE COPY. App\Http\Resources\DiscoveryResource
 * publishes both because a discovery card makes a CLAIM ("verified low by
 * Google") that the server owns the wording of. This makes no claim at all — it
 * hands over Google's own figures and the moment they were fetched, and the
 * screen prints them next to Orbit's. "Orbit says €36, Google says €150" is a
 * disagreement the reader is entitled to resolve for themselves.
 */
final class LivePriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LivePriceCheck $check */
        $check = $this->resource;

        $verdict = $check->google_verdict;

        return [
            /*
             * THE DAY THE CHECK IS ABOUT — a bare `YYYY-MM-DD`, like every other
             * DAY in this API, and published rather than assumed. The client
             * asked about no date at all (the server checks the cheapest
             * departure it is showing, see RouteController), so this is how the
             * screen knows the answer is about the flight it has on it.
             */
            'date' => $check->departure_date->toDateString(),

            /*
             * THE CHEAPEST SEAT GOOGLE ITSELF COULD FIND. Null when Google had
             * no opinion — see the docblock, and never a fallback.
             */
            'lowest' => $check->lowestCents() === null ? null : Euros::from($check->lowestCents()),

            /*
             * WHAT THIS ROUTE NORMALLY GOES FOR, ACCORDING TO GOOGLE rather than
             * to Orbit's own statistics. Two independent "usual" figures on one
             * screen is the point rather than a duplication: Orbit's is computed
             * from what it has watched, Google's from a market Orbit cannot see,
             * and a reader deciding whether €36 was ever real is better served
             * by both than by either.
             */
            'typicalLow'  => is_int($verdict['typical_low'] ?? null) ? Euros::from($verdict['typical_low']) : null,
            'typicalHigh' => is_int($verdict['typical_high'] ?? null) ? Euros::from($verdict['typical_high']) : null,

            /* `low`, `typical` or `high` — Google's own word, or null. */
            'level' => is_string($verdict['level'] ?? null) ? $verdict['level'] : null,

            /*
             * WHEN ORBIT ASKED. A timestamp with an offset, in the owner's
             * timezone, exactly like `meta.fares.fetchedAt` — this one names a
             * MOMENT and the screen reads it aloud as "checked just now". It is
             * also what the cooldown is measured from
             * (`orbit.live_check.cooldown_hours`), so a client can tell how much
             * longer this answer will be served for.
             */
            'checkedAt' => $check->checked_at->setTimezone((string) config('orbit.timezone'))->toIso8601String(),
        ];
    }
}
