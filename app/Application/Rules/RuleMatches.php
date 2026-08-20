<?php

declare(strict_types=1);

namespace App\Application\Rules;

use App\Models\User;
use App\Models\Route;
use App\Models\Destination;
use App\Models\CalendarFare;
use App\Models\WatchlistItem;
use App\Domain\Pricing\DatedFare;
use App\Domain\Rules\RuleMatcher;
use App\Domain\Rules\RuleCriteria;
use Illuminate\Support\Facades\Date;
use App\Domain\Rules\DestinationProfile;

/**
 * What a rule matches right now.
 *
 * Four queries for any rule (destinations, routes, fares, watchlist),
 * fetched once — a per-route lookup would be 184 queries for "anywhere".
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * Destinations and watchlist are memoised per instance — the container
 * resolves this per request, so the cache is request-scoped, never stale.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * Does not create anything — an unpriced route just has fewer matches
 * until App\Jobs\SweepRuleFares runs; this is the read, not the fetch.
 * Why: docs/BUSINESS-LOGIC.md §36.
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
        // A rule that asks for nothing finds nothing (unlike empty vibes/origins
        // elsewhere, which mean "anywhere"/"all three") — there is no rule yet.
        // Why: docs/BUSINESS-LOGIC.md §36.
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
            // Every candidate is still pending — not the same as "nothing matches".
            // A route with no row is as unpriced as one with a row and no fares.
            // Why: docs/BUSINESS-LOGIC.md §36.
            return RuleMatchSummary::none(count($codes));
        }

        $fares = CalendarFare::query()
            ->whereIn('route_id', $routes->pluck('id')->all())
            ->where('departure_date', '>=', $today->toDateString())
            ->orderBy('departure_date')
            ->get(['route_id', 'departure_date', 'price_cents', 'found_at'])
            ->groupBy('route_id');

        $watched = $this->watchedRouteIds($user);

        $matches = [];

        foreach ($routes as $route) {
            $offered = array_map(
                static fn (CalendarFare $fare): DatedFare => new DatedFare(
                    $fare->departure_date->toDateTimeImmutable(),
                    $fare->price_cents,
                    // Carried so the alert pipeline can refuse a stale fare (its age
                    // matters as much as a watched route's — speculative sweep data).
                    // Why: docs/BUSINESS-LOGIC.md §36.
                    $fare->found_at?->toDateTimeImmutable(),
                ),
                ($fares->get($route->id) ?? collect())->all(),
            );

            $cheapest = $this->matcher->cheapest($criteria, array_values($offered), $todayAt);

            if ($cheapest !== null) {
                $matches[] = new RuleMatch($route, $cheapest, isset($watched[$route->id]));
            }
        }

        // Cheapest first, code as tiebreak (not id): two routes at €38 must sort
        // the same way on every request; insertion order isn't predictable.
        // Why: docs/BUSINESS-LOGIC.md §36.
        usort($matches, static fn (RuleMatch $a, RuleMatch $b): int => $a->cheapest->cents <=> $b->cheapest->cents
            ?: strcmp($a->route->code, $b->route->code));

        // How much of the question is unanswered: `$fares`' count is exactly what
        // Orbit has priced; a priced candidate matching nothing isn't pending.
        // Why: docs/BUSINESS-LOGIC.md §36.
        $pending = count($codes) - $fares->count();

        return RuleMatchSummary::of($matches, (int) config('orbit.rules.sample'), $pending);
    }

    /**
     * Every route code this rule is about, priced or not — also what
     * App\Jobs\SweepRuleFares spends its budget on (hence public, matcher order).
     * Why: docs/BUSINESS-LOGIC.md §36.
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
     * Memoised per user, not just per instance — a bare cache works today but
     * would silently cross-contaminate the moment two users share one request.
     * Why: docs/BUSINESS-LOGIC.md §36.
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
