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
 * "Show me the insanely cheap routes I am NOT watching."
 *
 * =============================================================================
 * A TWO-STAGE FUNNEL, AND THE SECOND STAGE IS THE WHOLE POINT
 * =============================================================================
 * Sweeping is easy and cheap: three requests bring back roughly 1,177 fares
 * (562 from AMS, 419 from DUS, 196 from EIN on 2026-08-16). Ranking them is
 * free. Everything a naive version of this feature needs is therefore available
 * for three requests — and a naive version would be WRONG, in the specific way
 * this app has already been wrong twice.
 *
 * A swept fare is a price somebody else's search turned up, up to a week ago,
 * out of a cache Orbit does not control. Orbit has shipped €36 for a date whose
 * live cheapest was €56, and DUS-AGP at €29 against a Skyscanner cheapest of
 * €68. Putting the top five of a thousand such rows on a screen under the words
 * "insanely cheap" would be that mistake automated and given a nightly
 * schedule.
 *
 * So nothing reaches the screen on the sweep's word alone:
 *
 *   STAGE 1  sweep + score      3 requests, ~1,177 rows → a handful.
 *            Arithmetic only (App\Domain\Discovery\CandidateScorer). Distance,
 *            price, €/km and freshness, in that order, because that order is
 *            the cheapest way to get to a shortlist.
 *
 *   STAGE 2  verify             ~35 requests + ≤5 searches, 5 candidates.
 *            (a) EACH FINALIST'S OWN NEAR WINDOW, through the existing
 *                PriceProvider port — is this fare remarkable ON ITS OWN ROUTE,
 *                or is this just a cheap route? DUS-AGP's €29 was cheaper than
 *                all 23 fares in its October window, against a €78 median.
 *            (b) GOOGLE, if there is quota — does a company that is not
 *                Travelpayouts agree this is cheap today? See
 *                App\Domain\Discovery\GoogleVerdict for what "agree" had to be
 *                redefined as, and the three measurements that forced it.
 *
 * WHAT SURVIVES BOTH IS A DISCOVERY. What survives (a) but is never put to
 * Google is a "great find" — shown, honestly, without a verified badge. What
 * fails (a) is not shown at all.
 *
 * =============================================================================
 * IT DOES NOT ALERT, AND v1 NEVER WILL
 * =============================================================================
 * docs/BUSINESS-LOGIC.md §16. This job writes rows to a table an API endpoint
 * reads; it sends no mail, queues no notification and touches nothing in
 * `alerts`. A discovery is a fare nobody asked about, on a route nobody chose,
 * out of the least verified data in the app — the three properties that most
 * disqualify a thing from being allowed to interrupt somebody. It surfaces on a
 * screen the owner opens. That is the entire contract, and it is why this PR
 * could add a schedule entry where the returns foundation deliberately did not.
 *
 * =============================================================================
 * WHY IT IS ONE JOB AND NOT A FAN-OUT
 * =============================================================================
 * Every other scheduled command here queues one job per route, because those
 * are per-route questions and the parallelism is free. This one is a RANKING:
 * the shortlist cannot be chosen until all three sweeps are in, and the whole
 * budget argument rests on exactly five finalists being verified. Split across
 * workers it would be three jobs that have to rendezvous, or three independent
 * shortlists of five — fifteen finalists, and the budget gone.
 *
 * IT IS IDEMPOTENT. The write is an upsert keyed on (code, departure date), so
 * a retry or a hand-run of `orbit:discover` overwrites the same rows rather
 * than multiplying them.
 */
final class DiscoverDeals implements ShouldQueue
{
    use Queueable;

    /**
     * Long, because it is sequential and it is allowed to be.
     *
     * A run is 3 sweep requests, up to 7 window requests per finalist and up to
     * 5 SerpAPI searches — call it forty round trips at a 15-second read
     * timeout apiece in the worst case. Nobody is waiting: this runs at 05:20
     * on the queue, and the alternative to a generous timeout is Horizon
     * killing it mid-upsert on the one morning the provider is slow.
     */
    public int $timeout = 900;

    /**
     * ONE ATTEMPT. Every request in here is metered, and a retry would re-sweep,
     * re-fetch five windows and re-spend up to five of a 250-a-month SerpAPI
     * quota to produce the same screen a day early. A failed run means the
     * previous set stays up — which is exactly what `expires_at`'s half-day of
     * slack is for — and tomorrow's run fixes it.
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
        /*
         * THE OWNER'S CLOCK, not UTC's — the same reason every poll in this app
         * resolves it this way. "Departures that have gone by" must mean gone by
         * in Amsterdam, or a run that lands at 00:30 local prunes a departure
         * that has not happened.
         */
        $timezone = (string) config('orbit.timezone');
        $now = Date::now($timezone);

