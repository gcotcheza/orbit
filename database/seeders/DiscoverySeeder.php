<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Airport;
use App\Jobs\DiscoverDeals;
use App\Domain\Geo\Haversine;
use Illuminate\Database\Seeder;
use App\Domain\Pricing\DatedFare;
use App\Models\DiscoveryBaseline;
use Illuminate\Support\Facades\Date;
use App\Domain\Discovery\DealCandidate;
use App\Application\Ports\PriceProvider;
use App\Domain\Discovery\CandidateScorer;
use App\Domain\Discovery\DiscoveryPolicy;
use App\Domain\Discovery\RelativeLanePolicy;
use App\Application\Ports\OriginSweepProvider;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

/**
 * Fill the discovery strip on a fresh box — but ONLY while a fake is sweeping.
 *
 * =============================================================================
 * ⚠ THE GUARD IS THE WHOLE FILE. READ IT BEFORE REMOVING IT.
 * =============================================================================
 * `db:seed` runs on EVERY DEPLOY (see the deploy runbook, and DatabaseSeeder's
 * docblock). A discovery run is 3 origin sweeps + up to 35 window fetches + up
 * to 5 SerpAPI searches, so an unguarded version of this seeder would spend ~38
 * metered Travelpayouts requests and 2% of a MONTHLY SerpAPI allowance every
 * single time somebody deployed a typo fix — on top of whatever the 06:00 hour
 * is already doing, and with no rate-limit budget written down for it anywhere.
 *
 * So it runs against the FAKE sweep provider and nothing else. On a box with
 * `ORBIT_SWEEP_PROVIDER=travelpayouts` this does nothing at all, and the 05:20
 * schedule entry — which the budget table in config/orbit.php actually accounts
 * for — is what fills the table.
 *
 * IT IS NOT CHECKING "AM I IN A TEST". It checks which adapter is bound,
 * because that is the fact that decides whether this costs money. A staging box
 * running the fakes gets a populated discovery strip and should; a production
 * box running the fakes gets one too, and that is also right — the fake is what
 * production actually runs until the keys are flipped (docs/PLAN.md), and a
 * screen that was empty until 05:20 the next morning would look broken on the
 * day this shipped.
 *
 * =============================================================================
 * IT RUNS THE ORDINARY JOB, WHICH IS THE SAME RULE FakeHistorySeeder FOLLOWS
 * =============================================================================
 * There is no fixture here and there is no hand-written `discoveries` row. The
 * seeder dispatches App\Jobs\DiscoverDeals synchronously and lets the real
 * funnel decide what survives — the same sweep, the same four thresholds, the
 * same cross-sectional check against the same PriceProvider the calendar uses.
 *
 * That matters more than convenience. A hand-seeded discovery would be a card
 * that no version of the funnel ever produced, so the browser gate would be
 * photographing a shape rather than a feature, and a threshold that quietly
 * stopped admitting anything would still screenshot perfectly. If this seeder
 * produces nothing, the funnel produces nothing, and that is worth finding out
 * on a sandbox rather than in production.
 *
 * IDEMPOTENT, like everything else `db:seed` calls: the job upserts on (code,
 * departure date) and prunes to `orbit.discovery.max_rows` on every run.
 *
 * =============================================================================
 * ⚠ THE FLYWHEEL HAS TO BE GIVEN A TURN, OR THE SECOND LANE IS INVISIBLE HERE
 * =============================================================================
 * The relative lane reads REMEMBERED baselines — what each route's own window
 * usually costs — and a fresh box remembers nothing. On its first run the lane
 * is therefore all exploration and surfaces NOTHING, which is the honest
 * production shape (config/orbit.php says so in as many words: "it gets smarter
 * every day it runs, and it starts knowing nothing"). A sandbox that ran the job
 * once would photograph a feature that looks broken and is not.
 *
 * So this seeder measures the baselines a fortnight of exploration would have
 * left behind, and then runs the job. WHAT IT DOES NOT DO IS INVENT THEM: each
 * baseline is a real median of that route's real fake window, fetched through
 * the same PriceProvider port the job itself uses, at the same window width. The
 * only thing being short-circuited is the CALENDAR — those measurements are
 * taken now rather than over the fourteen mornings it would take the rotation to
 * reach them.
 *
 * A FABRICATED BASELINE WOULD DEFEAT THE WHOLE POINT. Writing "€110" next to a
 * route so a €60 fare shows a 45% discount would make the browser gate a
 * photograph of a shape rather than of a feature — the same trap this seeder's
 * refusal to hand-write a `discoveries` row avoids one paragraph up. The
 * discount the sandbox displays is real: the fake's swept sale price against the
 * fake's own unsaled window.
 */
