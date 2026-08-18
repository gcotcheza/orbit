<?php

declare(strict_types=1);

namespace App\Infrastructure\Pricing;

use App\Domain\Pricing\PriceStats;
use Illuminate\Support\Facades\Date;
use App\Application\Ports\PriceStatsProvider;

/**
 * "Usual price", until Amadeus has a key.
 *
 * A YEAR OF DEPARTURE DATES, summarised. Amadeus' price-analysis endpoint
 * hands back quartiles built from months of real fares, so the fake earns its
 * quartiles the same way — it prices all 365 departure dates from today and
 * takes the five-number summary — rather than inventing five numbers that no
 * fare it ever quotes would fall between.
 *
 * IT ASKS FOR A YEAR SEEN FROM SEVERAL BOOKING MOMENTS, not for a year seen
 * from this morning. See FakeFareModel::yearOfPrices(): a route's whole fare
 * level swings slowly, and a "usual" measured entirely inside today's swing
 * would move with it and cancel out, leaving every route permanently,
 * boringly average.
 */
final readonly class FakeStatsProvider implements PriceStatsProvider
{
    public function __construct(private FakeFareModel $model = new FakeFareModel) {}

    /**
     * NARROWER THAN THE PORT, which allows null: a simulation always has an
     * answer, and saying so lets every caller in the tests skip a null check
     * the real adapters will still have to make.
     */
    public function statsFor(string $originIata, string $destinationIata): PriceStats
    {
        return PriceStats::fromSamples($this->model->yearOfPrices(
            $originIata.'-'.$destinationIata,
            Date::now()->toDateTimeImmutable(),
        ));
    }
}
