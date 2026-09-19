<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Route;
use DateTimeImmutable;
use App\Models\ReturnFare;
use App\Models\ReturnObservation;
use Illuminate\Http\JsonResponse;
use App\Domain\Pricing\DealScorer;
use App\Domain\Pricing\PricePoint;
use App\Domain\Pricing\PriceStats;
use Tests\Concerns\BuildsRouteData;
use App\Domain\Pricing\PriceHistory;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * GET /api/routes/{code} — the "Return trips" section's whole supply: every configured band,
 * and what a thin one is allowed to claim (docs/BUSINESS-LOGIC.md §15, docs/API.md `returns`).
 */
final class ReturnsOnRouteDetailTest extends TestCase
{
    use BuildsRouteData, RefreshDatabase;

    private User $owner;

    private Route $route;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-09-03 09:00:00');

        $this->owner = User::factory()->create();
        $this->route = $this->makeRoute('AMS', 'LIS');
    }

    /** R1 — the bands are the config's, in its order, and all of them are answered. */
    #[Test]
    public function every_configured_band_is_answered_in_config_order(): void
    {
        $this->seedFare('2026-09-20', nights: 7, cents: 33400);

        $response = $this->read();

        $response->assertJsonCount(4, 'data.returns');
        $response->assertJsonPath('data.returns.*.band.nights', [[2, 3], [6, 8], [13, 15], [21, 28]]);
        $response->assertJsonPath('data.returns.*.band.label', [
            'A long weekend',
            'A week away',
            'A fortnight',
            'Three to four weeks',
        ]);
    }

    /** R6 — a band nobody quoted is a row with no fare, never a missing row. */
    #[Test]
    public function a_band_with_no_fares_is_listed_with_nothing_in_it(): void
    {
        $this->seedFare('2026-09-20', nights: 7, cents: 33400);

        $response = $this->read();

        $response->assertJsonPath('data.returns.0.fare', null);
        $response->assertJsonPath('data.returns.1.fare.current', 334);
        $response->assertJsonPath('data.returns.2.fare', null);
        $response->assertJsonPath('data.returns.3.fare', null);
    }

    /** R5 — the sparse band, which is the ordinary one here. */
    #[Test]
    public function a_thin_band_has_a_price_and_no_usual_price(): void
    {
        foreach ([41000, 38000, 45000] as $index => $cents) {
            $this->seedFare($this->departure($index), nights: 7, cents: $cents);
        }

        $response = $this->read();

        $response->assertJsonPath('data.returns.1.fare.current', 380);
        $response->assertJsonPath('data.returns.1.fare.usual', null);
        $response->assertJsonPath('data.returns.1.fare.pctBelow', null);
        $response->assertJsonPath('data.returns.1.fare.sampleCount', 3);
    }

    /** R3, R4 — the usual price, the gap to it, and which trip the price is actually for. */
    #[Test]
    public function a_band_with_enough_fares_carries_its_usual_price_and_the_gap_to_it(): void
    {
        foreach ([10000, 20000, 30000, 40000, 50000, 60000, 70000, 80000] as $index => $cents) {
            $this->seedFare($this->departure($index), nights: 6 + ($index % 3), cents: $cents);
        }

        $response = $this->read();

        $response->assertJsonPath('data.returns.1.fare.current', 100);
        $response->assertJsonPath('data.returns.1.fare.usual', 400);
        // Never below zero on a band: the current price is the cheapest of the pool `usual` summarises.
        $this->assertGreaterThanOrEqual(0, $response->json('data.returns.1.fare.pctBelow'));
        $response->assertJsonPath('data.returns.1.fare.pctBelow', 75);
        $response->assertJsonPath('data.returns.1.fare.sampleCount', 8);
        $response->assertJsonPath('data.returns.1.fare.nights', 6);
        $response->assertJsonPath('data.returns.1.fare.departure', '2026-09-20');
    }

    #[Test]
    public function an_old_fare_far_under_its_own_bands_usual_price_may_be_gone(): void
    {
        $this->seedBand(foundAt: '2026-08-29 20:11:25');

        $response = $this->read();

        $response->assertJsonPath('data.returns.1.fare.mayBeGone', true);
        // The owner's offset, like every other moment in this API.
        $response->assertJsonPath('data.returns.1.fare.foundAt', '2026-08-29T22:11:25+02:00');
    }

    #[Test]
    public function a_fare_whose_find_time_is_unknown_is_never_demoted(): void
    {
        $this->seedBand(foundAt: null);

        $response = $this->read();

        $response->assertJsonPath('data.returns.1.fare.mayBeGone', false);
        $response->assertJsonPath('data.returns.1.fare.foundAt', null);
    }

    /** Each priced band hands off its OWN trip: that departure out, that stay back. */
    #[Test]
    public function a_priced_band_carries_the_round_trip_link_for_its_own_dates(): void
    {
        config()->set('orbit.travelpayouts.marker', '123456');

        $this->seedFare('2026-09-20', nights: 7, cents: 33400);

        $response = $this->read();

        $response->assertJsonPath(
            'data.returns.1.fare.booking.aviasales',
            'https://www.aviasales.com/search/AMS2009LIS27091?marker=123456',
        );

        /* A band with no fare has no fare to hang a hand-off on. */
        $response->assertJsonPath('data.returns.0.fare', null);
    }

    /**
     * R9 — the words are the one-way scorer's, run on this band's own pool. The expectation is
     * computed THROUGH the scorer: hand-picking a word would let the two drift apart.
     */
    #[Test]
    public function a_band_with_a_usual_price_and_a_history_carries_the_scorers_own_verdict(): void
    {
        $this->seedBand(foundAt: null);
        $history = $this->seedMornings([16000, 15000, 14000, 13000, 12000, 11000, 10500, 10000]);

        $response = $this->read();

        $verdict = $this->app->make(DealScorer::class)->score(
            10000,
            PriceStats::fromSamples([10000, 20000, 30000, 40000, 50000, 60000]),
            new PriceHistory($history),
            trackingDays: 8,
        )->verdict;

        $response->assertJsonPath('data.returns.1.fare.verdict.label', $verdict->label);
        $response->assertJsonPath('data.returns.1.fare.verdict.short', $verdict->short);
        $response->assertJsonPath('data.returns.1.fare.verdict.tone', $verdict->tone);
    }

    /** R9 — R5 withholds the usual price, so there is nothing to score against. */
    #[Test]
    public function a_band_too_thin_for_a_usual_price_has_no_verdict(): void
    {
        foreach ([41000, 38000, 45000] as $index => $cents) {
            $this->seedFare($this->departure($index), nights: 7, cents: $cents);
        }

        $this->seedMornings([16000, 15000, 14000, 13000, 12000, 11000, 10500, 10000]);

        $response = $this->read();

        $response->assertJsonStructure([
            'data' => ['returns' => [1 => ['fare' => [
                'current', 'usual', 'pctBelow', 'nights', 'departure',
                'foundAt', 'mayBeGone', 'sampleCount', 'booking', 'verdict',
            ]]]],
        ]);

        $response->assertJsonPath('data.returns.1.fare.usual', null);
        $response->assertJsonPath('data.returns.1.fare.verdict', null);
    }

    /** R9 — a ghost is not scored, so the verdict pill and the "may be gone" pill never meet. */
    #[Test]
    public function a_fare_that_may_already_be_gone_is_not_scored(): void
    {
        $this->seedBand(foundAt: '2026-08-29 20:11:25');
        $this->seedMornings([16000, 15000, 14000, 13000, 12000, 11000, 10500, 10000]);

        $response = $this->read();

        $response->assertJsonPath('data.returns.1.fare.mayBeGone', true);
        $response->assertJsonPath('data.returns.1.fare.usual', 300);
        $response->assertJsonPath('data.returns.1.fare.verdict', null);
    }

    /** §7's day-1 floor, on a band: two mornings is a state, not a verdict. */
    #[Test]
    public function a_band_orbit_has_only_just_started_pricing_says_new(): void
    {
        $this->seedBand(foundAt: null);
        $this->seedMornings([10500, 10000], from: '2026-09-02');

        $response = $this->read();

        $response->assertJsonPath('data.returns.1.fare.verdict.short', 'New');
        $response->assertJsonPath('data.returns.1.fare.verdict.label', 'Not enough data yet');
        $response->assertJsonPath('data.returns.1.fare.verdict.tone', 'normal');
    }

    /**
     * R9 — a run of mornings that stopped is not a trend. `lastDays()` counts back from the
     * newest point, so without the freshness bound June's slide would be published as today's.
     */
    #[Test]
    public function a_band_whose_mornings_stopped_weeks_ago_is_scored_on_its_pool_alone(): void
    {
        $this->seedBand(foundAt: null);

        /* Eight mornings ending 45 days ago, and nothing since. */
        $this->seedMornings([16000, 15000, 14000, 13000, 12000, 11000, 10500, 10000], from: '2026-07-13');

        $response = $this->read();

        $verdict = $this->app->make(DealScorer::class)->score(
            10000,
            PriceStats::fromSamples([10000, 20000, 30000, 40000, 50000, 60000]),
            PriceHistory::empty(),
            trackingDays: 53,
        )->verdict;

        $response->assertJsonPath('data.returns.1.fare.verdict.label', $verdict->label);
        $this->assertNotSame(
            'Cheap & still falling',
            $response->json('data.returns.1.fare.verdict.label'),
            'A trend that ended in July must not be published as one that is still going.',
        );
    }

    /** R9 — the chart's bound hides the oldest mornings; it must not shorten the route's age. */
    #[Test]
    public function a_band_first_seen_beyond_the_charts_depth_is_still_a_band_orbit_knows(): void
    {
        $this->seedBand(foundAt: null);

        /* One morning 100 days back, outside the chart's depth, and two inside it. */
        $this->seedMorning('2026-05-26', 40000);
        $this->seedMorning('2026-09-02', 10000);
        $this->seedMorning('2026-09-03', 10000);

        $response = $this->read();

        $this->assertNotSame(
            'New',
            $response->json('data.returns.1.fare.verdict.short'),
            'Orbit has held this band for 101 days; only two of them are inside the chart.',
        );
        $response->assertJsonPath('data.returns.1.fare.verdict.tone', 'good');
    }

    /** The section is the detail's alone: the watchlist's rows are unchanged. */
    #[Test]
    public function the_summary_the_other_screens_share_carries_no_return_trips(): void
    {
        $this->seedFare('2026-09-20', nights: 7, cents: 33400);
        $this->watch($this->owner, $this->route);

        $this->actingAs($this->owner)->getJson('/api/watchlist')
            ->assertOk()
            ->assertJsonMissingPath('data.0.returns');
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function read(): TestResponse
    {
        return $this->actingAs($this->owner)->getJson('/api/routes/AMS-LIS')->assertOk();
    }

    /** A pool deep enough for a usual price, whose cheapest fare is the one under test. */
    private function seedBand(?string $foundAt): void
    {
        $this->seedFare($this->departure(0), nights: 7, cents: 10000, foundAt: $foundAt);

        foreach ([20000, 30000, 40000, 50000, 60000] as $index => $cents) {
            $this->seedFare($this->departure($index + 1), nights: 7, cents: $cents);
        }
    }

    /**
     * Consecutive mornings of the 6-8 band's own history, oldest first, and the same points as
     * a list for the expectation to be scored from.
     *
     * @param  list<int>  $cents
     * @return list<PricePoint>
     */
    private function seedMornings(array $cents, string $from = '2026-08-27'): array
    {
        $points = [];

        foreach ($cents as $index => $amount) {
            $points[] = $this->seedMorning(Date::parse($from)->addDays($index)->toDateString(), $amount);
        }

        return $points;
    }

    /** One morning of that band's history, and the point the scorer would see it as. */
    private function seedMorning(string $observedOn, int $cents): PricePoint
    {
        ReturnObservation::query()->insert([
            'route_id'    => $this->route->id,
            'nights_min'  => 6,
            'nights_max'  => 8,
            'observed_on' => $observedOn,
            'price_cents' => $cents,
            'nights'      => 7,
            'found_at'    => null,
            'created_at'  => Date::now(),
            'updated_at'  => Date::now(),
        ]);

        return new PricePoint(new DateTimeImmutable($observedOn), $cents);
    }

    private function departure(int $index): string
    {
        return Date::parse('2026-09-20')->addDays($index)->toDateString();
    }

    /**
     * WARNING: uses `insert` + a bare 'Y-m-d', matching the poll's upsert — `create()`'s date
     * cast round-trips differently on SQLite than Postgres.
     */
    private function seedFare(string $departure, int $nights, int $cents, ?string $foundAt = null): void
    {
        ReturnFare::query()->insert([
            'route_id'       => $this->route->id,
            'departure_date' => $departure,
            'nights'         => $nights,
            'price_cents'    => $cents,
            'fetched_at'     => Date::now()->format('Y-m-d H:i:s'),
            'found_at'       => $foundAt,
            'created_at'     => Date::now(),
            'updated_at'     => Date::now(),
        ]);
    }
}
