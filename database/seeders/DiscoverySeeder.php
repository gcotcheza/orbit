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
 * ⚠ THE GUARD IS THE WHOLE FILE, READ IT BEFORE REMOVING IT — an unguarded
 * seeder spends metered API budget on every deploy (docs/BUSINESS-LOGIC.md §36).
 */
final class DiscoverySeeder extends Seeder
{
    use WithoutModelEvents;

    // Roughly a fortnight of exploration at three routes a night — a sandbox
    // number with no production counterpart (docs/BUSINESS-LOGIC.md §36).
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

    // The relative lane's pool, deliberately — ordered and capped for
    // determinism (docs/BUSINESS-LOGIC.md §36).
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
                    // Measured now, not spread out — a baseline the policy
                    // considers FRESH, as on a box that's been running a while.
                    'measured_at' => $now->copy()->utc(),
                ],
            );

            $measured++;
        }

        return $measured;
    }
}
