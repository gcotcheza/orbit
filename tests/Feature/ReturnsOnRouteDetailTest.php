<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Route;
use App\Models\ReturnFare;
use Illuminate\Http\JsonResponse;
use Tests\Concerns\BuildsRouteData;
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
