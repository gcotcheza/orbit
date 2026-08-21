<?php

declare(strict_types=1);

namespace App\Jobs;

use DateTimeImmutable;
use App\Models\Airport;
use App\Models\Discovery;
use Carbon\CarbonInterface;
use Psr\Log\LoggerInterface;
use App\Domain\Geo\Haversine;
use App\Domain\Discovery\Lane;
use App\Domain\Pricing\DatedFare;
use App\Models\DiscoveryBaseline;
use App\Domain\Discovery\PickReason;
use Illuminate\Support\Facades\Date;
use App\Domain\Discovery\RelativePick;
use App\Domain\Discovery\DealCandidate;
use App\Domain\Discovery\GoogleVerdict;
use App\Domain\Discovery\RouteBaseline;
use App\Application\Ports\PriceProvider;
use App\Domain\Discovery\CandidateScorer;
use App\Domain\Discovery\DiscoveryPolicy;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Domain\Discovery\RelativeLanePolicy;
use App\Application\Ports\OriginSweepProvider;
use App\Domain\Discovery\RelativeLaneSelector;
use App\Infrastructure\Verify\GoogleFlightsCheck;

/**
 * "Show me the insanely cheap routes I am NOT watching." Two-stage funnel; writes rows
 * only, never alerts; one job, not a fan-out (docs/BUSINESS-LOGIC.md §16).
 */
final class DiscoverDeals implements ShouldQueue
{
    use Queueable;

    /**
     * Long on purpose: ~40 sequential round trips at 05:20, nothing waiting.
     * Why: docs/BUSINESS-LOGIC.md §16.
     */
    public int $timeout = 900;

    /**
     * ONE ATTEMPT: a retry would re-spend metered fetches and SerpAPI quota; a failed
     * run just leaves the previous set up (docs/BUSINESS-LOGIC.md §16).
     */
    public int $tries = 1;

