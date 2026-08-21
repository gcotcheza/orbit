<?php

declare(strict_types=1);

namespace App\Infrastructure\Pricing;

use App\Domain\Pricing\PriceStats;
use Illuminate\Support\Facades\Date;
use App\Application\Ports\PriceStatsProvider;

/**
 * "Usual price", until a real stats key exists: a year of departure dates summarised into
 * quartiles, asked from several booking moments (see FakeFareModel::yearOfPrices()).
 */
final readonly class FakeStatsProvider implements PriceStatsProvider
{
    public function __construct(private FakeFareModel $model = new FakeFareModel) {}

    /**
     * NARROWER THAN THE PORT, which allows null: a simulation always has an answer, so
     * every caller in the tests can skip a null check the real adapters still make.
     */
    public function statsFor(string $originIata, string $destinationIata): PriceStats
    {
        return PriceStats::fromSamples($this->model->yearOfPrices(
            $originIata.'-'.$destinationIata,
            Date::now()->toDateTimeImmutable(),
        ));
    }
}