final class DiscoverySeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * How many routes' baselines to measure before the run.
     *
     * FORTY, WHICH IS ROUGHLY A FORTNIGHT OF EXPLORATION at three routes a
     * night — enough that the relative lane reliably has something to say, and
     * bounded because this is a loop over a fake pricing model rather than a
     * free lunch. It is a sandbox number and has no production counterpart: on a
     * real box the rotation does this over a fortnight, three at a time, and
     * `orbit.discovery.lanes.relative.shortlist` is the only setting involved.
     */
    private const BASELINES_TO_WARM = 40;

    public function run(): void
    {
        if (config('orbit.providers.sweep') !== 'fake') {
            $this->command?->getOutput()->writeln(
                '  <fg=yellow>Skipping discovery seed — a real sweep provider is configured, and `orbit:discover` is scheduled at 05:20.</>',
            );

            return;
        }

        $warmed = $this->warmBaselines();

        DiscoverDeals::dispatchSync();

        $this->command?->getOutput()->writeln(
            "  Discovery: measured {$warmed} route baselines, then ran the funnel.",
        );
    }

    /**
     * Measure what a sample of swept routes usually cost, exactly as an
     * exploration pick would have.
     *
     * THE POOL IS THE RELATIVE LANE'S, deliberately: routes far enough and fresh
     * enough and under the lane's own ceiling. Anything the absolute lane would
     * take on €/km is left out, because a baseline for a route that surfaces as
     * an absolute find teaches the relative lane nothing it can use.
     *
     * ORDERED BY ROUTE CODE AND CAPPED, so the same forty routes are measured on
     * this box, in CI and after `docker compose down -v` — the determinism rule
     * every fake in this app follows, and what lets the browser gate assert that
     * a relative card is on the screen at all.
     */
    private function warmBaselines(): int
    {
        $sweep = app(OriginSweepProvider::class);
        $prices = app(PriceProvider::class);
        $policy = app(DiscoveryPolicy::class);
        $relative = app(RelativeLanePolicy::class);

        $timezone = (string) config('orbit.timezone');
        $now = Date::now($timezone);
        $at = $now->toDateTimeImmutable();

        /** @var array<string, Airport> $airports */
        $airports = Airport::query()->get(['id', 'iata', 'lat', 'lng'])->keyBy('iata')->all();

        /** @var list<string> $origins */
        $origins = config('orbit.origins');

        $pool = [];

        foreach ($origins as $originIata) {
            $origin = $airports[$originIata] ?? null;

            if ($origin === null) {
                continue;
            }

            foreach ($sweep->cheapestFromOrigin($originIata) as $fare) {
                $destination = $airports[$fare->destinationIata] ?? null;

                if ($destination === null) {
                    continue;
                }

                $candidate = new DealCandidate(
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

                if ($candidate->kilometres < $policy->minKilometres
                    || $candidate->cents > $relative->maxPriceCents
                    || ! $policy->isFresh($candidate, $at)) {
                    continue;
                }

                $pool[$candidate->routeCode()] = $candidate;
            }
        }

        ksort($pool);

        $days = (int) config('orbit.discovery.verify_window_days');
        $from = $now->copy()->startOfDay();
        $to = $from->copy()->addDays($days);

        $measured = 0;

        foreach (array_slice($pool, 0, self::BASELINES_TO_WARM) as $candidate) {
            $window = array_map(
                static fn (DatedFare $fare): int => $fare->cents,
                $prices->cheapestPerDay(
                    $candidate->originIata,
                    $candidate->destinationIata,
                    $from->toDateTimeImmutable(),
                    $to->toDateTimeImmutable(),
                ),
            );

            $median = CandidateScorer::median($window);

            if ($median === null) {
                continue;
            }

            DiscoveryBaseline::query()->updateOrCreate(
                ['code' => $candidate->routeCode()],
                [
                    'median_cents' => $median,
                    'sample_days'  => count($window),
                    /*
                     * MEASURED NOW, which is the one thing about these baselines
                     * that a fortnight of real exploration would have spread out.
                     * It is the conservative direction: a baseline the policy
                     * considers FRESH, so the lane behaves as it would on a box
                     * that has been running a while.
                     */
                    'measured_at' => $now->copy()->utc(),
                ],
            );

            $measured++;
        }

        return $measured;
    }
}
