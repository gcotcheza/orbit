<?php

declare(strict_types=1);

namespace App\Http\Resources;

use DateTimeZone;
use App\Models\Route;
use DateTimeImmutable;
use Illuminate\Http\Request;
use App\Models\LivePriceCheck;
use App\Domain\Pricing\MayBeGone;
use App\Domain\Pricing\NightsBand;
use App\Domain\Pricing\PricePoint;
use Illuminate\Support\Facades\Date;
use App\Application\Routes\BookingLink;
use App\Domain\Pricing\ReturnBandPrice;
use App\Application\Routes\RouteSnapshot;

/**
 * Everything the route detail screen draws (design/README.md §2). The live check is passed in
 * because the ADVICE depends on it (docs/BUSINESS-LOGIC.md §17).
 */
final class RouteDetailResource extends RouteSummaryResource
{
    /**
     * Every configured duration band, the empty ones included (docs/API.md, `returns`).
     *
     * @param  list<array{band: NightsBand, price: ReturnBandPrice|null}>  $returns
     */
    public function __construct(
        RouteSnapshot $snapshot,
        private readonly ?LivePriceCheck $live = null,
        private readonly array $returns = [],
    ) {
        parent::__construct($snapshot);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->snapshot();
        $stats = $snapshot->stats;
        $cheapest = $snapshot->cheapest;

        $summary = parent::toArray($request);

        $now = Date::now()->toDateTimeImmutable();
        $zone = new DateTimeZone((string) config('orbit.timezone'));

        $staleAfterHours = (int) config('orbit.live_check.stale_after_hours');
        $underUsualPercent = (int) config('orbit.live_check.under_usual_percent');

        $mayBeGone = $snapshot->cheapestMayBeGone($now, $staleAfterHours, $underUsualPercent);

        return [
            ...$summary,

            /*
             * Observation dates — when we looked — not departure dates. The
             * calendar endpoint is the other axis.
             */
            'history' => array_map(static fn (PricePoint $point): array => [
                'date'  => $point->on->format('Y-m-d'),
                'price' => Euros::from($point->cents),
            ], $snapshot->history->points),

            'stats' => $stats === null ? null : [
                'min'    => Euros::from($stats->minCents),
                'p25'    => Euros::from($stats->p25Cents),
                'median' => Euros::from($stats->medianCents),
                'p75'    => Euros::from($stats->p75Cents),
                'max'    => Euros::from($stats->maxCents),
            ],

            'advice' => $this->advice($snapshot, $mayBeGone),

            'cheapest' => $summary['cheapest'] === null ? null : [
                ...$summary['cheapest'],
                'foundAt' => $cheapest?->foundAt?->setTimezone($zone)->format('c'),

                /*
                 * ⚠ THE SERVER'S JUDGEMENT AND NOT THE CLIENT'S: old enough AND
                 * far enough under usual that the headline should not be shouted.
                 */
                'mayBeGone' => $mayBeGone,
            ],

            'returns' => array_map(fn (array $band): array => [
                'band' => [
                    'label'  => $band['band']->label(),
                    'nights' => [$band['band']->min, $band['band']->max],
                ],
                'fare' => $band['price'] === null
                    ? null
                    : $this->returnFare(
                        $snapshot->route,
                        $band['price'],
                        $now,
                        $zone,
                        $staleAfterHours,
                        $underUsualPercent,
                    ),
            ], $this->returns),

            'booking' => [
                'aviasales'  => BookingLink::aviasales($snapshot->route, $cheapest?->departureDate),
                'skyscanner' => BookingLink::skyscanner($snapshot->route, $cheapest?->departureDate),
            ],
        ];
    }

    /**
     * What one band holds, or nothing at all — the same judgement and the same thresholds the
     * headline fare gets, against this band's own usual price (docs/API.md, `returns`).
     *
     * @return array<string, mixed>
     */
    private function returnFare(
        Route $route,
        ReturnBandPrice $price,
        DateTimeImmutable $now,
        DateTimeZone $zone,
        int $staleAfterHours,
        int $underUsualPercent,
    ): array {
        $usual = $price->usual;

        $mayBeGone = MayBeGone::decide(
            $price->currentCents,
            $price->foundAt,
            $usual,
            $now,
            $staleAfterHours,
            $underUsualPercent,
        );

        return [
            'current'     => Euros::from($price->currentCents),
            'usual'       => $usual === null ? null : Euros::from($usual->usualCents()),
            'pctBelow'    => $usual?->percentUnderUsual($price->currentCents),
            'nights'      => $price->nights,
            'departure'   => $price->departureDate->format('Y-m-d'),
            'foundAt'     => $price->foundAt?->setTimezone($zone)->format('c'),
            'mayBeGone'   => $mayBeGone,
            'sampleCount' => $price->sampleCount,
            'booking'     => [
                'aviasales' => BookingLink::aviasalesReturn($route, $price->departureDate, $price->returnDate()),
            ],
        ];
    }

    /**
     * ⚠ The callout is the page's conclusion, so it is the thing that must not
     * recommend a fare the same document has just cast doubt on.
     *
     * @return array{title: string, body: string, tone: string}
     */
    private function advice(RouteSnapshot $snapshot, bool $mayBeGone): array
    {
        $cheapest = $snapshot->cheapest;
        $lowest = $this->live?->lowestCents();

        if ($cheapest !== null && $lowest !== null && self::contradicts($cheapest->cents, $lowest)) {
            return [
                'title' => 'Google cannot find this fare',
                'body'  => sprintf(
                    'Orbit has %s cached; the cheapest Google can find for %s is %s. Treat the cached fare as gone.',
                    self::money($cheapest->cents),
                    $cheapest->departureDate->format('j M'),
                    self::money($lowest),
                ),
                'tone' => 'warn',
            ];
        }

        if ($mayBeGone && $lowest === null && $cheapest !== null) {
            return [
                'title' => 'Cheap, but it may be gone',
                'body'  => sprintf(
                    '%s is %d%% under this route’s usual price, and old enough that fares like it have usually sold. %s',
                    self::money($cheapest->cents),
                    abs((int) $snapshot->stats?->percentUnderUsual($cheapest->cents)),
                    /* Telling somebody to check a price they have just checked
                       is the app forgetting the answer it charged them for. */
                    $this->live === null
                        ? 'Check the live price before counting on it.'
                        : 'Google had no live price for it either.',
                ),
                'tone' => 'warn',
            ];
        }

        $advice = $snapshot->deal->advice;

        return [
            'title' => $advice->title,
            'body'  => $advice->body,
            'tone'  => $advice->tone,
        ];
    }

    /**
     * ⚠ A GAP, NOT A STRICT `>`. €76.50 against €77 is a rounding difference,
     * and "treat the cached fare as gone" is far too strong a sentence for it.
     */
    private static function contradicts(int $cachedCents, int $liveCents): bool
    {
        $percent = (int) config('orbit.live_check.contradiction_percent');

        return $liveCents * 100 >= $cachedCents * (100 + $percent);
    }

    /** The same spelling App\Domain\Pricing\DealScorer's sentences use. */
    private static function money(int $cents): string
    {
        return $cents % 100 === 0
            ? '€'.intdiv($cents, 100)
            : '€'.number_format($cents / 100, 2);
    }
}