        $scorer = new CandidateScorer($policy);

        $candidates = $this->sweepAll($sweep, $logger);

        if ($candidates === []) {
            /*
             * NOTHING IS PRUNED ON AN EMPTY SWEEP. A provider that is down must
             * not empty the screen — the existing set is still the best answer
             * anybody has, and `expires_at` retires it on its own schedule.
             */
            $logger->info('Discovery swept nothing — leaving the existing set alone.');

            return;
        }

        $at = $now->toDateTimeImmutable();

        $shortlist = $scorer->shortlist($scorer->admit($candidates, $at));

        /*
         * THE SECOND LANE, CHOSEN BEFORE ANYTHING IS FETCHED AND AFTER THE FIRST
         * — the order is the dedupe. Lane B is told which destinations lane A
         * took so one city can never occupy a slot in both, which would be two
         * fetches and two cards to say one thing.
         */
        $relative = $this->relativeLane($candidates, $shortlist, $policy, $relativePolicy, $at);

        $logger->info('Discovery swept and scored.', [
            'candidates'     => count($candidates),
            'shortlisted'    => count($shortlist),
            'relative_picks' => count($relative),
            /*
             * HOW MANY OF THE SECOND LANE'S SLOTS WENT ON A CLAIM VERSUS ON A
             * QUESTION, and the run cannot be read without it. Most exploration
             * picks fail verification, which is CORRECT and would otherwise look
             * like a lane with a terrible hit rate — this is what distinguishes
             * "we surfaced nothing and learned three routes" from "we surfaced
             * nothing and wasted three fetches".
             */
            'relative_from_baseline' => count(array_filter(
                $relative,
                static fn (RelativePick $pick): bool => $pick->reason === PickReason::Baseline,
            )),
        ]);

        /*
         * THE GOOGLE BUDGET IS ASKED FOR ONCE, BEFORE ANY OF IT IS SPENT, and
         * it is zero on most boxes. No SERPAPI_KEY is the default state of this
         * app; quota under the reserve, or a probe that failed, are the same
         * answer. See App\Infrastructure\Verify\GoogleFlightsCheck — a skipped
         * check is not an error and never becomes a claim.
         */
        $budget = $google->available();
        $spent = 0;

        $verified = [];
        $learned = 0;

        /*
         * BOTH LANES IN ONE LOOP, ABSOLUTE FIRST, AND THE ORDER IS THE GOOGLE
         * BUDGET.
         *
         * The SerpAPI allowance did not grow when the second lane arrived — it
         * is the same ≤5 searches out of 250 a month — so the two lanes now
         * share it, and sharing needs a priority. Absolute wins: it is the
         * stronger claim (remarkable against every fare in the sweep, not just
         * against its own route) and it is the older one, so a run in which
         * quota runs out degrades the NEW feature rather than the shipped one.
         * A relative finalist that gets no search is shown unverified, which is
         * the ordinary state of every card on this strip anyway.
         */
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
             * ⚠ THE FLYWHEEL, AND IT TURNS FOR BOTH LANES.
             *
             * Every window this job fetches is a measurement of what that route
             * usually costs, and it is remembered whether or not a card comes of
             * it — including on the absolute lane, whose five finalists are
             * every bit as unwatched and unpriced as the relative lane's three.
             * Eight baselines a night rather than three, for no extra request.
             *
             * IT IS WRITTEN BEFORE THE VERIFICATION GATE BELOW, which is the
             * whole point: a candidate that fails `isRemarkable()` has still
             * told Orbit what its route costs, and that is the more valuable of
             * the two answers over a week. A lane that only learned from its
             * successes would learn almost nothing.
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