    public function handle(
        OriginSweepProvider $sweep,
        PriceProvider $prices,
        GoogleFlightsCheck $google,
        DiscoveryPolicy $policy,
        RelativeLanePolicy $relativePolicy,
        LoggerInterface $logger,
    ): void {
        // Owner's local clock, not UTC — "gone by" must mean gone by in
        // Amsterdam.
        $timezone = (string) config('orbit.timezone');
        $now = Date::now($timezone);

        $scorer = new CandidateScorer($policy);

        $candidates = $this->sweepAll($sweep, $logger);

        if ($candidates === []) {
            // Empty sweep must not empty the screen; existing set stays
            // until it expires on its own schedule.
            $logger->info('Discovery swept nothing — leaving the existing set alone.');

            return;
        }

        $at = $now->toDateTimeImmutable();

        $shortlist = $scorer->shortlist($scorer->admit($candidates, $at));

        // Order matters: lane B needs lane A's destinations already
        // picked, so one city can't occupy both slots.
        $relative = $this->relativeLane($candidates, $shortlist, $policy, $relativePolicy, $at);

        $logger->info('Discovery swept and scored.', [
            'candidates'     => count($candidates),
            'shortlisted'    => count($shortlist),
            'relative_picks' => count($relative),
            // Splits relative slots from a claim vs a question — most exploration picks
            // failing verification is expected (docs/BUSINESS-LOGIC.md §16).
            'relative_from_baseline' => count(array_filter(
                $relative,
                static fn (RelativePick $pick): bool => $pick->reason === PickReason::Baseline,
            )),
        ]);

        // Google budget asked once, up front; zero on most boxes (no SERPAPI_KEY) is
        // the normal case, not an error (docs/BUSINESS-LOGIC.md §16).
        $budget = $google->available();
        $spent = 0;

        $verified = [];
        $learned = 0;

        // Absolute lane is queued first: a quota shortfall must degrade the newer
        // feature, not the shipped one (docs/BUSINESS-LOGIC.md §16).
        $queue = [];

        foreach ($shortlist as $candidate) {
            $queue[] = [$candidate, Lane::Absolute];
        }

        foreach ($relative as $pick) {
            $queue[] = [$pick->candidate, Lane::Relative];
        }

        foreach ($queue as [$candidate, $lane]) {
            $window = $this->windowFor($prices, $candidate, $now);

            /*
             * ⚠ Baseline is written before the verification gate below — a candidate that
             * fails still taught us its route's usual price (docs/BUSINESS-LOGIC.md §16).
             */
            if ($window !== []) {
                $this->rememberBaseline($candidate, $window, $now);
                $learned++;
            }

            $percentile = null;
            $savings = null;

            if ($window !== []) {
                $percentile = CandidateScorer::percentile($candidate->cents, $window);
                $median = CandidateScorer::median($window);
                $savings = $median === null ? null : max(0, $median - $candidate->cents);

                // Cross-sectional gate: cheap €/km alone isn't a cheap fare, so both lanes
                // face the same isRemarkable() check (docs/BUSINESS-LOGIC.md §16).
                if (! $policy->isRemarkable($percentile, $savings ?? 0)) {
                    continue;
                }
            } elseif ($lane === Lane::Relative) {
                /*
                 * ⚠ Empty window disqualifies a relative candidate but not an absolute one
                 * — the two cards make different claims (docs/BUSINESS-LOGIC.md §16).
                 */
                continue;
            }

            // Empty window is not a rejection for an absolute candidate — patchy
            // calendar coverage is ordinary, not an outage (docs/BUSINESS-LOGIC.md §16).
            $verdict = null;

            if ($spent < $budget) {
                $verdict = $google->check(
                    $candidate->originIata,
                    $candidate->destinationIata,
                    $candidate->departureDate,
                );

                // Counted whether or not it answered — a bad night must
                // not spare the month's quota.
                $spent++;
            }

            $verified[] = [$candidate, $lane, $percentile, $savings, $verdict];
        }

        $logger->info('Discovery verified its shortlist.', [
            'kept'          => count($verified),
            'kept_relative' => count(array_filter(
                $verified,
                static fn (array $row): bool => $row[1] === Lane::Relative,
            )),
            // Only number that moves on a night that surfaces nothing —
            // it's what makes tomorrow's run better than today's.
            'baselines_learned' => $learned,
            'google_budget'     => $budget,
            'google_spent'      => $spent,
        ]);

        $this->store($verified, $now, $policy);
        $this->prune($now, $policy);
    }

    /**
     * Every home origin swept and paired with a distance. One airport query for the whole
     * run, not one per code — ~1,177 codes a night (docs/BUSINESS-LOGIC.md §16).
     *
     * @return list<DealCandidate>
     */
    private function sweepAll(OriginSweepProvider $sweep, LoggerInterface $logger): array
    {
        /** @var list<string> $origins */
        $origins = config('orbit.origins');

        /** @var array<string, Airport> $airports */
        $airports = Airport::query()
            ->get(['id', 'iata', 'lat', 'lng'])
            ->keyBy('iata')
            ->all();

        $candidates = [];
        $unknown = 0;

        foreach ($origins as $originIata) {
            $origin = $airports[$originIata] ?? null;

            if ($origin === null) {
                /*
                 * Home airport with no row is a seeding bug, not a sweep to attempt —
                 * tests/Feature/SeedersTest asserts they agree (docs/BUSINESS-LOGIC.md §16).
                 */
                $logger->warning('Discovery skipped an origin with no airport row.', ['origin' => $originIata]);

                continue;
            }

            foreach ($sweep->cheapestFromOrigin($originIata) as $fare) {
                $destination = $airports[$fare->destinationIata] ?? null;

                /*
                 * City-level codes (LON, MOW, MIL, ...) are dropped: no coordinates for an
                 * honest €/km and no route code to open (docs/BUSINESS-LOGIC.md §16).
                 */
                if ($destination === null) {
                    $unknown++;

                    continue;
                }

                $candidates[] = new DealCandidate(
                    originIata: $originIata,
                    destinationIata: $fare->destinationIata,
                    departureDate: $fare->departureDate,
                    cents: $fare->cents,
                    kilometres: Haversine::kilometres(
                        $origin->lat,
                        $origin->lng,
                        $destination->lat,
                        $destination->lng,
                    ),
                    foundAt: $fare->foundAt,
                );
            }
        }

        if ($unknown > 0) {
            $logger->debug('Discovery dropped swept destinations with no airport row.', ['dropped' => $unknown]);
        }

        return $candidates;
    }

