<?php

declare(strict_types=1);

namespace App\Infrastructure\Pricing;

use App\Application\Ports\ReturnTripProvider;
use App\Domain\Pricing\NightsBand;
use App\Domain\Pricing\ReturnTrip;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;

/**
 * Round-trip fares, until there are real ones.
 *
 * NOT A TEST DOUBLE — the same standing FakePriceProvider has. `orbit.providers
 * .returns` defaults to `fake`, so this is the adapter a box runs until somebody
 * sets `ORBIT_RETURNS_PROVIDER=travelpayouts`, and it is what the later
 * return-trip screens and the browser sandbox will be built and demonstrated
 * against. It therefore has to look like a plausible airline, not like `return
 * 42`.
 *
 * =============================================================================
 * IT IS DELIBERATELY SPARSE, WHICH IS THE ONE PLACE IT DEPARTS FROM ITS ONE-WAY
 * SIBLING
 * =============================================================================
 * FakePriceProvider answers for EVERY day of the window, and that has a known
 * cost: tests/Feature/TravelpayoutsPollTest says out loud that "the whole app
 * has never once been exercised against a calendar with holes in it, and the
 * holes are what a poll is going to get from Tuesday onwards". Round-trip data
 * is far holier than one-way data — the share of near-window departure dates
 * carrying any round-trip fare at all was 27.5% (AMS-LIS), 14.8% (AMS-JFK),
 * 33.5% (AMS-BKK) and 7.7% (EIN-BCN) on 2026-08-16 — so a dense fake here would
 * not be an optimistic simplification, it would build every screen on top of an
 * assumption production breaks on day one.
 *
 * So a (departure date, stay length) cell is priced only when its hash falls
 * under `COVERAGE_IN_HUNDREDTHS`. Over the 181-day near window and the sixteen
 * stay lengths below that yields on the order of a hundred fares per route,
 * which is the same order as the 119, 56 and 23 entries the three recorded
 * routes actually returned. Most departure dates get one stay length or none —
 * which is what the live cache looks like.
 *
 * THE STAY LENGTHS IT OFFERS ARE THE CONFIGURED BANDS AND NOTHING ELSE.
 * `orbit.returns.durations` is what the app will ask questions along, so a fake
 * that priced 0 to 60 nights uniformly would spend 90% of its rows on lengths
 * no screen will ever query and still leave the bands thin. The real cache is
 * not band-shaped, but it is not uniform either — its mass sits on short stays
 * for short-haul and on one to four weeks for long-haul, which is roughly where
 * the bands are.
 *
 * PRICED AS TWO LEGS WITH A RETURN DISCOUNT. The out and back legs are taken
 * from FakeFareModel at their own departure dates — so a fortnight spanning the
 * seasonal peak costs more than one either side of it, and the Friday-out
 * Sunday-back premium falls out on its own — and the pair is then discounted.
 * `RETURN_DISCOUNT` is set from the measured ratio of cheapest return to
 * cheapest one-way: 1.68x (AMS-LIS), 1.45x (AMS-JFK), 1.74x (AMS-BKK), i.e. a
 * return costs well under two one-ways.
 *
 * SHARING FakeFareModel WITH THE OTHER TWO FAKES IS THE POINT. If round-trip
 * prices came from their own generator, the day a screen shows a one-way fare
 * next to a return one they would tell contradictory stories about the same
 * route — and the bug would look like a bug in whichever was on the right.
 */
final readonly class FakeReturnProvider implements ReturnTripProvider
{
    /**
     * How many of a hundred (departure date, stay length) cells carry a fare.
     *
     * FIVE, FROM THE MEASUREMENTS ABOVE rather than from taste: 181 days x 16
     * stay lengths x 5% is about 145 fares per route, against the 119 (AMS-LIS),
     * 56 (AMS-JFK) and 23 (EIN-BCN) real entries recorded inside the same
     * window. Raising it makes every future return-trip screen look better than
     * production ever will.
     */
    private const COVERAGE_IN_HUNDREDTHS = 5;

    /** A return is this much of two separate one-ways — see the docblock. */
    private const RETURN_DISCOUNT = 0.86;

    public function __construct(private FakeFareModel $model = new FakeFareModel) {}

    /**
     * @return list<ReturnTrip>
     */
    public function cheapestReturns(
        string $originIata,
        string $destinationIata,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?NightsBand $nights = null,
    ): array {
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);

        if ($to < $from) {
            return [];
        }

        /*
         * "As of now" through the Date facade rather than `new
         * DateTimeImmutable`, because that is the clock `Date::setTestNow()`
         * moves — and a fake that ignored it would price every replayed morning
         * identically. A real adapter reads a wall clock it cannot move.
         */
        $observedAt = Date::now()->toDateTimeImmutable();

        $routeCode = $originIata.'-'.$destinationIata;
        $lengths = $this->stayLengths();
        $trips = [];

        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            foreach ($lengths as $stay) {
                if ($nights !== null && ! $nights->contains($stay)) {
                    continue;
                }

                /*
                 * THE HOLE, AND IT IS STABLE. crc32 of the route, the date and
                 * the stay length — so the same cell is empty on this box, in
                 * CI and after `docker compose down -v`, which is what lets a
                 * feature test assert that a particular pair has no fare.
                 */
                if (crc32($routeCode.':returns:'.$day->format('Ymd').':'.$stay) % 100 >= self::COVERAGE_IN_HUNDREDTHS) {
                    continue;
                }

                $out = $this->model->priceCents($routeCode, $day, $observedAt);
                $back = $this->model->priceCents($routeCode, $day->modify("+{$stay} days"), $observedAt);

                /*
                 * FOUND NOW, BECAUSE THIS ONE REALLY DID JUST INVENT IT. Null
                 * would mean "we do not know how old this is" and would render
                 * as no line at all, so every screenshot and every sandbox run
                 * would silently exercise the one path where the freshness
                 * feature is invisible. Stamping the clock keeps the fake a
                 * plausible provider rather than a hole in the coverage.
                 *
                 * IT IS ALSO THE ONE THING THE FAKE FLATTERS. Real round-trip
                 * fares come out of a seven-day-deep cache and are routinely
                 * days old (TravelpayoutsReturnProvider, point 9); these are
                 * always fresh.
                 */
                $trips[] = new ReturnTrip(
                    $day,
                    $stay,
                    (int) round(($out + $back) * self::RETURN_DISCOUNT),
                    $observedAt,
                );
            }
        }

        return $trips;
    }

    /**
     * Every stay length the configured bands cover, ascending and without
     * duplicates.
     *
     * READ FROM CONFIG RATHER THAN LISTED HERE so that a box which retunes
     * `orbit.returns.durations` gets a fake that still has fares in the bands it
     * asks about. A hard-coded list would go quietly empty the day somebody
     * added a "three weeks" band.
     *
     * @return list<int>
     */
    private function stayLengths(): array
    {
        /** @var list<array{int, int}> $durations */
        $durations = config('orbit.returns.durations', []);

        $lengths = [];

        foreach ($durations as $pair) {
            $band = NightsBand::of($pair);

            for ($n = $band->min; $n <= $band->max; $n++) {
                $lengths[$n] = $n;
            }
        }

        ksort($lengths);

        return array_values($lengths);
    }
}
