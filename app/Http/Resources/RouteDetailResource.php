<?php

declare(strict_types=1);

namespace App\Http\Resources;

use DateTimeZone;
use Illuminate\Http\Request;
use App\Models\LivePriceCheck;
use App\Domain\Pricing\PricePoint;
use Illuminate\Support\Facades\Date;
use App\Application\Routes\BookingLink;
use App\Application\Routes\RouteSnapshot;

/**
 * Everything the route detail screen draws (design/README.md §2).
 *
 * The live check is passed in because the ADVICE depends on it: a callout that
 * says "lock it in" over a fare Google cannot find is the client composing a
 * claim this server never made. docs/BUSINESS-LOGIC.md §17.
 */
final class RouteDetailResource extends RouteSummaryResource
{
    public function __construct(RouteSnapshot $snapshot, private readonly ?LivePriceCheck $live = null)
    {
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

        $mayBeGone = $snapshot->cheapestMayBeGone(
            Date::now()->toDateTimeImmutable(),
            (int) config('orbit.live_check.stale_after_hours'),
            (int) config('orbit.live_check.under_usual_percent'),
        );

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
                'foundAt' => $cheapest?->foundAt?->setTimezone(
                    new DateTimeZone((string) config('orbit.timezone')),
                )->format('c'),

                /*
                 * ⚠ THE SERVER'S JUDGEMENT AND NOT THE CLIENT'S: old enough AND
                 * far enough under usual that the headline should not be shouted.
                 */
                'mayBeGone' => $mayBeGone,
            ],

            'booking' => [
                'aviasales'  => BookingLink::aviasales($snapshot->route, $cheapest?->departureDate),
                'skyscanner' => BookingLink::skyscanner($snapshot->route, $cheapest?->departureDate),
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

        if ($cheapest !== null && $lowest !== null && $lowest > $cheapest->cents) {
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
                    '%s is %d%% under this route’s usual price, and old enough that fares like it have usually sold. Check the live price before counting on it.',
                    self::money($cheapest->cents),
                    abs((int) $snapshot->stats?->percentUnderUsual($cheapest->cents)),
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

    /** The same spelling App\Domain\Pricing\DealScorer's sentences use. */
    private static function money(int $cents): string
    {
        return $cents % 100 === 0
            ? '€'.intdiv($cents, 100)
            : '€'.number_format($cents / 100, 2);
    }
}
