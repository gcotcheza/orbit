<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\CalendarFare;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRouteData;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The lower bound every "cheapest departure" read shares: today stays, yesterday goes.
 */
final class CalendarFareDepartingFromTest extends TestCase
{
    use BuildsRouteData, RefreshDatabase;

    /** Written both ways the table is written: through the cast, and as a bare 'Y-m-d'. */
    #[Test]
    public function it_keeps_today_and_drops_yesterday(): void
    {
        $route = $this->makeRoute('AMS', 'OPO');
        $this->offer($route, ['2026-08-13' => 3000, '2026-08-14' => 4000]);

        DB::table('calendar_fares')->insert([
            ['route_id' => $route->id, 'departure_date' => '2026-08-12', 'price_cents' => 2000, 'fetched_at' => Date::now()],
            ['route_id' => $route->id, 'departure_date' => '2026-08-15', 'price_cents' => 5000, 'fetched_at' => Date::now()],
        ]);

        $dates = CalendarFare::query()
            ->departingFrom(Date::parse('2026-08-14 00:00:00'))
            ->orderBy('departure_date')
            ->get()
            ->map(static fn (CalendarFare $fare): string => $fare->departure_date->toDateString())
            ->all();

        $this->assertSame(['2026-08-14', '2026-08-15'], $dates);
    }
}
