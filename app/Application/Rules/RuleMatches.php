<?php

declare(strict_types=1);

namespace App\Application\Rules;

use App\Domain\Pricing\DatedFare;
use App\Domain\Rules\DestinationProfile;
use App\Domain\Rules\RuleCriteria;
use App\Domain\Rules\RuleMatcher;
use App\Models\CalendarFare;
use App\Models\Destination;
use App\Models\Route;
use App\Models\User;
use App\Models\WatchlistItem;
use Illuminate\Support\Facades\Date;

/**
 * What a rule matches right now.
 *
 * FOUR QUERIES FOR ANY RULE, and the same reasoning as
 * App\Application\Routes\RouteSnapshots: the destinations, the routes, their
 * fares, and this account's watchlist, each fetched once for the whole answer.
 * A per-route lookup would be seventy-seven of them for a rule that says
 * "anywhere cheap".
 *
 * TWO OF THE FOUR ARE MEMOISED FOR THE LIFE OF THE INSTANCE — the destination
 * vocabulary and the watchlist — because `GET /api/rules` asks this class once
 * per rule and neither answer can change between two rules in one response.
 * The container resolves this per request, so the cache is a request and not a
 * process: nothing here has to be invalidated, because nothing here outlives
 * the answer it was gathered for.
 *
 * IT DOES NOT CREATE ANYTHING. A rule naming a route Orbit has never priced
 * simply has fewer matches until App\Jobs\SweepRuleFares has been round —
 * this is the read, and making it fetch fares would put a provider call behind
 * a keystroke on the create screen.
 */
final class RuleMatches
{
    /** @var list<DestinationProfile>|null */
    private ?array $destinations = null;

    /** @var array<int, array<int, true>> user id => route ids they watch */
    private array $watched = [];

    public function __construct(private readonly RuleMatcher $matcher) {}

    public function for(RuleCriteria $criteria, User $user): RuleMatchSummary
    {
        /*
         * A RULE THAT ASKS FOR NOTHING FINDS NOTHING, which is the opposite of
         * what the fields mean everywhere else in this class — an empty
         * `vibes` is anywhere, an empty `origins` is all three. It is not an
         * exception to that rule so much as an admission that there is no rule
         * yet: this is what an empty textarea produces, and answering "6 trips
         * match this right now" under a box nobody has typed in is a claim
         * about a sentence that does not exist. App\Http\Controllers\
         * RuleController refuses to store one for the same reason, so no saved
         * rule can ever reach this branch.
         */
        if ($criteria->isEmpty()) {
            return RuleMatchSummary::none();
        }

        $codes = $this->candidateCodes($criteria);

        if ($codes === []) {
            return RuleMatchSummary::none();
        }

        $today = Date::now((string) config('orbit.timezone'))->startOfDay();
        $todayAt = $today->toDateTimeImmutable();

        $routes = Route::query()
            ->whereIn('code', $codes)
            ->with(['origin', 'destination'])
            ->get();

        if ($routes->isEmpty()) {
            /*
             * EVERY CANDIDATE IS STILL PENDING, which is not the same answer as
             * "nothing matches" and is the ordinary state of a rule about
             * places Orbit has never been asked to price. A route with no row
             * at all is as unpriced as one with a row and no fares.
             */
            return RuleMatchSummary::none(count($codes));
        }

        $fares = CalendarFare::query()
            ->whereIn('route_id', $routes->pluck('id')->all())
            ->where('departure_date', '>=', $today->toDateString())
            ->orderBy('departure_date')
            ->get(['route_id', 'departure_date', 'price_cents'])
            ->groupBy('route_id');

        $watched = $this->watchedRouteIds($user);

        $matches = [];

        foreach ($routes as $route) {
            $offered = array_map(
                static fn (CalendarFare $fare): DatedFare => new DatedFare(
                    $fare->departure_date->toDateTimeImmutable(),
                    $fare->price_cents,
                ),
                ($fares->get($route->id) ?? collect())->all(),
            );

            $cheapest = $this->matcher->cheapest($criteria, array_values($offered), $todayAt);

            if ($cheapest !== null) {
                $matches[] = new RuleMatch($route, $cheapest, isset($watched[$route->id]));
            }
        }

        /*
         * Cheapest first, and the CODE as the tiebreak rather than the id: two
         * routes at €38 should come back in the same order on every request,
         * and an insertion order is not an order anybody can predict.
         */
        usort($matches, static fn (RuleMatch $a, RuleMatch $b): int => $a->cheapest->cents <=> $b->cheapest->cents
            ?: strcmp($a->route->code, $b->route->code));

        /*
         * HOW MUCH OF THE QUESTION IS STILL UNANSWERED.
         *
         * `$fares` is keyed by route id and only holds the routes that have at
         * least one departure in the window, so its count is exactly the
         * candidates Orbit has a price for; everything else in `$codes` is a
         * route it has never priced, or has no route row for at all. A
         * candidate that IS priced and matches nothing is not pending — the
         * answer for it is in, and the answer is no.
         */
        $pending = count($codes) - $fares->count();

        return RuleMatchSummary::of($matches, (int) config('orbit.rules.sample'), $pending);
    }

    /**
     * Every route code this rule is about, whether or not Orbit has ever
     * priced it. Also what App\Jobs\SweepRuleFares spends its budget on, which
     * is why it is public and why the order is the matcher's ranking.
     *
     * @return list<string>
     */
    public function candidateCodes(RuleCriteria $criteria): array
    {
        $origins = $criteria->originsOrAll($this->origins());
        $codes = [];

        foreach ($this->matcher->rank($criteria, $this->destinations()) as $place) {
            foreach ($origins as $origin) {
                /* A route from a place to itself is not a trip. */
                if ($origin !== $place->iata) {
                    $codes[] = Route::codeFor($origin, $place->iata);
                }
            }
        }

        return $codes;
    }

    /**
     * @return list<DestinationProfile>
     */
    private function destinations(): array
    {
        return $this->destinations ??= array_values(Destination::query()
            ->with('airport:id,iata')
            ->get()
            ->map(static fn (Destination $destination): DestinationProfile => new DestinationProfile(
                $destination->airport->iata,
                $destination->vibes,
                $destination->warmth,
            ))
            ->all());
    }

    /**
     * The route ids this account is already watching.
     *
     * MEMOISED PER USER AND NOT JUST PER INSTANCE. One account is all this app
     * has and one request is all this object lives for, so a bare cache would
     * work today and be wrong the first time anything asks about two people —
     * silently, by telling the second one they are watching the first one's
     * routes. Keying it costs an array lookup.
     *
     * @return array<int, true>
     */
    private function watchedRouteIds(User $user): array
    {
        $id = (int) $user->getAuthIdentifier();

        if (isset($this->watched[$id])) {
            return $this->watched[$id];
        }

        /** @var list<int> $ids */
        $ids = WatchlistItem::query()
            ->where('user_id', $id)
            ->pluck('route_id')
            ->all();

        return $this->watched[$id] = array_fill_keys($ids, true);
    }

    /**
     * @return list<string>
     */
    private function origins(): array
    {
        /** @var list<string> $origins */
        $origins = config('orbit.origins');

        return $origins;
    }
}
