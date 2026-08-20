<?php

declare(strict_types=1);

namespace App\Infrastructure\Pricing;

use DateTimeImmutable;
use App\Domain\Pricing\NightsBand;
use App\Domain\Pricing\ReturnTrip;
use Illuminate\Support\Facades\Date;
use App\Application\Ports\ReturnTripProvider;

/**
 * Round-trip fake, not a test double — deliberately sparse rather than dense like the one-way fake,
 * and priced as two FakeFareModel legs (docs/BUSINESS-LOGIC.md §15).
 */
final readonly class FakeReturnProvider implements ReturnTripProvider
{
    /**
     * Cells (of a hundred) that carry a fare: 5, measured against real coverage — raising it
     * flatters future screens.
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

        /**
         * Date::now(), not `new DateTimeImmutable` — that is the clock Date::setTestNow() moves,
         * and a fake ignoring it would price every replayed morning alike.
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

                /**
                 * The hole is stable: crc32(route, date, stay) is the same empty cell everywhere,
                 * so a test can assert "no fare".
                 */
                if (crc32($routeCode.':returns:'.$day->format('Ymd').':'.$stay) % 100 >= self::COVERAGE_IN_HUNDREDTHS) {
                    continue;
                }

                $out = $this->model->priceCents($routeCode, $day, $observedAt);
                $back = $this->model->priceCents($routeCode, $day->modify("+{$stay} days"), $observedAt);

                /**
                 * Stamped as found now, not null: null would hide the freshness feature from every
                 * screenshot. The one thing this fake flatters.
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
     * Every stay length the configured bands cover, ascending and deduped — read from config, so a
     * retuned `durations` still has fares in its bands.
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