    /**
     * The relative lane's picks — cheap ON THEIR OWN ROUTE rather than per kilometre.
     * Pool is the sweep minus the absolute lane's five destinations (docs/BUSINESS-LOGIC.md §16).
     *
     * @param  list<DealCandidate>  $candidates  everything swept
     * @param  list<DealCandidate>  $shortlist  the absolute lane's finalists
     * @return list<RelativePick>
     */
    private function relativeLane(
        array $candidates,
        array $shortlist,
        DiscoveryPolicy $policy,
        RelativeLanePolicy $relativePolicy,
        DateTimeImmutable $now,
    ): array {
        /*
         * Distance/freshness floors reuse the absolute lane's policy; only the price
         * ceiling is this lane's own, and maxCentsPerKilometre is deliberately absent.
         */
        $pool = array_values(array_filter(
            $candidates,
            static fn (DealCandidate $candidate): bool => $candidate->kilometres >= $policy->minKilometres
                && $candidate->cents <= $relativePolicy->maxPriceCents
                && $policy->isFresh($candidate, $now),
        ));

        if ($pool === []) {
            return [];
        }

        /*
         * One query for every baseline this run could read, keyed by code — the same
         * ask-once pattern as sweepAll's airport lookup.
         */
        $codes = array_values(array_unique(array_map(
            static fn (DealCandidate $candidate): string => $candidate->routeCode(),
            $pool,
        )));

        /** @var array<string, RouteBaseline> $baselines */
        $baselines = DiscoveryBaseline::query()
            ->whereIn('code', $codes)
            ->get()
            ->mapWithKeys(static fn (DiscoveryBaseline $row): array => [$row->code => $row->toDomain()])
            ->all();

        return (new RelativeLaneSelector($relativePolicy))->select(
            $pool,
            $baselines,
            array_map(
                static fn (DealCandidate $candidate): string => $candidate->destinationIata,
                $shortlist,
            ),
            $now,
        );
    }

    /**
     * Write down what this route usually costs — the only state that outlives a run.
     * See the discovery_baselines migration and RelativeLanePolicy::$minBaselineDays.
     *
     * @param  list<int>  $window
     */
    private function rememberBaseline(DealCandidate $candidate, array $window, CarbonInterface $now): void
    {
        $median = CandidateScorer::median($window);

        if ($median === null) {
            return;
        }

        /*
         * ⚠ Converted to UTC before writing: upsert() bypasses the casts, so an owner-zone
         * Carbon is stored two hours fast (docs/BUSINESS-LOGIC.md §16).
         */
        $measuredAt = $now->copy()->utc();

        DiscoveryBaseline::query()->upsert(
            [[
                'code'         => $candidate->routeCode(),
                'median_cents' => $median,
                'sample_days'  => count($window),
                'measured_at'  => $measuredAt,
                'created_at'   => $measuredAt,
                'updated_at'   => $measuredAt,
            ]],
            ['code'],
            ['median_cents', 'sample_days', 'measured_at', 'updated_at'],
        );
    }

    /**
     * Every fare in a finalist's own near window, in cents — the same PriceProvider port
     * any watched route uses. Near window, not the horizon (docs/BUSINESS-LOGIC.md §16).
     *
     * @return list<int>
     */
    private function windowFor(PriceProvider $prices, DealCandidate $candidate, CarbonInterface $now): array
    {
        $days = (int) config('orbit.discovery.verify_window_days');

        $from = $now->copy()->startOfDay();
        $to = $from->copy()->addDays($days);

        $fares = $prices->cheapestPerDay(
            $candidate->originIata,
            $candidate->destinationIata,
            $from->toDateTimeImmutable(),
            $to->toDateTimeImmutable(),
        );

        return array_map(static fn (DatedFare $fare): int => $fare->cents, $fares);
    }