                /*
                 * THE CROSS-SECTIONAL GATE, AND IT IS THE ONE THAT DROPS THINGS.
                 * A candidate that beat a thousand others on €/km and is
                 * ordinary on its own route is a cheap ROUTE, not a cheap fare —
                 * and the owner can find a cheap route by looking. Both halves
                 * must pass; see DiscoveryPolicy::isRemarkable().
                 *
                 * BOTH LANES FACE IT UNCHANGED. The relative lane's own
                 * threshold was a filter on a REMEMBERED number, spent to decide
                 * where a request went; this is the freshly fetched window, and
                 * a lane that got to skip it would be making the exact claim
                 * this whole funnel exists to prevent.
                 */
                if (! $policy->isRemarkable($percentile, $savings ?? 0)) {
                    continue;
                }
            } elseif ($lane === Lane::Relative) {
                /*
                 * ⚠ AND THIS IS WHERE THE TWO LANES PART.
                 *
                 * An empty window is NOT a failed verification for an absolute
                 * discovery — see the note below — and it IS a disqualification
                 * for a relative one, because the two cards make different
                 * claims and only one of them survives without a window.
                 *
                 * "€18 to Vilnius is a steal, period" rests on €/km, which the
                 * sweep alone supports. "Rare price for this route" rests on
                 * what the route usually costs, and with no window there is NO
                 * EVIDENCE FOR IT AT ALL — the remembered baseline is why a
                 * request was spent, not proof the fare is rare today. Showing
                 * it anyway would put the feature's least supported sentence on
                 * its least supported card.
                 *
                 * The fetch is not wasted: the route simply had nothing to
                 * measure, so no baseline was written either, and the rotation
                 * will reach it again.
                 */
                continue;
            }

            /*
             * AN EMPTY WINDOW IS NOT A FAILED VERIFICATION, AND AN ABSOLUTE
             * CANDIDATE IS KEPT WITH NOTHING RECORDED.
             *
             * Travelpayouts' month-matrix coverage runs 41% to 87% even on the
             * routes Orbit watches daily, and a discovery is by definition an
             * obscure pair — so "no calendar for this route" is the ORDINARY
             * answer, not an outage, and treating it as a rejection would
             * systematically delete the most surprising half of every sweep.
             *
             * What it costs is stated on the card rather than hidden: percentile
             * and savings stay NULL, no "cheapest of N days" line is drawn, and
             * the badge cannot read anything but unverified. Shown, with less
             * said about it — which is the same bargain `found_at` made.
             */
            $verdict = null;

            if ($spent < $budget) {
                $verdict = $google->check(
                    $candidate->originIata,
                    $candidate->destinationIata,
                    $candidate->departureDate,
                );

                /*
                 * SPENT WHETHER OR NOT IT ANSWERED. A search that timed out or
                 * came back without `price_insights` has still been counted by
                 * SerpAPI, and a budget that only decremented on success would
                 * turn one bad night into the whole month's quota.
                 */
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
            /*
             * HOW MANY ROUTES THIS RUN NOW KNOWS THE USUAL PRICE OF. On a night
             * that surfaces nothing this is the only number that moved, and it
             * is the one that makes tomorrow's run better than today's.
             */
            'baselines_learned' => $learned,
            'google_budget'     => $budget,
            'google_spent'      => $spent,
        ]);

        $this->store($verified, $now, $policy);
        $this->prune($now, $policy);
    }

    /**
     * Every home origin swept and paired with a distance.
     *
     * ONE AIRPORT QUERY FOR THE WHOLE RUN. The sweeps come back with ~1,177
     * three-letter codes between them and each needs coordinates; asking the
     * database per code would be 1,177 queries a night for a table of 3,270
     * rows that fits comfortably in memory.
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
                 * A home airport with no row is a seeding problem and not a
                 * sweep to attempt — config('orbit.origins') and
                 * DestinationSeeder are asserted to agree by
                 * tests/Feature/SeedersTest, so this is the belt on that braces.
                 */
                $logger->warning('Discovery skipped an origin with no airport row.', ['origin' => $originIata]);

                continue;
            }

            foreach ($sweep->cheapestFromOrigin($originIata) as $fare) {
                $destination = $airports[$fare->destinationIata] ?? null;

                /*
                 * THE CITY CODES ARE DROPPED HERE, and this is the first place
                 * their absence is actually a problem. Travelpayouts normalises
                 * some airports to metropolitan codes — 45 of the 1,177 recorded
                 * rows were LON, MOW, MIL, BUE, CHI, JKT and friends — and Orbit
                 * has neither coordinates for them (so there is no honest €/km)
                 * nor a route code a lookup could open (so the card would go
                 * nowhere). See OriginSweepProvider, point 7, for why the
                 * adapter passes them through rather than deciding this itself.
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
     * The relative lane's picks — the candidates worth a window fetch because
     * of what they cost ON THEIR OWN ROUTE rather than per kilometre.
     *
     * THE POOL IS THE SWEEP MINUS THE ABSOLUTE LANE, PLUS SANITY. Everything
     * here is arithmetic over rows already in memory and one indexed query for
     * the remembered baselines; the expensive part is downstream and is bounded
     * by `lanes.relative.shortlist`.
     *
     * NOTE THAT A CANDIDATE THE ABSOLUTE LANE *ADMITTED* BUT DID NOT SHORTLIST
     * IS STILL ELIGIBLE HERE. The exclusion is by DESTINATION and against the
     * five that actually took a slot, not against the thirty-odd that cleared
     * the €/km floor — a route that was the absolute lane's sixth-best is a
     * perfectly good relative candidate, and refusing it would throw away the
     * part of the sweep most likely to be interesting.
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
         * THE SANITY FLOORS, AND THEY ARE THE ABSOLUTE LANE'S MINUS THE ONE
         * RULE THAT DEFINES THE OTHER PRODUCT.
         *
         * Distance and freshness are read off the SAME policy object the other
         * lane uses rather than re-declared here — under 400 km you are
         * describing a train and a three-day-old price is stale, and both of
         * those are true whichever argument the card is making. The price
         * ceiling is this lane's own and higher. And `maxCentsPerKilometre` is
         * absent entirely, which is the whole reason this lane exists.
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
         * ONE QUERY FOR EVERY BASELINE THIS RUN COULD POSSIBLY READ, keyed by
         * code — the same "ask once for the set" call `sweepAll` makes about
         * airports, and for the same reason: the pool is a few dozen routes and
         * asking per candidate would be a few dozen queries a night for a table
         * of a few hundred rows.
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
     * Write down what this route usually costs.
     *
     * THE ONLY THING IN DISCOVERY THAT OUTLIVES A RUN, and the reason the
     * relative lane is worth building at all: a window fetched tonight is a
     * baseline read for free on any morning in the next month. See the
     * `discovery_baselines` migration for why this is discovery's own table and
     * emphatically not `calendar_fares`.
     *
     * THE MEDIAN AND THE COUNT TOGETHER, NEVER THE MEDIAN ALONE. A median over
     * four priced days is four numbers, and the policy needs the count to refuse
     * it — see RelativeLanePolicy::$minBaselineDays.
     *
     * AN UPSERT ON `code`, SO A ROUTE HAS ONE CURRENT BELIEF. A baseline is not
     * history; nothing plots how Dublin's usual price moved, and keeping that
     * would be a second `route_price_history` for routes nobody watches.
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
         * ⚠ UTC BEFORE IT IS WRITTEN, for exactly the reason `store()` converts
         * its three timestamps: `upsert()` goes straight to the query builder
         * and skips the model's casts, so a Carbon in the owner's zone is
         * formatted by that zone and read back as UTC. Two hours of drift, in
         * the direction that makes every baseline look FRESHER than it is —
         * which is the direction that quietly keeps stale yardsticks in service.
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
     * Every fare in a finalist's own near window, in cents.
     *
     * THROUGH THE EXISTING PriceProvider PORT, which is the point: the window a
     * discovery is judged against is fetched by the same adapter, in the same
     * shape, as the calendar of any watched route. A second way to ask "what
     * does this route cost" would be a second definition of the current price.
     *
     * THE NEAR WINDOW AND NOT THE ELEVEN-MONTH HORIZON, for both of the reasons
     * `lookup.fresh_for_hours` gives: it is what `selfstats.cross_section_days`
     * summarises, so the comparison is like against like — and it is 6 or 7
     * billed requests instead of 12, which at five finalists is the difference
     * between ~35 and ~60.
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
     * Write the survivors down.
     *
     * ONE UPSERT FOR THE SET, keyed on (code, departure date) — so a candidate
     * that survives two runs in a row is one row whose price and verdict move,
     * rather than two cards for one flight.
     *
     * `discovered_at` IS REFRESHED ON EVERY RUN and `expires_at` with it, which
     * is what "still being found" means: a fare that keeps turning up keeps its
     * place, and one that stops turning up ages out on its own.
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
         * ⚠ CONVERTED TO UTC BEFORE THEY ARE WRITTEN, WHICH `upsert()` MAKES
         * NECESSARY AND WHICH THE MODEL WOULD OTHERWISE HAVE DONE.
         *
         * `$now` is deliberately in the OWNER'S timezone, because the date
         * arithmetic above and the prune below both ask "has this departure
         * gone by in Amsterdam". But `upsert()` goes straight to the query
         * builder and skips the model's casts entirely — so a Carbon at
         * 07:20 +02:00 is formatted by its own zone and stored as the STRING
         * `07:20`, which is then read back as 07:20 UTC. Two hours of drift, in
         * the direction that makes every discovery outlive its `expires_at`,
         * and completely invisible on a box running UTC.
         *
         * The same trap `google_verdict` hits three lines down for the same
         * reason: nothing between here and the database is the model.
         */
        $discoveredAt = $now->copy()->utc();
        $expiresAt = $now->copy()->addHours($policy->expiresAfterHours)->utc();

        $rows = [];

        foreach ($verified as [$candidate, $lane, $percentile, $savings, $verdict]) {
            $rows[] = [
                'origin_airport_id'      => $ids[$candidate->originIata],
                'destination_airport_id' => $ids[$candidate->destinationIata],
                'code'                   => $candidate->routeCode(),
                /*
                 * `->value` AND NOT THE ENUM, because `upsert()` goes straight
                 * to the query builder and skips the model's casts — the same
                 * trap `google_verdict` hits three lines down, and the same one
                 * the timestamps above are converted for.
                 */
                'lane'           => $lane->value,
                'departure_date' => $candidate->departureDate->format('Y-m-d'),
                'price_cents'    => $candidate->cents,
                'cents_per_km'   => $candidate->centsPerKilometre(),
                'percentile'     => $percentile,
                'savings_cents'  => $savings,
                /*
                 * ENCODED HERE RATHER THAN LEFT TO THE `array` CAST, because
                 * `upsert()` goes straight to the query builder and skips the
                 * model's casts entirely — a PHP array handed to it is a
                 * "could not convert to string" on Postgres and, worse, a
                 * silently useless value anywhere it is not.
                 */
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
                 * `lane` IS IN THE UPDATE LIST. A route that was a relative find
                 * yesterday and clears the €/km floor today must be able to
                 * become an absolute one — the eyebrow it draws is a claim about
                 * WHY it is on the screen, and a claim that outlived its reason
                 * is the thing this funnel exists to prevent.
                 */
                'lane',
                'price_cents', 'cents_per_km', 'percentile', 'savings_cents',
                /*
                 * `google_verdict` IS IN THE UPDATE LIST AND HAS TO BE — in both
                 * directions. A run with quota re-earns a verdict; a run without
                 * it must be able to take one AWAY, because a badge that
                 * outlived the check behind it is precisely the unverified claim
                 * this whole funnel exists to prevent.
                 */
                'google_verdict',
                'found_at', 'discovered_at', 'expires_at', 'updated_at',
            ],
        );
    }

    /**
     * Keep the table small, and keep it honest.
     *
     * FOUR DELETES, AND THEY RUN AFTER A SUCCESSFUL RUN ONLY — the empty-sweep
     * return above is what guarantees that. A provider outage must never be the
     * thing that clears the screen.
     */
    private function prune(CarbonInterface $now, DiscoveryPolicy $policy): void
    {
        /* 1. Rows that have said their piece. */
        Discovery::query()->where('expires_at', '<=', $now)->delete();

        /* 2. Departures that have gone by — see the `live` scope for the split. */
        Discovery::query()->whereDate('departure_date', '<', $now->toDateString())->delete();

        /*
         * 3. SUPERSEDED ROWS: the same route, discovered on an earlier run.
         *
         * The unique key is (code, departure_date), so a route found today for
         * a different date than yesterday's does NOT collide — it makes a second
         * row, and the screen would show Málaga twice on two dates. One card per
         * route is what the design asks for, and the newest run's date is the
         * one still being found.
         */
        $thisRun = $now->copy()->utc();

        Discovery::query()
            ->whereIn('code', Discovery::query()->select('code')->where('discovered_at', $thisRun))
            ->where('discovered_at', '<', $thisRun)
            ->delete();

        /*
         * 4. THE CEILING. Ordered exactly as the screen orders them, so what
         * survives is what would have been shown — a bound on the table rather
         * than a second opinion about which discoveries are good.
         */
        $keep = Discovery::query()
            ->orderBy('cents_per_km')
            ->orderBy('code')
            ->limit($policy->maxRows)
            ->pluck('id');

        Discovery::query()->whereNotIn('id', $keep)->delete();
    }
}
