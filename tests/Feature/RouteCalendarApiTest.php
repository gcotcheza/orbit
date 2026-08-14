<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Route;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsRouteData;
use Tests\TestCase;

/**
 * GET /api/routes/{code}/calendar — the heatmap, a month at a time.
 *
 * The verdict boundaries are design/README.md §3 and are worked out on paper
 * here: with a month running €40 to €100 the range is €60, so "cheap" is
 * anything at or under €40 + 28% of €60 = €56.80 and "pricey" anything at or
 * over €40 + 66% = €79.60.
 */
final class RouteCalendarApiTest extends TestCase
{
    use BuildsRouteData, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-14 09:00:00');
        $this->owner = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function seedMonth(): Route
    {
        $route = $this->makeRoute('AMS', 'FAO');

        $this->offer($route, [
            '2026-09-01' => 4000,  // the month's floor
            '2026-09-02' => 5000,  // still inside 28%
            '2026-09-03' => 6000,  // mid
            '2026-09-04' => 7000,  // mid
            '2026-09-05' => 8000,  // past 66%
            '2026-09-06' => 10000, // the month's ceiling
            // A neighbouring month, which must not leak into September.
            '2026-10-04' => 3000,
        ]);

        return $route;
    }

    #[Test]
    public function a_guest_is_refused_with_json(): void
    {
        $this->seedMonth();

        $this->getJson('/api/routes/AMS-FAO/calendar?month=2026-09')->assertUnauthorized();
    }

    #[Test]
    public function it_returns_one_entry_per_day_with_a_verdict(): void
    {
        $this->seedMonth();

        $response = $this->actingAs($this->owner)->getJson('/api/routes/AMS-FAO/calendar?month=2026-09');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => ['days' => [['date', 'price', 'verdict']], 'min', 'max', 'cheapest' => ['date', 'price']],
            'meta' => ['code', 'month'],
        ]);

        $response->assertJsonCount(6, 'data.days');
        $response->assertJsonPath('meta.code', 'AMS-FAO');
        $response->assertJsonPath('meta.month', '2026-09');
    }

    #[Test]
    public function the_verdicts_follow_the_designs_thresholds(): void
    {
        $this->seedMonth();

        $response = $this->actingAs($this->owner)->getJson('/api/routes/AMS-FAO/calendar?month=2026-09');

        $this->assertSame(
            ['cheap', 'cheap', 'mid', 'mid', 'pricey', 'pricey'],
            array_column((array) $response->json('data.days'), 'verdict'),
        );
    }

    #[Test]
    public function the_bounds_and_the_cheapest_day_are_this_months(): void
    {
        $this->seedMonth();

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-FAO/calendar?month=2026-09')
            ->assertJsonPath('data.min', 40)
            ->assertJsonPath('data.max', 100)
            ->assertJsonPath('data.cheapest.date', '2026-09-01')
            ->assertJsonPath('data.cheapest.price', 40);
    }

    #[Test]
    public function days_with_no_fare_are_simply_absent(): void
    {
        $route = $this->makeRoute('AMS', 'FAO');
        $this->offer($route, ['2026-09-04' => 5000, '2026-09-09' => 6000]);

        $response = $this->actingAs($this->owner)->getJson('/api/routes/AMS-FAO/calendar?month=2026-09');

        $response->assertJsonCount(2, 'data.days');
        $this->assertSame(
            ['2026-09-04', '2026-09-09'],
            array_column((array) $response->json('data.days'), 'date'),
        );
    }

    /**
     * The poll window is about three months, so paging past it is a normal
     * thing for the screen to do and must not be an error.
     */
    #[Test]
    public function a_month_beyond_the_window_is_empty_rather_than_missing(): void
    {
        $this->seedMonth();

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-FAO/calendar?month=2029-01')
            ->assertOk()
            ->assertJsonPath('data.days', [])
            ->assertJsonPath('data.min', null)
            ->assertJsonPath('data.max', null)
            ->assertJsonPath('data.cheapest', null);
    }

    /**
     * A month whose fares are all the same price has no range to place them
     * in; every day is "mid" rather than every day being both the cheapest and
     * the dearest.
     */
    #[Test]
    public function a_flat_month_is_all_middle(): void
    {
        $route = $this->makeRoute('AMS', 'FAO');
        $this->offer($route, ['2026-09-01' => 6000, '2026-09-02' => 6000, '2026-09-03' => 6000]);

        $response = $this->actingAs($this->owner)->getJson('/api/routes/AMS-FAO/calendar?month=2026-09');

        $this->assertSame(['mid', 'mid', 'mid'], array_column((array) $response->json('data.days'), 'verdict'));
        $response->assertJsonPath('data.cheapest.date', '2026-09-01');
    }

    #[Test]
    public function the_month_defaults_to_the_current_one(): void
    {
        $route = $this->makeRoute('AMS', 'FAO');
        $this->offer($route, ['2026-08-20' => 5500]);

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-FAO/calendar')
            ->assertOk()
            ->assertJsonPath('meta.month', '2026-08')
            ->assertJsonCount(1, 'data.days');
    }

    #[Test]
    public function a_month_that_is_not_a_month_is_rejected(): void
    {
        $this->seedMonth();

        foreach (['2026-13', 'now', '2026-1', '26-01', '2026-09-01', "2026-09' or '1"] as $month) {
            $this->actingAs($this->owner)
                ->getJson('/api/routes/AMS-FAO/calendar?month='.urlencode($month))
                ->assertStatus(422)
                ->assertJsonValidationErrors('month');
        }
    }

    #[Test]
    public function an_unknown_route_is_a_json_404(): void
    {
        $this->actingAs($this->owner)->getJson('/api/routes/AMS-XXX/calendar?month=2026-09')
            ->assertNotFound()
            ->assertJsonPath('message', 'Unknown route.');
    }
}