    /**
     * Write the survivors down. One upsert keyed on (code, departure_date); discovered_at
     * and expires_at refresh every run (docs/BUSINESS-LOGIC.md §16).
     *
     * @param  list<array{0: DealCandidate, 1: Lane, 2: float|null, 3: int|null, 4: GoogleVerdict|null}>  $verified
     */
    private function store(array $verified, CarbonInterface $now, DiscoveryPolicy $policy): void
    {
        if ($verified === []) {
            return;
        }

        /** @var array<string, int> $ids */
        $ids = Airport::query()->pluck('id', 'iata')->all();

        /*
         * ⚠ Converted to UTC before writing, the same upsert()-bypasses-casts trap as
         * rememberBaseline (docs/BUSINESS-LOGIC.md §16).
         */
        $discoveredAt = $now->copy()->utc();
        $expiresAt = $now->copy()->addHours($policy->expiresAfterHours)->utc();

        $rows = [];

        foreach ($verified as [$candidate, $lane, $percentile, $savings, $verdict]) {
            $rows[] = [
                'origin_airport_id'      => $ids[$candidate->originIata],
                'destination_airport_id' => $ids[$candidate->destinationIata],
                'code'                   => $candidate->routeCode(),
                // `->value`, not the enum: upsert() bypasses casts (see
                // the UTC note above).
                'lane'           => $lane->value,
                'departure_date' => $candidate->departureDate->format('Y-m-d'),
                'price_cents'    => $candidate->cents,
                'cents_per_km'   => $candidate->centsPerKilometre(),
                'percentile'     => $percentile,
                'savings_cents'  => $savings,
                // json_encode()'d here, not left to the array cast —
                // upsert() bypasses casts; a raw array fails on Postgres.
                'google_verdict' => $verdict === null ? null : json_encode($verdict->toArray()),
                'found_at'       => $candidate->foundAt,
                'discovered_at'  => $discoveredAt,
                'expires_at'     => $expiresAt,
                'created_at'     => $discoveredAt,
                'updated_at'     => $discoveredAt,
            ];
        }

        Discovery::query()->upsert(
            $rows,
            ['code', 'departure_date'],
            [
                /*
                 * `lane` is in the update list: a route can flip between runs and the badge
                 * must track why it's shown (docs/BUSINESS-LOGIC.md §16).
                 */
                'lane',
                'price_cents', 'cents_per_km', 'percentile', 'savings_cents',
                /*
                 * `google_verdict` is updatable both ways: a run without quota must be able
                 * to take a verdict away, not just add one (docs/BUSINESS-LOGIC.md §16).
                 */
                'google_verdict',
                'found_at', 'discovered_at', 'expires_at', 'updated_at',
            ],
        );
    }

    /**
     * Four deletes below run only after a successful sweep — an outage
     * must never clear the screen (see the empty-sweep return in handle()).
     */
    private function prune(CarbonInterface $now, DiscoveryPolicy $policy): void
    {
        /* 1. Rows that have said their piece. */
        Discovery::query()->where('expires_at', '<=', $now)->delete();

        /* 2. Departures that have gone by — see the `live` scope for the split. */
        Discovery::query()->whereDate('departure_date', '<', $now->toDateString())->delete();

        /*
         * 3. Superseded rows: same route found on an earlier run. Unique key is
         * (code, departure_date), so a new date does not collide (docs/BUSINESS-LOGIC.md §16).
         */
        $thisRun = $now->copy()->utc();

        Discovery::query()
            ->whereIn('code', Discovery::query()->select('code')->where('discovered_at', $thisRun))
            ->where('discovered_at', '<', $thisRun)
            ->delete();

        /*
         * 4. Ceiling: ordered exactly as the screen orders them, so what survives is
         * what would have been shown — a size bound, not a second opinion.
         */
        $keep = Discovery::query()
            ->orderBy('cents_per_km')
            ->orderBy('code')
            ->limit($policy->maxRows)
            ->pluck('id');

        Discovery::query()->whereNotIn('id', $keep)->delete();
    }
}
